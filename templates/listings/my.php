<?php
ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">My listings</h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (empty($listings)): ?>
    <p style="color: var(--color-text-muted);">You haven’t listed any items yet. <a href="/listings/create">List an item</a></p>
  <?php else: ?>
    <div class="card">
      <div class="card-body" style="padding: 0;">
        <?php foreach ($listings as $item): ?>
          <div class="listing-row">
            <?php if (!empty($item['cover_image'])): ?>
              <img class="thumb" src="/<?= htmlspecialchars($item['cover_image']) ?>" alt="">
            <?php else: ?>
              <div class="thumb" style="display:flex;align-items:center;justify-content:center;font-size:0.75rem;color:var(--color-text-muted);">No img</div>
            <?php endif; ?>
            <div class="info">
              <strong><a href="/listings/<?= htmlspecialchars($item['slug']) ?>"><?= htmlspecialchars($item['title']) ?></a></strong>
              <span class="badge <?= !empty($item['published']) ? 'published' : '' ?>"><?= !empty($item['published']) ? 'Published' : 'Draft' ?></span>
              — <?= \App\Booking\Money::format((int) $item['price_daily_pence']) ?>/day
            </div>
            <div>
              <?php if (!empty($item['published'])): ?>
                <form method="post" action="/listings/<?= (int)$item['id'] ?>/unpublish" style="display:inline;">
                  <?= \App\Security::csrfField() ?>
                  <button type="submit" class="btn btn-ghost btn-sm">Unpublish</button>
                </form>
              <?php else: ?>
                <form method="post" action="/listings/<?= (int)$item['id'] ?>/publish" style="display:inline;">
                  <?= \App\Security::csrfField() ?>
                  <button type="submit" class="btn btn-primary btn-sm">Publish</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'My listings';
include dirname(__DIR__) . '/layout.php';
