<?php
/** @var array $user @var string $reference */
ob_start();
$currentUser = $user ?? null;
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Identity check (simulated)</h1>
    <div class="form-notice">
      <strong>Development only.</strong> This page stands in for the identity provider's check, so the badge flow can be
      demonstrated before a provider is connected. It cannot be enabled on staging or production.
    </div>
    <p class="meta">Reference: <?= htmlspecialchars($reference) ?></p>
    <form method="post" action="/verify/identity/simulate" style="display:inline;">
      <?= \App\Security::csrfField() ?>
      <input type="hidden" name="ref" value="<?= htmlspecialchars($reference) ?>">
      <input type="hidden" name="outcome" value="pass">
      <button type="submit" class="btn btn-primary">Simulate: check passed</button>
    </form>
    <form method="post" action="/verify/identity/simulate" style="display:inline;">
      <?= \App\Security::csrfField() ?>
      <input type="hidden" name="ref" value="<?= htmlspecialchars($reference) ?>">
      <input type="hidden" name="outcome" value="fail">
      <button type="submit" class="btn btn-ghost">Simulate: check failed</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Identity check';
include dirname(__DIR__) . '/layout.php';
