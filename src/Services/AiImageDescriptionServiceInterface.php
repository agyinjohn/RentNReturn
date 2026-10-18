<?php

declare(strict_types=1);

namespace App\Services;

interface AiImageDescriptionServiceInterface
{
    /**
     * System prompt for generating marketplace-style listing copy (title + description + attributes).
     * Written to match the style of major online marketplaces (e.g. eBay, Amazon, Facebook Marketplace).
     */
    public const DESCRIPTION_PROMPT = <<<'PROMPT'
You are writing a product listing for an online marketplace (like eBay, Amazon, or Facebook Marketplace). Based on the attached image(s), create listing copy that buyers expect to see on such sites.

Provide your response as valid JSON only, in this exact shape: {"suggested_title":"...","description":"...","attributes":{...}}

1. suggested_title: A short, search-friendly listing title (one line, under ~80 characters). Include the item type, key brand or model if visible, and one standout detail. Example style: "Cordless Drill 18V with 2 Batteries - Like New" or "Vintage Wooden Bookshelf 5-Tier Solid Oak".

2. description: A clear, scannable product description (2–5 sentences) in the style of real marketplace listings. Write in a neutral, informative tone. Include: what the item is; notable features, brand/model, or specs if visible; condition (e.g. like new, good, minor wear); and any relevant details a buyer would want. Use short paragraphs or bullet-style phrasing if it helps. No hype or filler—focus on useful, factual detail that helps buyers decide.

3. attributes: A JSON object with optional keys such as color, brand, item_type, condition_cues, material (only if clearly visible). Use empty object {} if unsure.
PROMPT;

    /**
     * @param string[] $imagePaths Full paths to image files (local filesystem)
     * @return array{title: string|null, description: string, attributes: array<string, string>}
     */
    public function describeImages(array $imagePaths): array;
}
