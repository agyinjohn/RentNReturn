<?php
ob_start();
$currentUser = \App\Auth::user($this->config);
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Reset your password</h1>
    <?php if (!empty($notice)): ?>
      <div class="form-success"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <p class="form-hint">Enter the email address for your account and we'll send you a link to choose a new password.</p>
    <form method="post" action="/forgot-password">
      <?= \App\Security::csrfField() ?>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email">
      </div>
      <button type="submit" class="btn btn-primary">Send reset link</button>
    </form>
    <p class="footer"><a href="/login">Back to log in</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Reset password';
include dirname(__DIR__) . '/layout.php';
