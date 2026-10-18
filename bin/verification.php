<?php

declare(strict_types=1);

/*
 * Operator tool for verification status (until the admin area in Step 7):
 *   php bin/verification.php status   <email>
 *   php bin/verification.php withdraw <email> "<reason>"         remove identity verification (and so the badge)
 *   php bin/verification.php record   <provider> <reference> <verified|failed|expired|withdrawn>
 *                                                                   apply an outcome confirmed in the provider's dashboard
 * Every change is kept in user_verifications with who/why.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = App\Database::get($config);
$identity = new App\Verification\IdentityVerification($pdo, App\Verification\IdentityVerification::providerFromConfig($config));
[, $command, $a, $b, $c] = $argv + [null, null, null, null, null];

$findUser = static function (?string $email) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([(string) $email]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "No user with email {$email}\n");
        exit(1);
    }
    return $user;
};

switch ($command) {
    case 'status':
        $user = $findUser($a);
        printf("User #%d  public name: %s\n", $user['id'], App\Verification\TrustBadge::publicName($user));
        printf("  email     %s\n", $user['email_verified_at'] ?: 'not verified');
        printf("  mobile    %s\n", $user['phone_verified_at'] ? $user['phone_verified_at'] . ' (' . App\Verification\PhoneNumber::mask($user['phone']) . ')' : 'not verified');
        printf("  identity  %s\n", $user['identity_verified_at'] ?: 'not verified');
        printf("  badge     %s\n", App\Verification\TrustBadge::earned($user) ? 'YES' : 'no');
        $stmt = $pdo->prepare('SELECT created_at, type, provider, status, provider_reference, detail FROM user_verifications WHERE user_id = ? ORDER BY id');
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $row) {
            printf("  %s  %-8s %-10s %-9s %s %s\n", $row['created_at'], $row['type'], $row['provider'], $row['status'], $row['provider_reference'] ?? '', $row['detail'] ?? '');
        }
        break;

    case 'withdraw':
        $user = $findUser($a);
        if (trim((string) $b) === '') {
            fwrite(STDERR, "A reason is required.\n");
            exit(1);
        }
        $actor = 'operator:' . (getenv('USER') ?: 'cli');
        $identity->withdraw((int) $user['id'], $actor, (string) $b);
        echo "Identity verification withdrawn for user #{$user['id']}.\n";
        break;

    case 'record':
        $userId = $identity->recordResult((string) $a, (string) $b, (string) $c, 'Recorded by operator:' . (getenv('USER') ?: 'cli'));
        if ($userId === null) {
            fwrite(STDERR, "No identity check with provider {$a} and reference {$b}.\n");
            exit(1);
        }
        echo "Recorded {$c} for user #{$userId}.\n";
        break;

    default:
        fwrite(STDERR, "Usage: php bin/verification.php status <email> | withdraw <email> \"<reason>\" | record <provider> <reference> <status>\n");
        exit(1);
}
