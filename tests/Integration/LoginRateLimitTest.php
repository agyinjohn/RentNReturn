<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

/** Step 1 gate: login rate limiting that cannot be reset by clearing cookies and does not reveal accounts. */
final class LoginRateLimitTest extends IntegrationTestCase
{
    private function loginError(\Tests\Support\Browser $b): string
    {
        $b->get('/login');
        preg_match('/class="form-error">([^<]+)</', $b->lastBody, $m);
        return html_entity_decode($m[1] ?? '');
    }

    public function testAccountIsLockedAfterFiveFailuresEvenWithCorrectPassword(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        for ($i = 0; $i < 5; $i++) {
            $b->login($email, 'wrong-password');
            $this->assertSame('Invalid email or password.', $this->loginError($b));
        }
        $b->login($email, 'correct-horse');
        $this->assertStringContainsString('Too many login attempts', $this->loginError($b));
        $this->assertRedirectsTo('/login', $b->get('/dashboard'));
    }

    public function testLockSurvivesClearingCookies(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        for ($i = 0; $i < 5; $i++) {
            $this->browser()->login($email, 'wrong-password'); // a brand-new cookie jar every attempt
        }
        $fresh = $this->browser();
        $fresh->login($email, 'correct-horse');
        $this->assertStringContainsString('Too many login attempts', $this->loginError($fresh));
    }

    public function testOtherAccountsAreNotAffected(): void
    {
        $locked = $this->uniqueEmail('locked');
        $other = $this->uniqueEmail('other');
        $this->createUser($locked);
        $this->createUser($other);
        $b = $this->browser();
        for ($i = 0; $i < 5; $i++) {
            $b->login($locked, 'wrong-password');
        }
        $this->assertRedirectsTo('/', $b->login($other, 'correct-horse'));
    }

    public function testUnknownEmailGetsTheSameMessageAsWrongPassword(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        $b->login($email, 'wrong-password');
        $known = $this->loginError($b);
        $b->login($this->uniqueEmail('nobody'), 'wrong-password');
        $this->assertSame($known, $this->loginError($b));
    }

    public function testSuccessfulLoginResetsTheFailureCount(): void
    {
        $email = $this->uniqueEmail();
        $this->createUser($email);
        $b = $this->browser();
        for ($i = 0; $i < 4; $i++) {
            $b->login($email, 'wrong-password');
        }
        $this->assertRedirectsTo('/', $b->login($email, 'correct-horse'));
        $b->post('/logout');
        $b = $this->browser();
        for ($i = 0; $i < 4; $i++) {
            $b->login($email, 'wrong-password');
        }
        $this->assertRedirectsTo('/', $b->login($email, 'correct-horse'));
    }
}
