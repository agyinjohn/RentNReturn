<?php
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
            <strong><a href="/listings/<?= htmlspecialchars($r['listing_slug']) ?>"><?= htmlspecialchars($r['listing_title']) ?></a></strong><br>
            <span class="meta">Owner: <?= htmlspecialchars($r['owner_name']) ?> — <?= htmlspecialchars($r['start_date']) ?> to <?= htmlspecialchars($r['end_date']) ?> — <?= htmlspecialchars($r['status']) ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'My requests';
include dirname(__DIR__) . '/layout.php';
