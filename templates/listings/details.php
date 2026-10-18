<?php
ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap" style="padding: 32px 20px;">
  <h1 class="page-title">Add listing details</h1>
  <p style="color: var(--color-text-muted); margin-bottom: 24px;">Add category, condition, price, and optional extra photos. Your title and description are set.</p>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <div class="card" style="max-width: 560px;">
    <div class="card-body">
      <?php if ($draftImageUrl): ?>
        <div class="form-group">
          <label>Your photo</label>
          <p><img src="<?= htmlspecialchars($draftImageUrl) ?>" alt="Your item" style="max-width: 200px; height: auto; border-radius: 8px;"></p>
        </div>
      <?php endif; ?>
      <form method="post" action="/listings/create" enctype="multipart/form-data">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label>Additional photos (optional)</label>
          <div class="upload-zone">
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
            <p class="form-hint">You can add up to 4 more photos (5 total).</p>
          </div>
        </div>
        <div class="form-group">
          <label for="category">Category</label>
          <select id="category" name="category" required>
            <option value="">Select category</option>
            <?php foreach ($categories as $label => $value): ?>
              <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="condition">Condition</label>
          <select id="condition" name="condition" required>
            <?php foreach ($conditions as $label => $value): ?>
              <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="price_daily">Price per day (£)</label>
          <input type="number" id="price_daily" name="price_daily" required min="0.01" step="0.01">
        </div>
        <div class="form-group">
          <label>Availability</label>
          <label style="display: flex; align-items: center; gap: 8px; font-weight: normal;">
            <input type="checkbox" name="available_now" value="1" checked>
            Available now
          </label>
          <p class="form-hint">Or set a date range below (optional).</p>
        </div>
        <div class="form-group">
          <label for="available_from">Available from (optional)</label>
          <input type="date" id="available_from" name="available_from">
        </div>
        <div class="form-group">
          <label for="available_until">Available until (optional)</label>
          <input type="date" id="available_until" name="available_until">
        </div>
        <button type="submit" class="btn btn-primary">Save listing</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Add listing details';
include dirname(__DIR__) . '/layout.php';
