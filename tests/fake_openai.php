<?php
// Fake OpenAI-compatible endpoint for tests (run with PHP's built-in server). Returns a fixed listing suggestion.
header('Content-Type: application/json');
$request = json_decode((string) file_get_contents('php://input'), true);
$hasImage = str_starts_with((string) ($request['messages'][0]['content'][1]['image_url']['url'] ?? ''), 'data:image/');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-key' || !$hasImage) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'bad request']]);
    return;
}
echo json_encode(['choices' => [['message' => ['content' => json_encode([
    'suggested_title' => 'Cordless Drill 18V',
    'description' => 'Green cordless drill with charger, lightly used.',
    'attributes' => ['color' => 'green'],
])]]]]);
