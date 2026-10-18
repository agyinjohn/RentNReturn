<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Verification\IdentityVerification;
use App\Verification\SimulatedIdentityProvider;
use Tests\Support\Browser;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 3 gate: the badge derives only from trusted server-side verification and cannot be self-set. */
final class TrustBadgeTest extends IntegrationTestCase
{
    private const BADGE = 'RentNReturn Verified ✓';

    private function simulatedUrl(): string
    {
        return TestEnvironment::server(['IDENTITY_PROVIDER' => 'simulated']);
    }

    private function verifyPhone(Browser $b, int $userId): void
    {
        $mobile = '+4477009' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT);
        $b->post('/verify/phone', ['phone' => $mobile]);
        $texts = TestEnvironment::texts($mobile);
        preg_match('/\b(\d{6})\b/', end($texts)['body'], $m);
        $b->post('/verify/phone/confirm', ['code' => $m[1]]);
        $this->assertNotNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** Go through the (development) identity flow; returns the provider reference. */
    private function passIdentity(Browser $b, string $outcome = 'pass'): string
    {
        $r = $b->post('/verify/identity');
        $this->assertSame('/verify/identity/simulate', parse_url((string) $r->location(), PHP_URL_PATH));
        parse_str((string) parse_url((string) $r->location(), PHP_URL_QUERY), $q);
        $this->assertSame(200, $b->get($r->location())->status);
        $b->post('/verify/identity/simulate', ['ref' => $q['ref'], 'outcome' => $outcome]);
        return $q['ref'];
    }

    public function testBadgeAppearsOnlyWhenEmailMobileAndIdentityAreAllVerified(): void
    {
        $url = $this->simulatedUrl();
        $email = $this->uniqueEmail('owner');
        $ownerId = $this->createUser($email, 'correct-horse', true, 'Darren Fitzgerald-Smith');
        $listingId = $this->createListing($ownerId, 1500, null, null, 'Badge Test Saw');
        $slug = $this->db()->query("SELECT slug FROM listings WHERE id = {$listingId}")->fetchColumn();
        $b = new Browser($url);
        $b->login($email, 'correct-horse');
        $visitor = new Browser($url);

        $this->assertStringNotContainsString(self::BADGE, $visitor->get("/listings/{$slug}")->body, 'Email only: no badge');
        $this->verifyPhone($b, $ownerId);
        $this->assertStringNotContainsString(self::BADGE, $visitor->get("/listings/{$slug}")->body, 'Email + mobile: no badge');

        $this->passIdentity($b, 'fail');
        $this->assertNull($this->db()->query("SELECT identity_verified_at FROM users WHERE id = {$ownerId}")->fetchColumn());
        $this->assertStringContainsString('did not complete (failed)', $b->get('/profile')->body);
        $this->assertStringNotContainsString(self::BADGE, $visitor->get("/listings/{$slug}")->body);

        $this->passIdentity($b, 'pass');
        foreach (["/listings/{$slug}", '/listings', "/people/{$ownerId}"] as $page) {
            $html = $visitor->get($page)->body;
            $this->assertStringContainsString(self::BADGE, $html, $page);
            // Public pages show the first name only — never legal surname, email or phone.
            $this->assertStringContainsString('Darren', $html, $page);
            $this->assertStringNotContainsString('Fitzgerald', $html, $page);
            $this->assertStringNotContainsString($email, $html, $page);
            $this->assertDoesNotMatchRegularExpression('/\+447|07700/', $html, $page);
        }
        $this->assertStringContainsString('Your public profile shows the badge', $b->get('/profile')->body);
        $history = $this->db()->query("SELECT type, provider, status FROM user_verifications WHERE user_id = {$ownerId} ORDER BY id")->fetchAll();
        $this->assertSame([
            ['type' => 'phone', 'provider' => 'log', 'status' => 'verified'],
            ['type' => 'identity', 'provider' => 'simulated', 'status' => 'failed'],
            ['type' => 'identity', 'provider' => 'simulated', 'status' => 'verified'],
        ], $history);
    }

    public function testBadgeCannotBeSetByEditingTheRequest(): void
    {
        [$b, $userId] = $this->signedInUser('forger');
        $b->post('/profile', [
            'name' => 'Forger Person', 'display_name' => 'Forger', 'contact_preference' => 'email',
            'email_verified_at' => '2026-01-01 00:00:00', 'phone_verified_at' => '2026-01-01 00:00:00',
            'identity_verified_at' => '2026-01-01 00:00:00', 'phone' => '+447700900999',
            'verified' => '1', 'badge' => '1', 'trust_badge' => '1', 'is_verified' => 'true',
        ]);
        $user = $this->db()->query("SELECT display_name, phone, phone_verified_at, identity_verified_at FROM users WHERE id = {$userId}")->fetch();
        $this->assertSame(['display_name' => 'Forger', 'phone' => null, 'phone_verified_at' => null, 'identity_verified_at' => null], $user);
        $this->assertStringNotContainsString(self::BADGE, $this->browser()->get("/people/{$userId}")->body);

        // The identity simulator is off unless explicitly enabled in development...
        $this->assertSame(404, $b->get('/verify/identity/simulate?ref=sim_anything')->status);
        $b->post('/verify/identity/simulate', ['ref' => 'sim_anything', 'outcome' => 'pass']);
        $this->assertNull($this->db()->query("SELECT identity_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
        $this->assertStringContainsString('Identity checks are coming soon', $b->get('/profile')->body);
    }

    public function testNobodyCanCompleteSomeoneElsesCheck(): void
    {
        $url = $this->simulatedUrl();
        [$victim, $victimId] = $this->signedInUser('victim', $url);
        $r = $victim->post('/verify/identity');
        parse_str((string) parse_url((string) $r->location(), PHP_URL_QUERY), $q);

        [$attacker] = $this->signedInUser('attacker', $url);
        $this->assertSame(404, $attacker->get('/verify/identity/simulate?ref=' . $q['ref'])->status);
        $attacker->post('/verify/identity/simulate', ['ref' => $q['ref'], 'outcome' => 'fail']);
        $attacker->post('/verify/identity/simulate', ['ref' => 'sim_made_up', 'outcome' => 'pass']);
        $this->assertSame('pending', $this->db()->query("SELECT status FROM user_verifications WHERE provider_reference = '{$q['ref']}'")->fetchColumn());
        $this->assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM user_verifications WHERE status = 'verified' AND user_id <> {$victimId} AND type = 'identity' AND provider_reference = 'sim_made_up'")->fetchColumn());
    }

    public function testProviderExpiryOrWithdrawalRemovesTheBadgeAndAllowsReverification(): void
    {
        $url = $this->simulatedUrl();
        [$b, $userId] = $this->signedInUser('owner', $url);
        $this->verifyPhone($b, $userId);
        $ref = $this->passIdentity($b);
        $this->assertStringContainsString(self::BADGE, $this->browser()->get("/people/{$userId}")->body);

        // Provider reports the verification expired → badge removed.
        $identity = new IdentityVerification($this->db(), new SimulatedIdentityProvider());
        $this->assertSame($userId, $identity->recordResult('simulated', $ref, 'expired', 'Document expired'));
        $this->assertStringNotContainsString(self::BADGE, $this->browser()->get("/people/{$userId}")->body);
        $this->assertStringContainsString('did not complete (expired)', $b->get('/profile')->body);

        // User verifies again → badge back. Then an operator withdraws it for account risk.
        $this->passIdentity($b);
        $this->assertStringContainsString(self::BADGE, $this->browser()->get("/people/{$userId}")->body);
        $identity->withdraw($userId, 'operator:test', 'Account under review');
        $this->assertStringNotContainsString(self::BADGE, $this->browser()->get("/people/{$userId}")->body);
        $last = $this->db()->query("SELECT provider, status, detail FROM user_verifications WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame(['provider' => 'operator:test', 'status' => 'withdrawn', 'detail' => 'Account under review'], $last);
        $this->assertNull($identity->recordResult('simulated', 'sim_unknown', 'verified'), 'Unknown references are ignored');
    }

    public function testLateFailureOfAnOldCheckDoesNotRemoveANewerVerification(): void
    {
        $url = $this->simulatedUrl();
        [$b, $userId] = $this->signedInUser('owner', $url);
        $b->post('/verify/identity'); // abandoned check
        $oldRef = $this->db()->query("SELECT provider_reference FROM user_verifications WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->passIdentity($b);

        (new IdentityVerification($this->db(), new SimulatedIdentityProvider()))->recordResult('simulated', $oldRef, 'expired');
        $this->assertNotNull($this->db()->query("SELECT identity_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
    }

    public function testDisplayNameIsUsedPubliclyAndValidated(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $this->createListing($ownerId, 1000, null, null, 'Display Name Ladder');
        $owner->post('/profile', ['name' => 'Owner Legalname', 'display_name' => 'Ladder Larry', 'contact_preference' => 'email']);
        $page = $this->browser()->get('/listings')->body;
        $this->assertStringContainsString('Ladder Larry', $page);
        $this->assertStringNotContainsString('Legalname', $page);

        $owner->post('/profile', ['name' => 'Owner Legalname', 'display_name' => 'Text me 07700900123', 'contact_preference' => 'email']);
        $this->assertStringContainsString('phone numbers or web addresses', $owner->get('/profile')->body);
        $this->assertSame('Ladder Larry', $this->db()->query("SELECT display_name FROM users WHERE id = {$ownerId}")->fetchColumn());
    }
}
