<?php
ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap">
  <section class="hero">
    <h1>Rent what you need. Share what you have.</h1>
    <p>A simple peer-to-peer marketplace for borrowing and lending items—tools, gear, party supplies, and more.</p>
    <a href="/listings" class="btn btn-primary">Browse listings</a>
    <?php if ($currentUser): ?>
      <a href="/listings/create" class="btn btn-secondary" style="margin-left: 10px;">List an item</a>
    <?php endif; ?>
  </section>
  <form method="get" action="/listings" class="search-bar">
    <input type="text" name="q" placeholder="Search by keyword..." value="<?= htmlspecialchars($keyword ?? '') ?>">
    <select name="category">
      <option value="">All categories</option>
      <?php foreach ($categories ?? [] as $label => $value): ?>
        <option value="<?= htmlspecialchars($value) ?>" <?= (isset($category) && $category === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
  <?php if (!empty($featured)): ?>
    <h2 class="page-title">Featured listings</h2>
    <div class="listings-grid">
      <?php foreach ($featured as $item): ?>
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
            <?php if ($item['available_now']): ?>
              <span class="listing-detail availability" style="margin-top:8px;">Available now</span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="text-align: center; color: var(--color-text-muted);">No listings yet. Be the first to list an item!</p>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Home';
include __DIR__ . '/layout.php';
