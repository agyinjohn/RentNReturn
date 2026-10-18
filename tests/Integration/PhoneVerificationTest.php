<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\Browser;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 3: verified mobile number by SMS code. */
final class PhoneVerificationTest extends IntegrationTestCase
{
    private static int $n = 0;

    /** A unique valid UK mobile per test. */
    private function mobile(): string
    {
        return '077009' . str_pad((string) (getmypid() % 1000), 3, '0', STR_PAD_LEFT) . str_pad((string) (++self::$n % 100), 2, '0', STR_PAD_LEFT);
    }

    private function e164(string $mobile): string
    {
        return '+44' . substr($mobile, 1);
    }

    private function lastCode(string $e164): string
    {
        $texts = TestEnvironment::texts($e164);
        $this->assertNotEmpty($texts, "Expected a text to {$e164}");
        preg_match('/\b(\d{6})\b/', end($texts)['body'], $m);
        return $m[1];
    }

    private function message(Browser $b): string
    {
        preg_match('/class="form-(?:error|success)">([^<]+)</', $b->get('/verify/phone')->body, $m);
        return html_entity_decode($m[1] ?? '');
    }

    public function testCodeVerifiesTheNumberAndIsStoredOnlyAsAHash(): void
    {
        [$b, $userId] = $this->signedInUser('phone');
        $mobile = $this->mobile();
        $this->assertRedirectsTo('/verify/phone', $b->post('/verify/phone', ['phone' => substr($mobile, 0, 5) . ' ' . substr($mobile, 5)]));
        $this->assertStringContainsString("We've texted a 6-digit code to 07••• ••• " . substr($mobile, -3), $this->message($b));
        $code = $this->lastCode($this->e164($mobile));
        $this->assertStringContainsString('Never share this code', TestEnvironment::texts($this->e164($mobile))[0]['body']);

        $stored = $this->db()->query("SELECT code_hash FROM phone_verifications WHERE user_id = {$userId}")->fetchColumn();
        $this->assertStringNotContainsString($code, $stored);
        $this->assertTrue(password_verify($code, $stored));

        $this->assertRedirectsTo('/profile', $b->post('/verify/phone/confirm', ['code' => $code]));
        $user = $this->db()->query("SELECT phone, phone_verified_at FROM users WHERE id = {$userId}")->fetch();
        $this->assertSame($this->e164($mobile), $user['phone']);
        $this->assertNotNull($user['phone_verified_at']);
        $this->assertSame(['type' => 'phone', 'status' => 'verified'], $this->db()->query("SELECT type, status FROM user_verifications WHERE user_id = {$userId}")->fetch());
        $this->assertStringContainsString('Mobile number 07••• ••• ' . substr($mobile, -3) . ' verified', $b->get('/profile')->body);

        // Same code again: refused (single use).
        $b->post('/verify/phone/confirm', ['code' => $code]);
        $this->assertStringContainsString('expired', $this->message($b));
    }

    public function testWrongCodesAreLimitedAndExpiredCodesRefused(): void
    {
        [$b, $userId] = $this->signedInUser('phone');
        $mobile = $this->mobile();
        $b->post('/verify/phone', ['phone' => $mobile]);
        $code = $this->lastCode($this->e164($mobile));
        $wrong = $code === '000000' ? '111111' : '000000';

        $b->post('/verify/phone/confirm', ['code' => $wrong]);
        $this->assertStringContainsString('4 attempts left', $this->message($b));
        for ($i = 0; $i < 4; $i++) {
            $b->post('/verify/phone/confirm', ['code' => $wrong]);
        }
        $this->assertStringContainsString('Too many wrong codes', $this->message($b));
        // Even the right code no longer works for this attempt.
        $b->post('/verify/phone/confirm', ['code' => $code]);
        $this->assertNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn());

        // A new code works, but not after it expires.
        $this->db()->exec('DELETE FROM rate_limits');
        $b->post('/verify/phone', ['phone' => $mobile]);
        $fresh = $this->lastCode($this->e164($mobile));
        $this->db()->exec("UPDATE phone_verifications SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE user_id = {$userId}");
        $b->post('/verify/phone/confirm', ['code' => $fresh]);
        $this->assertStringContainsString('expired', $this->message($b));
        $this->assertNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** Guesses fired in parallel from several sessions of the same account must still stop at 5. */
    public function testParallelGuessesCannotExceedTheAttemptLimit(): void
    {
        $url = TestEnvironment::server(['PHP_CLI_SERVER_WORKERS' => '8']);
        $email = $this->uniqueEmail('phone');
        $userId = $this->createUser($email);
        $sessions = [];
        for ($i = 0; $i < 10; $i++) {
            $sessions[$i] = new Browser($url);
            $sessions[$i]->login($email, 'correct-horse');
        }
        $mobile = $this->mobile();
        $sessions[0]->post('/verify/phone', ['phone' => $mobile]);
        $code = $this->lastCode($this->e164($mobile));
        $wrong = $code === '000000' ? '111111' : '000000';

        $handles = array_map(static fn (Browser $s) => $s->preparePost('/verify/phone/confirm', ['code' => $wrong]), $sessions);
        $multi = curl_multi_init();
        foreach ($handles as $h) {
            curl_multi_add_handle($multi, $h);
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running);
        curl_multi_close($multi);

        $this->assertLessThanOrEqual(5, (int) $this->db()->query("SELECT MAX(attempts) FROM phone_verifications WHERE user_id = {$userId}")->fetchColumn());
        $sessions[0]->post('/verify/phone/confirm', ['code' => $code]);
        $this->assertNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn(), 'Locked after 5 guesses, even the right code fails');
    }

    public function testRequestingANewCodeInvalidatesTheOldOne(): void
    {
        [$b, $userId] = $this->signedInUser('phone');
        $mobile = $this->mobile();
        $b->post('/verify/phone', ['phone' => $mobile]);
        $first = $this->lastCode($this->e164($mobile));
        $b->post('/verify/phone', ['phone' => $mobile]);
        $second = $this->lastCode($this->e164($mobile));
        if ($first !== $second) {
            $b->post('/verify/phone/confirm', ['code' => $first]);
            $this->assertNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
        }
        $b->post('/verify/phone/confirm', ['code' => $second]);
        $this->assertNotNull($this->db()->query("SELECT phone_verified_at FROM users WHERE id = {$userId}")->fetchColumn());
    }

    public function testOnlyUkMobilesAndOneAccountPerNumber(): void
    {
        [$b] = $this->signedInUser('phone');
        foreach (['+1 415 555 0100', '01224 123456', '+882 1234 5678', 'not a number'] as $bad) {
            $b->post('/verify/phone', ['phone' => $bad]);
            $this->assertStringContainsString('UK mobile number', $this->message($b), $bad);
        }
        $this->assertSame([], TestEnvironment::texts());

        $mobile = $this->mobile();
        $b->post('/verify/phone', ['phone' => $mobile]);
        $b->post('/verify/phone/confirm', ['code' => $this->lastCode($this->e164($mobile))]);

        [$other] = $this->signedInUser('other');
        $other->post('/verify/phone', ['phone' => $mobile]);
        $this->assertStringContainsString('cannot be used', $this->message($other));
        $this->assertCount(1, TestEnvironment::texts($this->e164($mobile)), 'No text is sent for a number owned by another account');

        $b->post('/verify/phone', ['phone' => $mobile]);
        $this->assertStringContainsString('already verified on your account', $this->message($b));
    }

    public function testSendingIsRateLimitedPerAccountAndPerNumber(): void
    {
        [$b] = $this->signedInUser('phone');
        $mobile = $this->mobile();
        for ($i = 0; $i < 4; $i++) {
            $b->post('/verify/phone', ['phone' => $mobile]);
        }
        $this->assertStringContainsString('Too many codes requested', $this->message($b));
        $this->assertCount(3, TestEnvironment::texts($this->e164($mobile)));

        // A different account cannot keep texting the same number either.
        [$c] = $this->signedInUser('other');
        $c->post('/verify/phone', ['phone' => $mobile]);
        $this->assertStringContainsString('Too many codes requested', $this->message($c));
        $this->assertCount(3, TestEnvironment::texts($this->e164($mobile)));
    }

    public function testTwilioDriverSendsThroughTheApi(): void
    {
        $fake = TestEnvironment::server(['__script' => 'fake_twilio']);
        $fakePort = (int) parse_url($fake, PHP_URL_PORT);
        $fakeLog = sys_get_temp_dir() . "/rnr-fake-twilio-{$fakePort}.log";
        @unlink($fakeLog);
        $url = TestEnvironment::server(['SMS_DRIVER' => 'twilio', 'TWILIO_BASE_URL' => $fake, 'TWILIO_ACCOUNT_SID' => 'AC_test', 'TWILIO_AUTH_TOKEN' => 'test-token', 'TWILIO_FROM' => '+447700900000']);

        [$b] = $this->signedInUser('twilio', $url);
        $mobile = $this->mobile();
        $b->post('/verify/phone', ['phone' => $mobile]);
        $this->assertStringContainsString("We've texted", $this->message($b));
        $sent = json_decode((string) file_get_contents($fakeLog), true);
        $this->assertSame(['To' => $this->e164($mobile), 'Body' => $sent['Body'], 'From' => '+447700900000'], $sent);
        $this->assertMatchesRegularExpression('/Your RentNReturn code is \d{6}\./', $sent['Body']);

        // Bad credentials: the user gets a friendly message and no code is left waiting.
        $broken = TestEnvironment::server(['SMS_DRIVER' => 'twilio', 'TWILIO_BASE_URL' => $fake, 'TWILIO_ACCOUNT_SID' => 'AC_test', 'TWILIO_AUTH_TOKEN' => 'wrong', 'TWILIO_FROM' => '+447700900000']);
        [$c, $cId] = $this->signedInUser('twilio', $broken);
        $c->post('/verify/phone', ['phone' => $this->mobile()]);
        $this->assertStringContainsString('could not send a text', $this->message($c));
        $this->assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM phone_verifications WHERE user_id = {$cId}")->fetchColumn());
    }
}
