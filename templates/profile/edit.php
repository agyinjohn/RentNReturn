<?php
use App\Verification\TrustBadge;

/** @var array $user @var ?array $identityCheck @var bool $identityAvailable @var bool $badge @var string $publicName @var ?string $maskedPhone */
ob_start();
$currentUser = $user ?? null;
$identityStatus = $identityCheck['status'] ?? null;
$tick = static fn (bool $ok): string => $ok ? '<span class="verify-yes">✓ Verified</span>' : '<span class="verify-no">Not verified</span>';
?>
<div class="wrap" style="padding: 40px 20px; max-width: 760px;">
  <h1 class="page-title">Profile</h1>
  <?php if (!empty($success)): ?>
    <div class="form-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom: 24px;">
    <div class="card-body">
      <h2 style="margin-top: 0;">Verification</h2>
      <p>
        <?php if ($badge): ?>
          <?= TrustBadge::html(true) ?> Your public profile shows the badge.
        <?php else: ?>
          The <strong>RentNReturn Verified ✓</strong> badge appears on your listings and profile once your email, mobile number and identity are all verified.
        <?php endif; ?>
      </p>
      <ul class="verify-list">
        <li>
          <span><strong>Email</strong><br><span class="meta"><?= htmlspecialchars($user['email']) ?> (private)</span></span>
          <span><?= $tick(!empty($user['email_verified_at'])) ?>
            <?php if (empty($user['email_verified_at'])): ?><a href="/verify-email" class="btn btn-secondary btn-sm">Verify</a><?php endif; ?>
          </span>
        </li>
        <li>
          <span><strong>Mobile number</strong><br><span class="meta"><?= $maskedPhone ? htmlspecialchars($maskedPhone) . ' (private)' : 'UK mobile, verified by text message' ?></span></span>
          <span><?= $tick(!empty($user['phone_verified_at'])) ?>
            <a href="/verify/phone" class="btn btn-secondary btn-sm"><?= $maskedPhone ? 'Change' : 'Verify' ?></a>
          </span>
        </li>
        <li>
          <span><strong>Identity</strong><br><span class="meta">
            <?php if (!empty($user['identity_verified_at'])): ?>
              Checked by our verification partner. Your documents are not stored by RentNReturn.
            <?php elseif ($identityStatus === 'pending'): ?>
              Check started — finish it on our partner's page, or start again.
            <?php elseif (in_array($identityStatus, ['failed', 'expired', 'withdrawn'], true)): ?>
              Your last check did not complete (<?= htmlspecialchars($identityStatus) ?>). You can try again.
            <?php elseif (!$identityAvailable): ?>
              Identity checks are coming soon.
            <?php else: ?>
              A quick photo ID and selfie check with our verification partner.
            <?php endif; ?>
          </span></span>
          <span><?= $tick(!empty($user['identity_verified_at'])) ?>
            <?php if (empty($user['identity_verified_at']) && $identityAvailable): ?>
              <form method="post" action="/verify/identity" style="display:inline;">
                <?= \App\Security::csrfField() ?>
                <button type="submit" class="btn btn-secondary btn-sm"><?= $identityStatus ? 'Try again' : 'Verify' ?></button>
              </form>
            <?php endif; ?>
          </span>
        </li>
      </ul>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h2 style="margin-top: 0;">Your details</h2>
      <form method="post" action="/profile">
        <?= \App\Security::csrfField() ?>
        <div class="form-group">
          <label for="display_name">Public display name</label>
          <input type="text" id="display_name" name="display_name" maxlength="40" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" placeholder="<?= htmlspecialchars(TrustBadge::publicName(['name' => $user['name']])) ?>">
          <p class="form-hint">Shown on your listings and <a href="/people/<?= (int) $user['id'] ?>">public profile</a> as “<?= htmlspecialchars($publicName) ?>”. Leave blank to use your first name. No phone numbers or web addresses.</p>
        </div>
        <div class="form-group">
          <label for="name">Full name (private)</label>
          <input type="text" id="name" name="name" required value="<?= htmlspecialchars($user['name'] ?? '') ?>">
          <p class="form-hint">Never shown publicly.</p>
        </div>
        <div class="form-group">
          <label for="contact_preference">Contact preference</label>
          <select id="contact_preference" name="contact_preference">
            <option value="email" <?= ($user['contact_preference'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="phone" <?= ($user['contact_preference'] ?? '') === 'phone' ? 'selected' : '' ?>>Phone</option>
            <option value="both" <?= ($user['contact_preference'] ?? '') === 'both' ? 'selected' : '' ?>>Both</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Profile';
include dirname(__DIR__) . '/layout.php';
