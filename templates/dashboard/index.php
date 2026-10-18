<?php
ob_start();
$currentUser = $user ?? null;
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
    <?php
    $pending = array_filter($requests ?? [], fn($r) => $r['status'] === 'pending');
    if (empty($pending)): ?>
      <p style="color: var(--color-text-muted);">No pending requests.</p>
    <?php else: ?>
      <ul class="req-list">
        <?php foreach ($pending as $r): ?>
          <li>
            <div>
              <strong><a href="/listings/<?= htmlspecialchars($r['listing_slug']) ?>"><?= htmlspecialchars($r['listing_title']) ?></a></strong><br>
              <span class="meta">From <?= htmlspecialchars($r['renter_name']) ?> — <?= htmlspecialchars($r['start_date']) ?> to <?= htmlspecialchars($r['end_date']) ?></span>
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
    <h2>Bookings / approved rentals</h2>
    <?php if (empty($bookings)): ?>
      <p style="color: var(--color-text-muted);">No bookings yet.</p>
    <?php else: ?>
      <ul class="booking-list">
        <?php foreach ($bookings as $b): ?>
          <li>
            <div>
              <strong><a href="/listings/<?= htmlspecialchars($b['listing_slug']) ?>"><?= htmlspecialchars($b['listing_title']) ?></a></strong><br>
              <span class="meta">Renter: <?= htmlspecialchars($b['renter_name']) ?> — <?= htmlspecialchars($b['start_date']) ?> to <?= htmlspecialchars($b['end_date']) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard';
include dirname(__DIR__) . '/layout.php';
