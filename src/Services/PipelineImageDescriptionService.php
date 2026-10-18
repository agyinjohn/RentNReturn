<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

/**
 * Tries multiple vision-capable services in order until one succeeds.
 * Use when one provider (e.g. Gemini) may not accept images; fallback (e.g. OpenAI) can.
 */
class PipelineImageDescriptionService implements AiImageDescriptionServiceInterface
{
    /** @var AiImageDescriptionServiceInterface[] */
    private array $services;

    public function __construct(AiImageDescriptionServiceInterface ...$services)
    {
        $this->services = $services;
    }

    public function describeImages(array $imagePaths): array
    {
        if (empty($imagePaths)) {
            return ['title' => null, 'description' => 'No images provided.', 'attributes' => []];
        }
        $lastException = null;
        foreach ($this->services as $service) {
            try {
                return $service->describeImages($imagePaths);
            } catch (Exception $e) {
                $lastException = $e;
            }
        }
        throw $lastException ?? new Exception('AI description service unavailable.');
    }
}
