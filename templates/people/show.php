<?php
use App\Booking\Money;
use App\Verification\TrustBadge;

/** @var array $person @var array $listings */
ob_start();
$completed = (int) $person['completed_as_owner'] + (int) $person['completed_as_renter'];
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title"><?= htmlspecialchars($person['public_name']) ?> <?= TrustBadge::html((bool) $person['verified']) ?></h1>
  <p class="meta">Member since <?= htmlspecialchars(date('F Y', strtotime($person['created_at']))) ?> · <?= $completed ?> completed rental<?= $completed === 1 ? '' : 's' ?></p>
  <ul class="verify-summary">
    <li><?= $person['email_verified'] ? '✓' : '–' ?> Email</li>
    <li><?= $person['phone_verified'] ? '✓' : '–' ?> Mobile</li>
    <li><?= $person['identity_verified'] ? '✓' : '–' ?> Identity</li>
    <li class="meta">Ratings — coming soon</li>
  </ul>

  <h2>Listings</h2>
  <?php if (empty($listings)): ?>
    <p class="meta">No listings yet.</p>
  <?php else: ?>
    <div class="listings-grid">
      <?php foreach ($listings as $item): ?>
        <article class="card">
          <a href="/listings/<?= htmlspecialchars($item['slug']) ?>">
            <?php if (!empty($item['cover_image'])): ?>
              <img class="card-image" src="/<?= htmlspecialchars($item['cover_image']) ?>" alt="">
            <?php else: ?>
              <div class="card-image" style="display:flex;align-items:center;justify-content:center;color:var(--color-text-muted);">No image</div>
            <?php endif; ?>
          </a>
          <div class="card-body">
            <h3 class="card-title"><a href="/listings/<?= htmlspecialchars($item['slug']) ?>"><?= htmlspecialchars($item['title']) ?></a></h3>
            <div class="card-price"><?= Money::format((int) $item['price_daily_pence']) ?>/day</div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = $person['public_name'];
include dirname(__DIR__) . '/layout.php';
