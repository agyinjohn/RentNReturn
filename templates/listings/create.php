<?php
ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">List an item</h1>
  <p style="color: var(--color-text-muted); margin-bottom: 24px;">Upload one photo of your item. We’ll generate a title and description for you to review, then you add the rest of the details.</p>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <div class="card" style="max-width: 560px;">
    <div class="card-body">
      <form method="post" action="/listings/create/describe" enctype="multipart/form-data">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label>Photo of your item</label>
          <div class="upload-zone">
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
            <p class="form-hint">Upload one photo. We’ll use it to suggest a title and description.</p>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Generate description</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'List an item';
include dirname(__DIR__) . '/layout.php';
