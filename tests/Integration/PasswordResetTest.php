<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 1 gate: secure password reset with short-lived, single-use tokens. */
final class PasswordResetTest extends IntegrationTestCase
{
    private function requestReset(string $email): string
    {
        $b = $this->browser();
        $b->get('/forgot-password');
        $this->assertRedirectsTo('/forgot-password', $b->post('/forgot-password', ['email' => $email]));
        $b->get('/forgot-password');
        preg_match('/class="form-success">([^<]+)</', $b->lastBody, $m);
        return html_entity_decode($m[1] ?? '');
    }

    public function testResetFlowChangesPasswordAndTokenIsSingleUse(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email, 'old-password');
        $this->requestReset($email);
        $link = $this->linkFromEmail($email, '/reset-password');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        $b = $this->browser();
        $form = $b->get($link);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Choose a new password', $form->body);
        $this->assertStringNotContainsString('invalid, expired', $form->body);

        // Mismatched confirmation is rejected and does not use up the token.
        $b->post('/reset-password', ['token' => $q['token'], 'password' => 'new-password-1', 'password_confirmation' => 'different']);
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'old-password'));

        $this->assertRedirectsTo('/login', $b->post('/reset-password', ['token' => $q['token'], 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']));
        $this->assertRedirectsTo('/login', $this->browser()->login($email, 'old-password'));
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'new-password-1'));

        // Same token again: refused, password unchanged.
        $again = $this->browser();
        $this->assertStringContainsString('invalid, expired', $again->get($link)->body);
        $this->assertRedirectsTo('/forgot-password', $again->post('/reset-password', ['token' => $q['token'], 'password' => 'hijacked-pass', 'password_confirmation' => 'hijacked-pass']));
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'new-password-1'));

        $this->assertStringContainsString('password was changed', TestEnvironment::emailsTo($email)[1]['subject']);
    }

    public function testUnknownEmailGetsIdenticalResponseAndNoEmail(): void
    {
        $known = $this->uniqueEmail('known');
        $this->createUser($known);
        $unknown = $this->uniqueEmail('unknown');
        $this->assertSame($this->requestReset($known), $this->requestReset($unknown));
        $this->assertCount(1, TestEnvironment::emailsTo($known));
        $this->assertCount(0, TestEnvironment::emailsTo($unknown));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email, 'old-password');
        $this->requestReset($email);
        $link = $this->linkFromEmail($email, '/reset-password');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $this->db()->exec("UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE purpose = 'password_reset'");

        $b = $this->browser();
        $this->assertStringContainsString('invalid, expired', $b->get($link)->body);
        $b->post('/reset-password', ['token' => $q['token'], 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'old-password'));
    }

    public function testTokenLifetimeIsOneHourAndOnlyHashIsStored(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $this->requestReset($email);
        $link = $this->linkFromEmail($email, '/reset-password');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        $row = $this->db()->query("SELECT token_hash, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS ttl FROM user_tokens WHERE purpose = 'password_reset' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame(hash('sha256', $q['token']), $row['token_hash']);
        $this->assertNotSame($q['token'], $row['token_hash']);
        $this->assertGreaterThan(3500, (int) $row['ttl']);
        $this->assertLessThanOrEqual(3600, (int) $row['ttl']);
    }

    public function testNewRequestInvalidatesEarlierLink(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $this->requestReset($email);
        $first = $this->linkFromEmail($email, '/reset-password');
        $this->requestReset($email);
        $second = $this->linkFromEmail($email, '/reset-password');
        $this->assertStringContainsString('invalid, expired', $this->browser()->get($first)->body);
        $this->assertStringNotContainsString('invalid, expired', $this->browser()->get($second)->body);
    }

    public function testResetSignsOutOtherSessionsAndClearsLoginLock(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email, 'old-password');
        $signedIn = $this->browser();
        $signedIn->login($email, 'old-password');
        $this->assertSame(200, $signedIn->get('/dashboard')->status);

        for ($i = 0; $i < 5; $i++) {
            $this->browser()->login($email, 'wrong');
        }
        $this->requestReset($email);
        $link = $this->linkFromEmail($email, '/reset-password');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $this->browser()->post('/reset-password', ['token' => $q['token'], 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);

        $this->assertRedirectsTo('/login', $signedIn->get('/dashboard'), 'Existing session must be signed out after a reset');
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'new-password-1'), 'Reset must clear the login lock');
    }

    public function testResetRequestsAreRateLimitedPerEmail(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        for ($i = 0; $i < 5; $i++) {
            $this->requestReset($email);
        }
        $this->assertCount(3, TestEnvironment::emailsTo($email));
    }
}
