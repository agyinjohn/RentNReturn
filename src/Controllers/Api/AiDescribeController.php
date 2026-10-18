<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth;
use App\Controller;
use App\Logger;
use App\Security;
use App\Services\AiImageDescriptionServiceFactory;

class AiDescribeController extends Controller
{
    public function describe(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        $user = Auth::user($this->config);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        // Temporary files go outside public/ and are always deleted below.
        $uploadDir = $this->config['upload']['tmp_path'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0750, true);
        }
        $paths = [];
        if (!empty($_FILES['images']['name'][0])) {
            $allowed = $this->config['upload']['allowed_types'];
            $maxSize = $this->config['upload']['max_size_mb'] * 1024 * 1024;
            for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                $tmp = $_FILES['images']['tmp_name'][$i];
                $type = Security::detectImageType($tmp, $this->config['upload']);
                if (!is_uploaded_file($tmp) || $_FILES['images']['size'][$i] > $maxSize || $type === null || !in_array($type, $allowed, true)) {
                    continue;
                }
                $ext = Security::imageExtension($type);
                if ($ext === null) continue;
                $basename = 'ai_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $path = $uploadDir . DIRECTORY_SEPARATOR . $basename;
                if (move_uploaded_file($tmp, $path)) {
                    $paths[] = $path;
                }
            }
        }
        if (count($paths) < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Please upload at least one image.']);
            return;
        }
        if (!$this->allowAiRequest((int) $user['id'])) {
            foreach ($paths as $path) {
                @unlink($path);
            }
            http_response_code(429);
            echo json_encode(['error' => 'Too many AI description requests. Please wait a while and try again.']);
            return;
        }
        try {
            $ai = AiImageDescriptionServiceFactory::create($this->config['ai']);
            $result = $ai->describeImages($paths);
            $attrs = $result['attributes'];
            $attrs['suggested_title'] = $result['title'];
            echo json_encode([
                'suggested_title' => $result['title'],
                'description' => $result['description'],
                'attributes' => $attrs,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('AI description failed', ['error' => $e->getMessage()]);
            http_response_code(502);
            echo json_encode(['error' => 'AI description failed. Please try again or enter manually.']);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
