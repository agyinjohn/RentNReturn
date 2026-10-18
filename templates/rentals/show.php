<?php
use App\Booking\BookingStatus;
use App\Booking\Money;

/** @var array $rental @var array $timeline @var array $actions */
ob_start();
$currentUser = $user ?? null;
$isRenter = $rental['role'] === 'renter';
$eventText = static function (array $e): string {
    $who = match ($e['actor_role']) {
        'system' => 'RentNReturn',
        default => ($e['actor_name'] ?? 'Someone') . ' (' . $e['actor_role'] . ')',
    };
    return match ($e['to_status']) {
        'requested' => 'Requested by ' . $who,
        'declined' => 'Declined by ' . $who,
        'cancelled' => 'Cancelled by ' . $who,
        'expired' => 'Expired',
        BookingStatus::AWAITING_PAYMENT => 'Approved by ' . $who . ' — awaiting payment',
        BookingStatus::CONFIRMED => 'Payment received — booking confirmed',
        default => BookingStatus::label($e['to_status']) . ' — ' . $who,
    };
};
?>
<div class="wrap" style="padding: 32px 20px; max-width: 760px;">
  <p><a href="<?= $isRenter ? '/requests' : '/dashboard' ?>">← <?= $isRenter ? 'My requests' : 'Dashboard' ?></a></p>
  <h1 class="page-title"><?= htmlspecialchars($rental['listing_title']) ?></h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p><span class="status-badge status-<?= BookingStatus::tone($rental['status']) ?>"><?= htmlspecialchars(BookingStatus::label($rental['status'])) ?></span></p>
      <dl class="rental-facts">
        <dt>Dates</dt>
        <dd><?= htmlspecialchars($rental['start_date']) ?> to <?= htmlspecialchars($rental['end_date']) ?> (<?= (int) $rental['days'] ?> day<?= (int) $rental['days'] === 1 ? '' : 's' ?>)</dd>
        <dt>Price</dt>
        <dd><?= Money::format((int) $rental['price_daily_pence']) ?>/day × <?= (int) $rental['days'] ?> = <strong><?= Money::format((int) $rental['total_pence']) ?></strong></dd>
        <dt><?= $isRenter ? 'Owner' : 'Renter' ?></dt>
        <dd><?= htmlspecialchars($isRenter ? $rental['owner_name'] : $rental['renter_name']) ?></dd>
        <?php if ($rental['status'] === BookingStatus::AWAITING_PAYMENT && $rental['payment_due_at']): ?>
          <dt>Payment due by</dt>
          <dd><?= htmlspecialchars((new DateTimeImmutable($rental['payment_due_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y, H:i')) ?> (UK time)</dd>
        <?php endif; ?>
        <dt>Listing</dt>
        <dd><a href="/listings/<?= htmlspecialchars($rental['listing_slug']) ?>">View listing</a></dd>
      </dl>
      <?php if (!empty($rental['message'])): ?>
        <p class="meta">Message from renter: <?= nl2br(htmlspecialchars($rental['message'])) ?></p>
      <?php endif; ?>

      <?php if ($rental['status'] === BookingStatus::AWAITING_PAYMENT): ?>
        <div class="form-notice">
          <?= $isRenter
              ? 'The owner has approved your request. Online payment is coming soon — the booking is confirmed once payment is received. If it is not paid by the deadline, the dates are released.'
              : 'You approved this request. The dates are held for the renter until the payment deadline, then released if unpaid.' ?>
        </div>
      <?php endif; ?>

      <div class="actions">
        <?php if ($rental['status'] === 'pending' && $isRenter): ?>
          <form method="post" action="/rentals/<?= (int) $rental['id'] ?>/cancel-request" style="display:inline;">
            <?= \App\Security::csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Cancel request</button>
          </form>
        <?php elseif ($rental['status'] === 'pending'): ?>
          <form method="post" action="/requests/<?= (int) $rental['id'] ?>/accept" style="display:inline;">
            <?= \App\Security::csrfField() ?>
            <button type="submit" class="btn btn-primary btn-sm">Accept</button>
          </form>
          <form method="post" action="/requests/<?= (int) $rental['id'] ?>/decline" style="display:inline;">
            <?= \App\Security::csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Decline</button>
          </form>
        <?php endif; ?>
        <?php foreach ($actions as $to => $label): ?>
          <form method="post" action="/rentals/<?= (int) $rental['id'] ?>/status" style="display:inline;">
            <?= \App\Security::csrfField() ?>
            <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
            <button type="submit" class="btn <?= $to === BookingStatus::CANCELLED ? 'btn-ghost' : 'btn-primary' ?> btn-sm"><?= htmlspecialchars($label) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <h2 style="margin-top: 32px;">History</h2>
  <ol class="timeline">
    <?php foreach ($timeline as $e): ?>
      <li>
        <span class="timeline-when"><?= htmlspecialchars((new DateTimeImmutable($e['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y, H:i')) ?></span>
        <span><?= htmlspecialchars($eventText($e)) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Rental: ' . $rental['listing_title'];
include dirname(__DIR__) . '/layout.php';
