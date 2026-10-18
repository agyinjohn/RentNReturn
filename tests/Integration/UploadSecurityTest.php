<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\Browser;
use Tests\Support\IntegrationTestCase;

/** Step 1 gate: unsafe uploads rejected, temporary AI files cleaned up, AI requests rate limited. */
final class UploadSecurityTest extends IntegrationTestCase
{
    private static string $fixtures;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$fixtures = sys_get_temp_dir() . '/rnr-upload-fixtures-' . getmypid();
        @mkdir(self::$fixtures, 0777, true);

        $img = imagecreatetruecolor(400, 300);
        imagefilledrectangle($img, 0, 0, 400, 300, imagecolorallocate($img, 60, 90, 70));
        imagejpeg($img, self::$fixtures . '/valid.jpg');
        imagepng(imagecreatetruecolor(20, 20), self::$fixtures . '/tiny.png');
        // Small file, absurd dimensions — the shape of a decompression bomb.
        imagepng(imagecreatetruecolor(12000, 10), self::$fixtures . '/too-wide.png');
        file_put_contents(self::$fixtures . '/shell.jpg', "<?php system(\$_GET['c']); ?>");
        file_put_contents(self::$fixtures . '/polyglot.jpg', "GIF89a<?php echo 1; ?>");
        // Valid JPEG header followed by junk: not decodable.
        file_put_contents(self::$fixtures . '/corrupt.jpg', "\xFF\xD8\xFF\xE0" . random_bytes(2000));
        // Over the app's 5 MB limit but under PHP's (test server runs with the recommended 6M upload_max_filesize).
        file_put_contents(self::$fixtures . '/huge.jpg', file_get_contents(self::$fixtures . '/valid.jpg') . str_repeat("\0", 5 * 1024 * 1024 + 10));
        // Over PHP's own upload_max_filesize: PHP drops it before the app runs.
        file_put_contents(self::$fixtures . '/over-php-limit.jpg', file_get_contents(self::$fixtures . '/valid.jpg') . str_repeat("\0", 7 * 1024 * 1024));
    }

    private function draftFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/storage/uploads/drafts/*') ?: [];
    }

    private function tmpFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/storage/tmp/*') ?: [];
    }

    private function publicDraftFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/public/uploads/listings/{draft,ai}_*', GLOB_BRACE) ?: [];
    }

    private function verifiedBrowser(): Browser
    {
        $email = $this->uniqueEmail('uploader');
        $this->createUser($email);
        $b = $this->browser();
        $b->login($email, 'correct-horse');
        return $b;
    }

    private function describeError(Browser $b, string $fixture): string
    {
        $r = $b->post('/listings/create/describe', [], true, ['image' => new \CURLFile(self::$fixtures . '/' . $fixture, 'image/jpeg', $fixture)]);
        $this->assertRedirectsTo('/listings/create', $r);
        $b->get('/listings/create');
        preg_match('/class="form-error">([^<]+)</', $b->lastBody, $m);
        return html_entity_decode($m[1] ?? '');
    }

    public function testFakeAndUnsafeImagesAreRejected(): void
    {
        $b = $this->verifiedBrowser();
        $before = $this->draftFiles();
        foreach (['shell.jpg', 'polyglot.jpg', 'corrupt.jpg', 'tiny.png', 'too-wide.png', 'huge.jpg', 'over-php-limit.jpg'] as $fixture) {
            $this->assertStringContainsString('Please upload a JPEG, PNG or WebP photo', $this->describeError($b, $fixture), $fixture);
        }
        $this->assertSame($before, $this->draftFiles(), 'Rejected files must not be stored');
        $this->assertSame([], $this->publicDraftFiles());
    }

    public function testValidImageWithFailedAiLeavesNoFilesBehind(): void
    {
        $b = $this->verifiedBrowser();
        $before = $this->draftFiles();
        // No AI key is configured in tests, so the AI step fails after the upload is accepted.
        $this->assertStringContainsString('AI description failed', $this->describeError($b, 'valid.jpg'));
        $this->assertSame($before, $this->draftFiles());
        $this->assertSame([], $this->publicDraftFiles());
    }

    public function testAiDescribeIsRateLimitedPerUser(): void
    {
        $b = $this->verifiedBrowser();
        // Tests run with RATE_AI_PER_HOUR=2.
        $this->assertStringContainsString('AI description failed', $this->describeError($b, 'valid.jpg'));
        $this->assertStringContainsString('AI description failed', $this->describeError($b, 'valid.jpg'));
        $this->assertStringContainsString('generated a lot of AI descriptions', $this->describeError($b, 'valid.jpg'));

        // Rejected uploads do not count against the limit (a different user, all invalid files).
        $c = $this->verifiedBrowser();
        $this->describeError($c, 'shell.jpg');
        $this->describeError($c, 'shell.jpg');
        $this->assertStringContainsString('AI description failed', $this->describeError($c, 'valid.jpg'));
    }

    public function testAiApiRejectsBadFilesCleansUpAndRateLimits(): void
    {
        $b = $this->verifiedBrowser();
        $tmpBefore = $this->tmpFiles();
        $bad = $b->post('/api/listing/ai-describe', [], true, ['images[0]' => new \CURLFile(self::$fixtures . '/shell.jpg', 'image/jpeg')]);
        $this->assertSame(400, $bad->status);

        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $statuses[] = $b->post('/api/listing/ai-describe', [], true, ['images[0]' => new \CURLFile(self::$fixtures . '/valid.jpg', 'image/jpeg')])->status;
        }
        $this->assertSame([502, 502, 429], $statuses);
        $this->assertSame($tmpBefore, $this->tmpFiles(), 'Temporary AI files must always be deleted');
    }

    public function testDraftImagesAreNotPubliclyAddressable(): void
    {
        $b = $this->verifiedBrowser();
        $this->assertSame(404, $b->get('/listings/create/draft-image')->status, 'No draft in session: nothing to serve');
        $this->assertRedirectsTo('/login', $this->browser()->get('/listings/create/draft-image'));
    }
}
