<?php

declare(strict_types=1);

namespace App\Verification;

use App\Logger;
use App\RateLimiter;
use App\Sms\SmsSender;
use PDO;

/** Mobile number verification by 6-digit SMS code. */
final class PhoneVerification
{
    public function __construct(
        private PDO $pdo,
        private SmsSender $sms,
        private RateLimiter $limiter,
        private array $rules,      // config['verification']
        private array $rateLimits  // config['rate_limits']
    ) {
    }

    /** Text a fresh code to $rawPhone for $userId. Returns the masked number. */
    public function sendCode(int $userId, string $rawPhone): string
    {
        $phone = PhoneNumber::normalizeUkMobile($rawPhone);
        if ($phone === null) {
            throw new VerificationException('Please enter a UK mobile number, for example 07700 900123.');
        }
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE phone = ?');
        $stmt->execute([$phone]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false && (int) $owner === $userId) {
            throw new VerificationException('This number is already verified on your account.');
        }
        if ($owner !== false) {
            throw new VerificationException('This number cannot be used. If it is yours, please contact support.');
        }

        // Limits protect against SMS cost abuse: per account and per destination number.
        $buckets = ['sms_user_hour' => 'sms:user:h:' . $userId, 'sms_user_day' => 'sms:user:d:' . $userId, 'sms_phone_hour' => 'sms:phone:h:' . $phone];
        foreach ($buckets as $name => $bucket) {
            if ($this->limiter->tooManyAttempts($bucket, $this->rateLimits[$name][0])) {
                throw new VerificationException('Too many codes requested. Please wait a while and try again.');
            }
        }
        foreach ($buckets as $name => $bucket) {
            $this->limiter->hit($bucket, $this->rateLimits[$name][1]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        // Only the newest code is valid.
        $this->pdo->prepare('UPDATE phone_verifications SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL')->execute([$now, $userId]);
        $this->pdo->prepare('INSERT INTO phone_verifications (user_id, phone, code_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $phone, password_hash($code, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + $this->rules['otp_ttl'])]);
        $id = (int) $this->pdo->lastInsertId();

        $minutes = intdiv($this->rules['otp_ttl'], 60);
        if (!$this->sms->send($phone, "Your RentNReturn code is {$code}. It expires in {$minutes} minutes. Never share this code.")) {
            $this->pdo->prepare('DELETE FROM phone_verifications WHERE id = ?')->execute([$id]);
            throw new VerificationException('We could not send a text message just now. Please try again shortly.');
        }
        return PhoneNumber::mask($phone);
    }

    /** Check the code the user typed. On success the number becomes the account's verified mobile. */
    public function confirmCode(int $userId, string $code): string
    {
        $code = preg_replace('/\s+/', '', $code);
        $stmt = $this->pdo->prepare('SELECT * FROM phone_verifications WHERE user_id = ? AND consumed_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, gmdate('Y-m-d H:i:s')]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new VerificationException('That code has expired. Please request a new one.');
        }
        // Count the attempt first and atomically, so parallel guesses cannot exceed the limit.
        $max = $this->rules['otp_max_attempts'];
        $bump = $this->pdo->prepare('UPDATE phone_verifications SET attempts = attempts + 1 WHERE id = ? AND attempts < ? AND consumed_at IS NULL');
        $bump->execute([$row['id'], $max]);
        if ($bump->rowCount() !== 1) {
            throw new VerificationException('Too many wrong codes. Please request a new one.');
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $row['code_hash'])) {
            $left = $max - ((int) $row['attempts'] + 1);
            if ($left <= 0) {
                $this->pdo->prepare('UPDATE phone_verifications SET consumed_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
                throw new VerificationException('Too many wrong codes. Please request a new one.');
            }
            throw new VerificationException("That code is not right. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.');
        }

        $this->pdo->beginTransaction();
        try {
            $use = $this->pdo->prepare('UPDATE phone_verifications SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
            $use->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
            if ($use->rowCount() !== 1) {
                throw new VerificationException('That code has already been used. Please request a new one.');
            }
            $this->pdo->prepare('UPDATE users SET phone = ?, phone_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['phone'], $userId]);
            $this->pdo->prepare("INSERT INTO user_verifications (user_id, type, provider, status, detail, verified_at) VALUES (?, 'phone', ?, 'verified', ?, UTC_TIMESTAMP())")
                ->execute([$userId, $this->sms->driverName(), PhoneNumber::mask($row['phone'])]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000') { // unique phone: verified by another account in the meantime
                throw new VerificationException('This number cannot be used. If it is yours, please contact support.');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Logger::info('Phone verified', ['user_id' => $userId]);
        return PhoneNumber::mask($row['phone']);
    }
}
