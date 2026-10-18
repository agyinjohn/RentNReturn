<?php
/** @var array $user @var ?string $codeSentTo */
ob_start();
$currentUser = $user ?? null;
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Verify your mobile</h1>
    <?php if (!empty($notice)): ?>
      <div class="form-success"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($codeSentTo): ?>
      <form method="post" action="/verify/phone/confirm">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label for="code">6-digit code sent to <?= htmlspecialchars($codeSentTo) ?></label>
          <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6">
          <p class="form-hint">The code expires in 10 minutes.</p>
        </div>
        <button type="submit" class="btn btn-primary">Verify</button>
      </form>
      <p class="footer"><a href="/verify/phone?change=1">Use a different number or send a new code</a></p>
    <?php else: ?>
      <p class="form-hint">We'll text you a 6-digit code. Your number is private: it is never shown on your listings or profile.</p>
      <form method="post" action="/verify/phone">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label for="phone">UK mobile number</label>
          <input type="tel" id="phone" name="phone" required autocomplete="tel" placeholder="07700 900123">
        </div>
        <button type="submit" class="btn btn-primary">Send code</button>
      </form>
    <?php endif; ?>
    <p class="footer"><a href="/profile">Back to profile</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Verify your mobile';
include dirname(__DIR__) . '/layout.php';
