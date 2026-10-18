<?php
/** @var string $reference @var string|null $errorDetail */
ob_start();
$currentUser = null;
?>
<div class="not-found">
  <h1>Something went wrong</h1>
  <p>Sorry — we couldn't complete that request. Please try again in a moment.</p>
  <p class="form-hint">If the problem continues, contact support and quote reference <strong><?= htmlspecialchars($reference) ?></strong>.</p>
  <?php if (!empty($errorDetail)): ?>
    <pre class="error-detail"><?= htmlspecialchars($errorDetail) ?></pre>
  <?php endif; ?>
  <p><a href="/">Go home</a></p>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Error';
include __DIR__ . '/layout.php';
