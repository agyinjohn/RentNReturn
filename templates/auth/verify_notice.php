<?php
ob_start();
$currentUser = $user;
$hideVerifyBanner = true; // this page already explains verification
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Verify your email</h1>
    <?php if (!empty($notice)): ?>
      <div class="form-notice"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="form-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <p>We sent a verification link to <strong><?= htmlspecialchars($user['email']) ?></strong>. Click it to start listing and renting items.</p>
    <p class="form-hint">Can't find it? Check your spam folder, or send a new link.</p>
    <form method="post" action="/verify-email/resend">
      <?= \App\Security::csrfField() ?>
      <button type="submit" class="btn btn-primary">Send a new verification link</button>
    </form>
    <p class="footer"><a href="/">Back to RentNReturn</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Verify your email';
include dirname(__DIR__) . '/layout.php';
