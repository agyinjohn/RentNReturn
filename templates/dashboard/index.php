<?php
use App\Booking\BookingStatus;
use App\Booking\Money;

ob_start();
$currentUser = $user ?? null;
$rentals = $rentals ?? [];
$pending = array_filter($rentals, fn($r) => $r['status'] === 'pending');
$current = array_filter($rentals, fn($r) => in_array($r['status'], BookingStatus::BLOCKING, true));
$past = array_filter($rentals, fn($r) => $r['status'] !== 'pending' && !in_array($r['status'], BookingStatus::BLOCKING, true));
$row = static function (array $r): string {
    return '<strong><a href="/rentals/' . (int) $r['id'] . '">' . htmlspecialchars($r['listing_title']) . '</a></strong> '
        . '<span class="status-badge status-' . BookingStatus::tone($r['status']) . '">' . htmlspecialchars(BookingStatus::label($r['status'])) . '</span><br>'
        . '<span class="meta">' . htmlspecialchars($r['renter_name']) . ' ' . \App\Verification\TrustBadge::html((bool) $r['renter_verified']) . ' — ' . htmlspecialchars($r['start_date']) . ' to ' . htmlspecialchars($r['end_date'])
        . ' (' . (int) $r['days'] . ' day' . ((int) $r['days'] === 1 ? '' : 's') . ') — ' . Money::format((int) $r['total_pence']) . '</span>';
};
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">Dashboard</h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <div class="dashboard-section">
    <h2>Rental requests (pending)</h2>
    <?php if (empty($pending)): ?>
      <p style="color: var(--color-text-muted);">No pending requests.</p>
    <?php else: ?>
      <ul class="req-list">
        <?php foreach ($pending as $r): ?>
          <li>
            <div>
              <?= $row($r) ?>
              <?php if (!empty($r['message'])): ?>
                <br><span class="meta">Message: <?= htmlspecialchars($r['message']) ?></span>
              <?php endif; ?>
            </div>
            <div class="actions">
              <form method="post" action="/requests/<?= (int)$r['id'] ?>/accept" style="display:inline;">
                <?= \App\Security::csrfField() ?>
                <button type="submit" class="btn btn-primary btn-sm">Accept</button>
              </form>
              <form method="post" action="/requests/<?= (int)$r['id'] ?>/decline" style="display:inline;">
                <?= \App\Security::csrfField() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Decline</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="dashboard-section">
    <h2>Current bookings</h2>
    <?php if (empty($current)): ?>
      <p style="color: var(--color-text-muted);">No current bookings.</p>
    <?php else: ?>
      <ul class="booking-list">
        <?php foreach ($current as $r): ?>
          <li><div><?= $row($r) ?></div><div class="actions"><a href="/rentals/<?= (int) $r['id'] ?>" class="btn btn-ghost btn-sm">View</a></div></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php if (!empty($past)): ?>
    <div class="dashboard-section">
      <h2>Past and closed</h2>
      <ul class="booking-list">
        <?php foreach ($past as $r): ?>
          <li><div><?= $row($r) ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/layout.php';
