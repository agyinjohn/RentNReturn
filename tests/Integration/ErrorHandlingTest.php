<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 0/1: users see a generic error with a reference; developers get the full detail in the structured log. */
final class ErrorHandlingTest extends IntegrationTestCase
{
    public function testServerErrorShowsGenericPageAndLogsDetail(): void
    {
        // A second app server whose database password is wrong: every DB call throws.
        $broken = TestEnvironment::server(['DB_PASSWORD' => 'definitely-wrong-password']);
        $ch = curl_init($broken . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = (string) curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->assertSame(500, $status);
        $this->assertStringContainsString('Something went wrong', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body, 'Exception details must not reach users when debug is off');
        $this->assertStringNotContainsString('definitely-wrong-password', $body);
        $this->assertMatchesRegularExpression('/reference <strong>([a-f0-9]{16})<\/strong>/', $body);
        preg_match('/reference <strong>([a-f0-9]{16})<\/strong>/', $body, $m);

        $entries = [];
        foreach (glob(TestEnvironment::logDir() . '/app-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (($entry['request_id'] ?? null) === $m[1]) {
                    $entries[] = $entry;
                }
            }
        }
        $this->assertCount(1, $entries, 'The reference shown to the user must match exactly one log entry');
        $this->assertSame('error', $entries[0]['level']);
        $this->assertStringContainsString('SQLSTATE', $entries[0]['message']);
        $this->assertSame('PDOException', $entries[0]['context']['exception']);
        $this->assertSame('/', $entries[0]['path']);
    }

    public function testFailedAcceptNeverShowsSuccess(): void
    {
        $email = $this->uniqueEmail('owner');
        $this->createUser($email);
        $b = $this->browser();
        $b->login($email, 'correct-horse');
        $this->assertRedirectsTo('/dashboard', $b->post('/requests/999999/accept'));
        $page = $b->get('/dashboard')->body;
        $this->assertStringContainsString('Request not found or already responded.', $page);
        $this->assertStringNotContainsString('Request approved', $page);
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
    }
}
