<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\Browser;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Creating a listing end to end: without an AI key, with a working AI provider, and with a failing one. */
final class ListingCreationTest extends IntegrationTestCase
{
    private static string $photo;
    /** @var string[] public listing photos created by these tests, deleted afterwards */
    private array $createdFiles = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$photo = sys_get_temp_dir() . '/rnr-listing-photo-' . getmypid() . '.jpg';
        $img = imagecreatetruecolor(640, 480);
        imagefilledrectangle($img, 0, 0, 640, 480, imagecolorallocate($img, 70, 120, 80));
        imagejpeg($img, self::$photo);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
    }

    private function owner(string $url): Browser
    {
        $email = $this->uniqueEmail('owner');
        $this->createUser($email);
        $b = new Browser($url);
        $b->login($email, 'correct-horse');
        return $b;
    }

    private function upload(Browser $b): string
    {
        $r = $b->post('/listings/create/describe', [], true, ['image' => new \CURLFile(self::$photo, 'image/jpeg', 'drill.jpg')]);
        $this->assertRedirectsTo('/listings/create/review', $r);
        return $b->get('/listings/create/review')->body;
    }

    /** Finish the review and details steps; returns the created listing row. */
    private function finish(Browser $b, string $title, string $description): array
    {
        $this->assertRedirectsTo('/listings/create/details', $b->post('/listings/create/review', ['listing_id' => '0', 'title' => $title, 'final_description' => $description]));
        $details = $b->get('/listings/create/details');
        $this->assertSame(200, $details->status);
        $this->assertStringContainsString('src="/listings/create/draft-image"', $details->body, 'Owner sees their photo');

        $photo = $b->get('/listings/create/draft-image');
        $this->assertSame(200, $photo->status);
        $this->assertSame('image/jpeg', $photo->header('Content-Type'));
        $this->assertSame(file_get_contents(self::$photo), $photo->body);

        $r = $b->post('/listings/create', ['category' => 'tools', 'condition' => 'good', 'price_daily' => '12.50', 'available_now' => '1']);
        $this->assertRedirectsTo('/listings/my', $r);
        $this->assertStringContainsString($title, $b->get('/listings/my')->body);

        $listing = $this->db()->query('SELECT * FROM listings ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame($title, $listing['title']);
        $this->assertSame($description, $listing['final_description']);
        $this->assertSame('12.50', $listing['price_daily']);

        $image = $this->db()->query('SELECT file_path FROM listing_images WHERE listing_id = ' . (int) $listing['id'])->fetchColumn();
        $this->assertNotFalse($image, 'The uploaded photo becomes the listing image');
        $file = dirname(__DIR__, 2) . '/public/' . $image;
        $this->createdFiles[] = $file;
        $this->assertFileExists($file);
        $this->assertSame(404, $b->get('/listings/create/draft-image')->status, 'Draft is used up once the listing is saved');
        return $listing;
    }

    public function testListingCanBeCreatedWithoutAnAiKey(): void
    {
        $b = $this->owner(static::$url); // default test server: no AI keys configured
        $create = $b->get('/listings/create')->body;
        $this->assertStringNotContainsString('generate a title and description', $create);
        $this->assertStringContainsString('>Continue<', $create);

        $review = $this->upload($b);
        $this->assertStringContainsString('Describe your item', $review);
        $this->assertStringNotContainsString('AI suggested title', $review);
        $this->assertStringNotContainsString('class="form-notice"', $review, 'No AI configured is normal, not an error');
        $this->assertMatchesRegularExpression('/id="title" name="title" required value=""/', $review);

        $listing = $this->finish($b, 'Hedge Trimmer 600mm', 'Electric hedge trimmer, 600 mm blade, good condition.');
        $this->assertNull($listing['ai_suggested_description'], 'No AI suggestion stored when none was made');
    }

    public function testListingWithWorkingAiPrefillsSuggestion(): void
    {
        $url = TestEnvironment::server(['AI_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'test-key', 'OPENAI_BASE_URL' => TestEnvironment::fakeAiServer()]);
        $b = $this->owner($url);
        $this->assertStringContainsString('Generate description', $b->get('/listings/create')->body);

        $review = $this->upload($b);
        $this->assertStringContainsString('Review & edit description', $review);
        $this->assertStringContainsString('value="Cordless Drill 18V"', $review);
        $this->assertStringContainsString('Green cordless drill with charger, lightly used.', $review);

        $listing = $this->finish($b, 'Cordless Drill 18V', 'Green cordless drill with charger. Edited by the owner.');
        $this->assertSame('Green cordless drill with charger, lightly used.', $listing['ai_suggested_description']);
    }

    public function testFailingAiFallsBackToManualEntryAndKeepsThePhoto(): void
    {
        $url = TestEnvironment::server(['AI_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'test-key', 'OPENAI_BASE_URL' => TestEnvironment::deadUrl()]);
        $b = $this->owner($url);
        $review = $this->upload($b);
        $this->assertStringContainsString('could not generate a description automatically', $review);
        $this->assertStringContainsString('Describe your item', $review);

        $listing = $this->finish($b, 'Pressure Washer', 'Petrol pressure washer with two nozzles.');
        $this->assertNull($listing['ai_suggested_description']);
    }
}
