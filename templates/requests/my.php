<?php
use App\Booking\BookingStatus;
use App\Booking\Money;

ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">My requests</h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if (empty($requests)): ?>
    <p style="color: var(--color-text-muted);">You haven’t sent any rental requests yet. <a href="/listings">Browse listings</a></p>
  <?php else: ?>
    <ul class="req-list">
      <?php foreach ($requests as $r): ?>
        <li>
          <div>
            <strong><a href="/rentals/<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['listing_title']) ?></a></strong>
            <span class="status-badge status-<?= BookingStatus::tone($r['status']) ?>"><?= htmlspecialchars(BookingStatus::label($r['status'])) ?></span><br>
            <span class="meta">Owner: <?= htmlspecialchars($r['owner_name']) ?> — <?= htmlspecialchars($r['start_date']) ?> to <?= htmlspecialchars($r['end_date']) ?> — <?= Money::format((int) $r['total_pence']) ?></span>
          </div>
          <div class="actions"><a href="/rentals/<?= (int) $r['id'] ?>" class="btn btn-ghost btn-sm">View</a></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'My requests';
include dirname(__DIR__) . '/layout.php';
