<?php
ob_start();
?>
<div class="not-found">
  <h1>Page not found</h1>
  <p><a href="/">Go home</a> or <a href="/listings">browse listings</a>.</p>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Not found';
$currentUser = $currentUser ?? null;
include __DIR__ . '/layout.php';
