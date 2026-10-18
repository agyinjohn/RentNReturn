<?php
ob_start();
$currentUser = $user ?? null;
?>
<div class="wrap" style="padding: 40px 20px;">
  <h1 class="page-title">Profile</h1>
  <?php if (isset($success) && $success): ?>
    <div class="form-success">Profile updated.</div>
  <?php endif; ?>
  <div class="card" style="max-width: 420px;">
    <div class="card-body">
      <form method="post" action="/profile">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" required value="<?= htmlspecialchars($user['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="contact_preference">Contact preference</label>
          <select id="contact_preference" name="contact_preference">
            <option value="email" <?= ($user['contact_preference'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="phone" <?= ($user['contact_preference'] ?? '') === 'phone' ? 'selected' : '' ?>>Phone</option>
            <option value="both" <?= ($user['contact_preference'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Profile';
include dirname(__DIR__) . '/layout.php';
