<?php
use App\Booking\Money;

/** @var array $user @var ?array $account @var array $payouts @var bool $paymentsEnabled @var int $feeBps @var string $feePayer */
ob_start();
$currentUser = $user ?? null;
$fee = rtrim(rtrim(number_format($feeBps / 100, 2), '0'), '.');
$labels = [
    'pending' => 'Held until the rental is completed',
    'awaiting_owner_account' => 'Ready — finish payout setup to receive it',
    'processing' => 'Being sent',
    'transferred' => 'Sent to your Stripe account',
    'failed' => 'Transfer failed — we will retry',
    'cancelled' => 'Cancelled (refunded to renter)',
];
?>
<div class="wrap" style="padding: 32px 20px; max-width: 860px;">
  <h1 class="page-title">Payouts</h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom: 24px;">
    <div class="card-body">
      <h2 style="margin-top: 0;">Getting paid</h2>
      <?php if (!$paymentsEnabled): ?>
        <p>Online payments are coming soon.</p>
      <?php elseif ($account === null): ?>
        <p>RentNReturn pays owners through <strong>Stripe</strong>. Set up payouts once (about 5 minutes: your details and bank account) and your earnings are sent automatically when each rental is completed.</p>
        <form method="post" action="/payouts/onboard">
          <?= \App\Security::csrfField() ?>
          <button type="submit" class="btn btn-primary">Set up payouts with Stripe</button>
        </form>
      <?php elseif (!(int) $account['payouts_enabled']): ?>
        <p><?= (int) $account['details_submitted'] ? 'Stripe is checking your details, or needs a little more information.' : 'Your payout setup is not finished yet.' ?></p>
        <?php if (!empty($account['requirements_due'])): ?>
          <p class="meta">Stripe still needs: <?= htmlspecialchars(str_replace('_', ' ', $account['requirements_due'])) ?></p>
        <?php endif; ?>
        <form method="post" action="/payouts/onboard">
          <?= \App\Security::csrfField() ?>
          <button type="submit" class="btn btn-primary">Continue payout setup</button>
        </form>
      <?php else: ?>
        <p><span class="verify-yes">✓ Payouts are set up.</span> Earnings are sent to your Stripe account when a rental is completed, then paid to your bank on Stripe's schedule.</p>
        <form method="post" action="/payouts/dashboard">
          <?= \App\Security::csrfField() ?>
          <button type="submit" class="btn btn-secondary btn-sm">View payouts and bank details on Stripe</button>
        </form>
      <?php endif; ?>
      <p class="form-hint">RentNReturn's fee is <?= htmlspecialchars($fee) ?>% of each rental<?= $feePayer === 'renter' ? ', paid by the renter on top of your price' : ', deducted from your earnings' ?>.</p>
    </div>
  </div>

  <h2>Your earnings</h2>
  <?php if (empty($payouts)): ?>
    <p class="meta">No paid rentals yet.</p>
  <?php else: ?>
    <table class="payout-table">
      <thead><tr><th>Rental</th><th class="hide-sm">Dates</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($payouts as $p): ?>
          <tr>
            <td><a href="/rentals/<?= (int) $p['rental_request_id'] ?>"><?= htmlspecialchars($p['title']) ?></a></td>
            <td class="hide-sm"><?= htmlspecialchars($p['start_date']) ?> to <?= htmlspecialchars($p['end_date']) ?></td>
            <td><?= Money::format((int) $p['amount_pence']) ?></td>
            <td><?= htmlspecialchars($labels[$p['status']] ?? $p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Payouts';
include dirname(__DIR__) . '/layout.php';
