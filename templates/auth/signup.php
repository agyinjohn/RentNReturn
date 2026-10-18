<?php
ob_start();
$currentUser = null;
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Sign up</h1>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/signup">
        <?= \App\Security::csrfField() ?>
      <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required autocomplete="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
        <p class="form-hint">At least 8 characters.</p>
      </div>
      <div class="form-group">
        <label for="contact_preference">Contact preference</label>
        <select id="contact_preference" name="contact_preference">
          <option value="email" <?= ($_POST['contact_preference'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
          <option value="phone" <?= ($_POST['contact_preference'] ?? '') === 'phone' ? 'selected' : '' ?>>Phone</option>
          <option value="both" <?= ($_POST['contact_preference'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Sign up</button>
    </form>
    <p class="footer">Already have an account? <a href="/login">Log in</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Sign up';
include dirname(__DIR__) . '/layout.php';
