<?php
$currentUser = $currentUser ?? null;
$pageTitle = $pageTitle ?? 'RentNReturn';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> – RentNReturn</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <a href="/" class="logo">
        <img src="/assets/images/logo.png" alt="RentNReturn" style="height: 38px;">
      </a>
      <nav class="nav">
        <a href="/listings">Browse</a>
        <?php if ($currentUser): ?>
          <a href="/listings/create">List an item</a>
          <a href="/listings/my">My listings</a>
          <a href="/dashboard">Dashboard</a>
          <a href="/payouts">Payouts</a>
          <a href="/requests">My requests</a>
          <a href="/profile">Profile</a>
          <form method="post" action="/logout" style="display:inline;">
            <?= \App\Security::csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Logout</button>
          </form>
        <?php else: ?>
          <a href="/login">Log in</a>
          <a href="/signup" class="btn">Sign up</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <?php if ($currentUser && empty($currentUser['email_verified_at']) && empty($hideVerifyBanner)): ?>
    <div class="notice-banner">
      <div class="wrap">
        <span>Please verify your email address to list or rent items.</span>
        <a href="/verify-email">Resend verification email</a>
      </div>
    </div>
  <?php endif; ?>
  <main class="main">
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="wrap" style="padding-top: 20px;"><div class="form-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?= $content ?? '' ?>
  </main>
  <footer style="text-align: center; padding: 32px 20px; color: var(--color-text-muted); font-size: 0.9rem;">
    <div class="wrap">RentNReturn — share what you have.</div>
  </footer>
</body>
</html>
