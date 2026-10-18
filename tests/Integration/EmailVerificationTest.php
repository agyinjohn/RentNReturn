<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 1 gate: email verification and blocking sensitive actions until verified. */
final class EmailVerificationTest extends IntegrationTestCase
{
    public function testSignupSendsVerificationEmailAndDoesNotRevealExistingAccounts(): void
    {
        $email = $this->uniqueEmail('new');
        $b = $this->browser();
        $b->get('/signup');
        $r = $b->post('/signup', ['name' => 'Sam', 'email' => $email, 'password' => 'a-good-password']);
        $this->assertRedirectsTo('/login', $r);
        $b->get('/login');
        $newNotice = $this->notice($b->lastBody);
        $this->assertStringContainsString('We have sent an email to ' . $email, $newNotice);

        $emails = TestEnvironment::emailsTo($email);
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('Verify your email', $emails[0]['subject']);
        $this->assertNull($this->db()->query("SELECT email_verified_at FROM users WHERE email = '{$email}'")->fetchColumn());

        // Signing up again with the same email looks identical to the visitor...
        $b2 = $this->browser();
        $b2->get('/signup');
        $r = $b2->post('/signup', ['name' => 'Attacker', 'email' => $email, 'password' => 'another-password']);
        $this->assertRedirectsTo('/login', $r);
        $b2->get('/login');
        $this->assertSame($newNotice, $this->notice($b2->lastBody));
        // ...but the real owner is emailed instead, and the account is untouched.
        $emails = TestEnvironment::emailsTo($email);
        $this->assertCount(2, $emails);
        $this->assertStringContainsString('already have', $emails[1]['subject']);
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM users WHERE email = '{$email}'")->fetchColumn());
        $this->assertRedirectsTo('/', $this->browser()->login($email, 'a-good-password'));
    }

    public function testUnverifiedUserIsBlockedFromSensitiveActionsUntilVerified(): void
    {
        $email = $this->uniqueEmail('unverified');
        $b = $this->browser();
        $b->get('/signup');
        $b->post('/signup', ['name' => 'Una', 'email' => $email, 'password' => 'a-good-password']);
        $this->assertRedirectsTo('/', $b->login($email, 'a-good-password'));

        // Can sign in and browse, but sees the banner and cannot list or rent.
        $b->get('/listings');
        $this->assertStringContainsString('Please verify your email address', $b->lastBody);
        $this->assertRedirectsTo('/verify-email', $b->get('/listings/create'));
        $this->assertRedirectsTo('/verify-email', $b->post('/listings/1/request', ['start_date' => '2030-01-01', 'end_date' => '2030-01-02']));
        $this->assertRedirectsTo('/verify-email', $b->post('/requests/1/accept'));
        $this->assertRedirectsTo('/verify-email', $b->post('/listings/1/publish'));
        $api = $b->post('/api/listing/ai-describe');
        $this->assertSame(403, $api->status);
        $this->assertStringContainsString('verify', $api->body);
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM rental_requests')->fetchColumn());

        // Clicking the emailed link verifies the account.
        $link = $this->linkFromEmail($email, '/verify-email/confirm');
        $this->assertRedirectsTo('/', $b->get($link));
        $this->assertNotNull($this->db()->query("SELECT email_verified_at FROM users WHERE email = '{$email}'")->fetchColumn());
        $this->assertSame(200, $b->get('/listings/create')->status);

        // The link is single-use.
        $this->assertRedirectsTo('/login', $this->browser()->get($link));
    }

    public function testVerificationLinkWorksWhenNotSignedInAndExpires(): void
    {
        $email = $this->uniqueEmail('later');
        $b = $this->browser();
        $b->get('/signup');
        $b->post('/signup', ['name' => 'Lee', 'email' => $email, 'password' => 'a-good-password']);
        $link = $this->linkFromEmail($email, '/verify-email/confirm');

        $this->db()->exec("UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE");
        $expired = $this->browser();
        $this->assertRedirectsTo('/login', $expired->get($link));
        $expired->get('/login');
        $this->assertStringContainsString('invalid or has expired', $expired->lastBody);
        $this->assertNull($this->db()->query("SELECT email_verified_at FROM users WHERE email = '{$email}'")->fetchColumn());

        // Resend issues a new link (and kills the old one); it works signed-out.
        $this->assertRedirectsTo('/', $b->login($email, 'a-good-password'));
        TestEnvironment::clearEmails();
        $this->assertRedirectsTo('/verify-email', $b->post('/verify-email/resend'));
        $newLink = $this->linkFromEmail($email, '/verify-email/confirm');
        $this->assertNotSame($link, $newLink);
        $this->assertRedirectsTo('/login', $this->browser()->get($newLink));
        $this->assertNotNull($this->db()->query("SELECT email_verified_at FROM users WHERE email = '{$email}'")->fetchColumn());
    }

    public function testResendIsRateLimited(): void
    {
        $email = $this->uniqueEmail('resend');
        $this->createUser($email, 'correct-horse', false);
        $b = $this->browser();
        $b->login($email, 'correct-horse');
        for ($i = 0; $i < 4; $i++) {
            $b->post('/verify-email/resend');
        }
        $this->assertCount(3, TestEnvironment::emailsTo($email));
        $b->get('/verify-email');
        $this->assertStringContainsString('requested several emails recently', $b->lastBody);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $this->assertRedirectsTo('/login', $this->browser()->get('/verify-email/confirm?token=' . str_repeat('a', 64)));
        $this->assertRedirectsTo('/login', $this->browser()->get('/verify-email/confirm?token=not-a-token'));
    }

    private function notice(string $html): string
    {
        preg_match('/class="form-success">([^<]+)</', $html, $m);
        return html_entity_decode($m[1] ?? '');
    }
}
