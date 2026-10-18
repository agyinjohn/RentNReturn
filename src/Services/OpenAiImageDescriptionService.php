<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

class OpenAiImageDescriptionService implements AiImageDescriptionServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    /** $baseUrl allows OpenAI-compatible gateways (and a local fake server in tests). */
    public function __construct(string $apiKey, string $model = 'gpt-4o', string $baseUrl = 'https://api.openai.com/v1')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function describeImages(array $imagePaths): array
    {
        if (empty($imagePaths)) {
            return ['title' => null, 'description' => 'No images provided.', 'attributes' => []];
        }

        $content = [];
        $content[] = [
            'type' => 'text',
            'text' => AiImageDescriptionServiceInterface::DESCRIPTION_PROMPT,
        ];
        foreach (array_slice($imagePaths, 0, 5) as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $mime = mime_content_type($path);
            $base64 = base64_encode((string) file_get_contents($path));
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $mime . ';base64,' . $base64],
            ];
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => 500,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $verifySSL = (getenv('CURL_SSL_VERIFY') !== '0' && getenv('CURL_SSL_VERIFY') !== 'false');
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            $msg = $curlError !== '' ? 'AI (OpenAI): ' . $curlError : 'AI description service: request failed.';
            throw new Exception($msg);
        }
        if ($status !== 200) {
            $data = json_decode($response, true);
            $message = $data['error']['message'] ?? $data['error']['code'] ?? 'HTTP ' . $status;
            throw new Exception('AI (OpenAI): ' . (is_string($message) ? $message : json_encode($message)));
        }
        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            $decoded = [
                'suggested_title' => null,
                'description' => trim($text) ?: 'Description could not be generated.',
                'attributes' => [],
            ];
        }
        return [
            'title' => $decoded['suggested_title'] ?? null,
            'description' => (string) ($decoded['description'] ?? ''),
            'attributes' => (array) ($decoded['attributes'] ?? []),
        ];
    }
}
