<?php
ob_start();
$currentUser = $user ?? null;
$keyword = $keyword ?? '';
$category = $category ?? '';
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">Browse listings</h1>
  <form method="get" action="/listings" class="search-bar">
    <input type="text" name="q" placeholder="Search by keyword..." value="<?= htmlspecialchars($keyword) ?>">
    <select name="category">
      <option value="">All categories</option>
      <?php foreach ($categories as $label => $value): ?>
        <option value="<?= htmlspecialchars($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
  <?php if (!empty($listings)): ?>
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
            <div class="card-meta"><?= htmlspecialchars($item['owner_name']) ?></div>
            <div class="card-price">£<?= number_format((float)$item['price_daily'], 2) ?>/day</div>
            <?php if (!empty($item['available_now'])): ?>
              <span class="listing-detail availability" style="margin-top:8px;">Available now</span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="text-align: center; color: var(--color-text-muted);">No listings match your search. Try different keywords or categories.</p>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Browse';
include dirname(__DIR__) . '/layout.php';
