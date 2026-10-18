<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Step 1 gate: CSRF rejection, session rotation, protected logout, security headers. */
final class CsrfAndSessionTest extends IntegrationTestCase
{
    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        $b->get('/login');

        foreach (['/login' => ['email' => $email, 'password' => 'correct-horse'], '/signup' => [], '/forgot-password' => ['email' => $email]] as $path => $fields) {
            $this->assertSame(419, $b->post($path, $fields, false)->status, "POST {$path} without token");
            $this->assertSame(419, $b->post($path, $fields, 'forged-token')->status, "POST {$path} with wrong token");
        }
        // The login without a valid token must not have signed the user in.
        $this->assertRedirectsTo('/login', $b->get('/dashboard'));
    }

    public function testCsrfTokenFromAnotherSessionIsRejected(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $attacker = $this->browser();
        $stolenToken = $attacker->csrfToken();

        $victim = $this->browser();
        $victim->login($email, 'correct-horse');
        $this->assertSame(419, $victim->post('/profile', ['name' => 'Hacked'], $stolenToken)->status);
        $this->assertSame('Test User', $this->db()->query("SELECT name FROM users WHERE email = '{$email}'")->fetchColumn());
    }

    public function testSessionIdIsRegeneratedOnLogin(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        $b->get('/login');
        $before = $b->sessionId();
        $this->assertNotNull($before);

        $this->assertRedirectsTo('/', $b->login($email, 'correct-horse'));
        $after = $b->sessionId();
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, 'Session ID must change at login (session fixation)');
        $this->assertSame(200, $b->get('/dashboard')->status);
    }

    public function testFixatedSessionIdIsNotAccepted(): void
    {
        // An attacker-chosen session ID must be replaced by the server (strict mode), never adopted.
        $b = $this->browser();
        $ch = curl_init(static::$url . '/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => 'rentnreturn_session=attackerchosenid1234567890']);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $this->assertMatchesRegularExpression('/Set-Cookie: rentnreturn_session=(?!attackerchosenid)/i', $raw);
    }

    public function testLogoutRequiresPostWithCsrfAndEndsSession(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        $b->login($email, 'correct-horse');

        $this->assertSame(404, $b->get('/logout')->status, 'GET /logout must not log out');
        $this->assertSame(200, $b->get('/dashboard')->status);

        $this->assertSame(419, $b->post('/logout', [], false)->status, 'POST /logout without token must be rejected');
        $this->assertSame(200, $b->get('/dashboard')->status);

        $this->assertRedirectsTo('/', $b->post('/logout'));
        $this->assertRedirectsTo('/login', $b->get('/dashboard'));
    }

    public function testSessionCookieIsHttpOnlyAndSameSite(): void
    {
        $ch = curl_init(static::$url . '/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        $this->assertMatchesRegularExpression('/Set-Cookie: rentnreturn_session=[^\r\n]*HttpOnly/i', $raw);
        $this->assertMatchesRegularExpression('/Set-Cookie: rentnreturn_session=[^\r\n]*SameSite=Lax/i', $raw);
    }

    public function testSecurityHeadersAreSent(): void
    {
        $r = $this->browser()->get('/');
        $this->assertSame(200, $r->status);
        $this->assertSame('nosniff', $r->header('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $r->header('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $r->header('Referrer-Policy'));
        $this->assertStringContainsString("script-src 'self'", (string) $r->header('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $r->header('Content-Security-Policy'));
    }

    public function testPasswordChangeSignsOutOtherSessions(): void
    {
        $email = $this->uniqueEmail();
        $id = $this->createUser($email);
        $b = $this->browser();
        $b->login($email, 'correct-horse');
        $this->assertSame(200, $b->get('/dashboard')->status);

        $this->db()->exec("UPDATE users SET session_version = session_version + 1 WHERE id = {$id}");
        $this->assertRedirectsTo('/login', $b->get('/dashboard'));
    }
}
