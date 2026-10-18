<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Security;
use App\Services\AiImageDescriptionServiceInterface;
use App\Services\AiImageDescriptionServiceFactory;

class ListingController extends Controller
{
    public function index(array $params = []): void
    {
        $user = Auth::user($this->config);
        $pdo = Database::get($this->config);
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $sql = '
            SELECT l.id, l.slug, l.title, l.category, l.item_condition, l.price_daily, l.available_now, l.final_description,
                   u.name AS owner_name,
                   (SELECT file_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS cover_image
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE l.published = 1
        ';
        $args = [];
        if ($keyword !== '') {
            $sql .= ' AND (l.title LIKE ? OR l.final_description LIKE ?)';
            $arg = '%' . $keyword . '%';
            $args[] = $arg;
            $args[] = $arg;
        }
        if ($category !== '' && isset($this->config['categories']) && in_array($category, array_values($this->config['categories']), true)) {
            $sql .= ' AND l.category = ?';
            $args[] = $category;
        }
        $sql .= ' ORDER BY l.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $listings = $stmt->fetchAll();
        $categories = $this->config['categories'];
        include $this->config['base_path'] . '/templates/listings/index.php';
    }

    public function createForm(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $error = $_SESSION['listing_error'] ?? null;
        unset($_SESSION['listing_error']);
        include $this->config['base_path'] . '/templates/listings/create.php';
    }

    /** Step 1: Upload one image → AI generates description → redirect to review */
    public function describeImage(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $uploadConfig = $this->config['upload'];
        $uploadDir = $uploadConfig['listing_images_path'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $allowedTypes = $uploadConfig['allowed_types'];
        $maxSize = $uploadConfig['max_size_mb'] * 1024 * 1024;

        $errors = [];
        if (empty($_FILES['image']['name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            $errors[] = 'Please upload one photo.';
        } else {
            $tmp = $_FILES['image']['tmp_name'];
            $size = $_FILES['image']['size'];
            $type = Security::detectImageType($tmp);
            if ($size > $maxSize || $type === null || !in_array($type, $allowedTypes, true)) {
                $errors[] = 'Invalid or oversized image.';
            } else {
                $ext = Security::imageExtension($type);
                if ($ext === null) {
                    $errors[] = 'Invalid image type.';
                } else {
                    $basename = 'draft_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $path = $uploadDir . DIRECTORY_SEPARATOR . $basename;
                    if (!move_uploaded_file($tmp, $path)) {
                        $errors[] = 'Could not save image.';
                    } else {
                        try {
                            $ai = $this->getAiService();
                            $result = $ai->describeImages([$path]);
                            $_SESSION['listing_draft'] = [
                                'image_path' => $path,
                                'image_basename' => $basename,
                                'suggested_title' => $result['title'],
                                'suggested_description' => $result['description'],
                                'attributes' => $result['attributes'],
                            ];
                            $this->redirect('/listings/create/review');
                            return;
                        } catch (\Throwable $e) {
                            @unlink($path);
                            $msg = $this->config['debug'] ?? false
                                ? $e->getMessage()
                                : 'AI description failed. Please try again.';
                            $errors[] = $msg;
                        }
                    }
                }
            }
        }
        if ($errors !== []) {
            $_SESSION['listing_error'] = implode(' ', $errors);
        }
        $this->redirect('/listings/create');
    }

    public function create(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $draft = $_SESSION['listing_draft'] ?? null;
        if (!$draft || empty($draft['title']) || empty($draft['final_description'])) {
            $_SESSION['listing_error'] = 'Please start by uploading a photo and approving the description.';
            $this->redirect('/listings/create');
            return;
        }

        $uploadConfig = $this->config['upload'];
        $uploadDir = $uploadConfig['listing_images_path'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $title = trim((string) $draft['title']);
        $finalDescription = trim((string) $draft['final_description']);
        $category = (string) ($_POST['category'] ?? '');
        $condition = (string) ($_POST['condition'] ?? '');
        $priceDaily = isset($_POST['price_daily']) ? (float) $_POST['price_daily'] : 0.0;
        $availableNow = isset($_POST['available_now']) && $_POST['available_now'] === '1';
        $availableFrom = !empty($_POST['available_from']) ? (string) $_POST['available_from'] : null;
        $availableUntil = !empty($_POST['available_until']) ? (string) $_POST['available_until'] : null;
        $allowedTypes = $uploadConfig['allowed_types'];
        $maxSize = $uploadConfig['max_size_mb'] * 1024 * 1024;
        $maxFiles = $uploadConfig['max_files'];

        $errors = [];
        if (!in_array($category, array_values($this->config['categories']), true)) {
            $errors[] = 'Please select a valid category.';
        }
        if (!in_array($condition, array_values($this->config['conditions']), true)) {
            $errors[] = 'Please select a valid condition.';
        }
        if ($priceDaily <= 0) {
            $errors[] = 'Daily price must be greater than 0.';
        }
        if ($availableFrom && !\DateTimeImmutable::createFromFormat('!Y-m-d', $availableFrom)) {
            $errors[] = 'Available-from date is invalid.';
        }
        if ($availableUntil && !\DateTimeImmutable::createFromFormat('!Y-m-d', $availableUntil)) {
            $errors[] = 'Available-until date is invalid.';
        }
        if ($availableFrom && $availableUntil && $availableFrom > $availableUntil) {
            $errors[] = 'Available-until date must be on or after available-from date.';
        }
        $extraFiles = [];
        if (!empty($_FILES['images']['name'][0])) {
            $count = count($_FILES['images']['name']);
            if ($count > $maxFiles - 1) {
                $errors[] = 'You can add at most ' . ($maxFiles - 1) . ' more photos (5 total).';
            } else {
                for ($i = 0; $i < $count; $i++) {
                    $tmp = $_FILES['images']['tmp_name'][$i];
                    $size = $_FILES['images']['size'][$i];
                    $type = Security::detectImageType($tmp);
                    if (!is_uploaded_file($tmp) || $size > $maxSize || $type === null || !in_array($type, $allowedTypes, true)) {
                        $errors[] = 'Invalid or oversized image.';
                        break;
                    }
                    $ext = Security::imageExtension($type);
                    if ($ext === null) {
                        $errors[] = 'Invalid image type.';
                        break;
                    }
                    $extraFiles[] = ['tmp' => $tmp, 'ext' => $ext];
                }
            }
        }
        if ($errors !== []) {
            $_SESSION['listing_error'] = implode(' ', $errors);
            $this->redirect('/listings/create/details');
            return;
        }

        $slug = $this->uniqueSlug($title);
        $pdo = Database::get($this->config);
        $pdo->prepare('
            INSERT INTO listings (user_id, title, slug, category, item_condition, price_daily, final_description, ai_suggested_description, available_now, available_from, available_until, published)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ')->execute([
            $user['id'],
            $title,
            $slug,
            $category,
            $condition,
            $priceDaily,
            $finalDescription,
            $draft['suggested_description'] ?? $finalDescription,
            $availableNow ? 1 : 0,
            $availableFrom ?: null,
            $availableUntil ?: null,
        ]);
        $listingId = (int) $pdo->lastInsertId();

        $draftPath = $draft['image_path'] ?? null;
        if ($draftPath && is_file($draftPath)) {
            $basename = $listingId . '_0_' . bin2hex(random_bytes(4)) . '.' . pathinfo($draftPath, PATHINFO_EXTENSION);
            $newPath = $uploadDir . DIRECTORY_SEPARATOR . $basename;
            rename($draftPath, $newPath);
            $pdo->prepare('INSERT INTO listing_images (listing_id, file_path, sort_order) VALUES (?, ?, ?)')->execute([$listingId, 'uploads/listings/' . $basename, 0]);
        }
        foreach ($extraFiles as $idx => $file) {
            $basename = $listingId . '_' . ($idx + 1) . '_' . bin2hex(random_bytes(4)) . '.' . $file['ext'];
            $path = $uploadDir . DIRECTORY_SEPARATOR . $basename;
            move_uploaded_file($file['tmp'], $path);
            $pdo->prepare('INSERT INTO listing_images (listing_id, file_path, sort_order) VALUES (?, ?, ?)')->execute([$listingId, 'uploads/listings/' . $basename, $idx + 1]);
        }

        unset($_SESSION['listing_draft']);
        $_SESSION['listing_success'] = 'Listing saved. You can publish it from My Listings.';
        $this->redirect('/listings/my');
    }

    public function reviewForm(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $listingId = (int) ($_GET['listing_id'] ?? 0);
        $draft = $_SESSION['listing_draft'] ?? null;

        if ($listingId > 0) {
            $pdo = Database::get($this->config);
            $stmt = $pdo->prepare('SELECT * FROM listings WHERE id = ? AND user_id = ?');
            $stmt->execute([$listingId, $user['id']]);
            $listing = $stmt->fetch();
            if (!$listing) {
                $this->redirect('/listings/my');
                return;
            }
            $attrs = $listing['ai_detected_attributes'] ? json_decode($listing['ai_detected_attributes'], true) : [];
            $suggestedTitle = $attrs['suggested_title'] ?? $listing['title'];
            unset($attrs['suggested_title']);
            $listing['ai_suggested_description'] = $listing['ai_suggested_description'] ?? '';
            $fromDraft = false;
        } elseif ($draft) {
            $listing = [
                'id' => null,
                'title' => $draft['suggested_title'] ?? 'Item',
                'ai_suggested_description' => $draft['suggested_description'] ?? '',
            ];
            $suggestedTitle = $draft['suggested_title'] ?? 'Item';
            $attrs = $draft['attributes'] ?? [];
            $fromDraft = true;
        } else {
            $this->redirect('/listings/create');
            return;
        }
        $conditions = $this->config['conditions'];
        $categories = $this->config['categories'];
        include $this->config['base_path'] . '/templates/listings/review.php';
    }

    public function saveFromReview(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $listingId = (int) ($_POST['listing_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $finalDescription = trim((string) ($_POST['final_description'] ?? ''));
        if ($title === '' || $finalDescription === '') {
            if ($listingId > 0) {
                $this->redirect('/listings/create/review?listing_id=' . $listingId);
            } else {
                $this->redirect('/listings/create/review');
            }
            return;
        }
        if ($listingId > 0) {
            $pdo = Database::get($this->config);
            $stmt = $pdo->prepare('SELECT id, slug FROM listings WHERE id = ? AND user_id = ?');
            $stmt->execute([$listingId, $user['id']]);
            $listing = $stmt->fetch();
            if (!$listing) {
                $this->redirect('/listings/my');
                return;
            }
            $newSlug = $this->uniqueSlug($title, $listingId);
            $pdo->prepare('UPDATE listings SET title = ?, slug = ?, final_description = ? WHERE id = ?')->execute([$title, $newSlug, $finalDescription, $listingId]);
            $_SESSION['listing_success'] = 'Listing saved. You can publish it from My Listings.';
            $this->redirect('/listings/my');
            return;
        }
        $draft = $_SESSION['listing_draft'] ?? null;
        if ($draft) {
            $draft['title'] = $title;
            $draft['final_description'] = $finalDescription;
            $_SESSION['listing_draft'] = $draft;
        }
        $this->redirect('/listings/create/details');
    }

    public function detailsForm(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $draft = $_SESSION['listing_draft'] ?? null;
        if (!$draft || empty($draft['title']) || empty($draft['final_description'])) {
            $_SESSION['listing_error'] = 'Please start by uploading a photo and approving the description.';
            $this->redirect('/listings/create');
            return;
        }
        $error = $_SESSION['listing_error'] ?? null;
        unset($_SESSION['listing_error']);
        $categories = $this->config['categories'];
        $conditions = $this->config['conditions'];
        $draftImageUrl = isset($draft['image_basename']) ? '/uploads/listings/' . htmlspecialchars($draft['image_basename']) : null;
        include $this->config['base_path'] . '/templates/listings/details.php';
    }

    public function myListings(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT l.*, (SELECT file_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS cover_image
            FROM listings l WHERE l.user_id = ?
            ORDER BY l.updated_at DESC
        ');
        $stmt->execute([$user['id']]);
        $listings = $stmt->fetchAll();
        $success = $_SESSION['listing_success'] ?? null;
        unset($_SESSION['listing_success']);
        $conditions = $this->config['conditions'];
        $categories = $this->config['categories'];
        include $this->config['base_path'] . '/templates/listings/my.php';
    }

    public function show(array $params): void
    {
        $slug = $params['slug'] ?? '';
        if ($slug === '') {
            $this->redirect('/listings');
            return;
        }
        $user = Auth::user($this->config);
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT l.*, u.name AS owner_name
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE l.slug = ? AND l.published = 1
        ');
        $stmt->execute([$slug]);
        $listing = $stmt->fetch();
        if (!$listing) {
            http_response_code(404);
            include $this->config['base_path'] . '/templates/404.php';
            return;
        }
        $stmt = $pdo->prepare('SELECT file_path, sort_order FROM listing_images WHERE listing_id = ? ORDER BY sort_order');
        $stmt->execute([$listing['id']]);
        $listing['images'] = $stmt->fetchAll();
        $conditions = $this->config['conditions'];
        $categories = $this->config['categories'];
        include $this->config['base_path'] . '/templates/listings/show.php';
    }

    public function publish(array $params): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $id = (int) ($params['id'] ?? 0);
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('UPDATE listings SET published = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        $_SESSION['listing_success'] = 'Listing published.';
        $this->redirect('/listings/my');
    }

    public function unpublish(array $params): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $id = (int) ($params['id'] ?? 0);
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('UPDATE listings SET published = 0 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        $_SESSION['listing_success'] = 'Listing unpublished.';
        $this->redirect('/listings/my');
    }

    private function uniqueSlug(string $title, int $excludeId = 0): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        if ($slug === '') {
            $slug = 'listing';
        }
        $pdo = Database::get($this->config);
        $base = $slug;
        $n = 0;
        do {
            $candidate = $n === 0 ? $base : $base . '-' . $n;
            $stmt = $pdo->prepare('SELECT id FROM listings WHERE slug = ? AND id != ?');
            $stmt->execute([$candidate, $excludeId]);
            if (!$stmt->fetch()) {
                return $candidate;
            }
            $n++;
        } while (true);
    }

    private function getAiService(): AiImageDescriptionServiceInterface
    {
        return AiImageDescriptionServiceFactory::create($this->config['ai']);
    }
}
