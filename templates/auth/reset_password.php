<?php
ob_start();
$currentUser = null;
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Choose a new password</h1>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!$valid): ?>
      <div class="form-error">That reset link is invalid, expired or has already been used.</div>
      <p><a href="/forgot-password" class="btn btn-primary">Request a new link</a></p>
    <?php else: ?>
      <form method="post" action="/reset-password">
        <?= \App\Security::csrfField() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="form-group">
          <label for="password">New password</label>
          <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8" maxlength="72">
          <p class="form-hint">At least 8 characters.</p>
        </div>
        <div class="form-group">
          <label for="password_confirmation">Confirm new password</label>
          <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" minlength="8" maxlength="72">
        </div>
        <button type="submit" class="btn btn-primary">Change password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Choose a new password';
include dirname(__DIR__) . '/layout.php';
