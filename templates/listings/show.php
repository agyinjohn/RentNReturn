<?php
ob_start();
$currentUser = $user ?? null;
$images = $listing['images'] ?? [];
$imgCount = count($images);
?>
<div class="wrap" style="padding: 32px 20px;">
  <article class="listing-detail">
    <div class="listing-gallery <?= $imgCount > 1 ? 'listing-gallery--multi' : '' ?>">
      <?php if (!empty($images)): ?>
        <?php foreach ($images as $img): ?>
          <img src="/<?= htmlspecialchars($img['file_path']) ?>" alt="">
        <?php endforeach; ?>
      <?php else: ?>
        <div style="aspect-ratio: 16/10; display:flex; align-items:center; justify-content:center; color: var(--color-text-muted);">No images</div>
      <?php endif; ?>
    </div>
    <h1><?= htmlspecialchars($listing['title']) ?></h1>
    <div class="price"><?= \App\Booking\Money::format((int) $listing['price_daily_pence']) ?>/day</div>
    <?php if (!empty($listing['available_now'])): ?>
      <span class="availability">Available now</span>
    <?php elseif (!empty($listing['available_from']) || !empty($listing['available_until'])): ?>
      <span class="availability unavailable">
        <?= $listing['available_from'] ? 'From ' . htmlspecialchars($listing['available_from']) : '' ?>
        <?= $listing['available_until'] ? ' until ' . htmlspecialchars($listing['available_until']) : '' ?>
      </span>
    <?php else: ?>
      <span class="availability unavailable">Check availability</span>
    <?php endif; ?>
    <div class="owner">
      <span class="owner-name"><?= htmlspecialchars($listing['owner_name']) ?></span>
      <span class="rating-placeholder">Rating — coming soon</span>
    </div>
    <div style="margin-bottom: 20px;">
      <?= nl2br(htmlspecialchars($listing['final_description'] ?? '')) ?>
    </div>
    <?php if ($currentUser && (int)$currentUser['id'] !== (int)$listing['user_id']): ?>
      <div class="request-form">
        <h3>Request to rent</h3>
        <?php if (!empty($_SESSION['request_error'])): ?>
          <div class="form-error"><?= htmlspecialchars($_SESSION['request_error']) ?></div>
          <?php unset($_SESSION['request_error']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['request_success'])): ?>
          <div class="form-success"><?= htmlspecialchars($_SESSION['request_success']) ?></div>
          <?php unset($_SESSION['request_success']); ?>
        <?php endif; ?>
        <form method="post" action="/listings/<?= (int)$listing['id'] ?>/request">
          <?= \App\Security::csrfField() ?>
          <div class="form-group">
            <label for="start_date">Start date</label>
            <input type="date" id="start_date" name="start_date" min="<?= htmlspecialchars(max($today, (string) $listing['available_from'])) ?>"<?= $listing['available_until'] ? ' max="' . htmlspecialchars($listing['available_until']) . '"' : '' ?> required>
          </div>
          <div class="form-group">
            <label for="end_date">End date</label>
            <input type="date" id="end_date" name="end_date" min="<?= htmlspecialchars(max($today, (string) $listing['available_from'])) ?>"<?= $listing['available_until'] ? ' max="' . htmlspecialchars($listing['available_until']) . '"' : '' ?> required>
          </div>
          <p class="form-hint">Both dates count as rental days (for example, Monday to Wednesday is 3 days). Up to <?= (int) $rules['max_rental_days'] ?> days. The total is <?= \App\Booking\Money::format((int) $listing['price_daily_pence']) ?> × number of days, confirmed when you send the request.</p>
          <div class="form-group">
            <label for="message">Message (optional)</label>
            <textarea id="message" name="message" placeholder="Introduce yourself or ask a question"></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Request to rent</button>
        </form>
      </div>
    <?php elseif ($currentUser && (int)$currentUser['id'] === (int)$listing['user_id']): ?>
      <p><a href="/listings/my" class="btn btn-secondary">Manage in My listings</a></p>
    <?php else: ?>
      <p><a href="/login" class="btn btn-primary">Log in to request this item</a></p>
    <?php endif; ?>
  </article>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $listing['title'] ?? 'Listing';
include dirname(__DIR__) . '/layout.php';
