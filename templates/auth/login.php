<?php
ob_start();
$currentUser = null;
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Log in</h1>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login">
        <?= \App\Security::csrfField() ?>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary">Log in</button>
    </form>
    <p class="footer">Don’t have an account? <a href="/signup">Sign up</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Log in';
include dirname(__DIR__) . '/layout.php';
