<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

/**
 * Groq vision API (OpenAI-compatible endpoint).
 * Vision models: meta-llama/llama-4-scout-17b-16e-instruct, meta-llama/llama-4-maverick-17b-128e-instruct
 * @see https://console.groq.com/docs/vision
 */
class GroqImageDescriptionService implements AiImageDescriptionServiceInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'meta-llama/llama-4-scout-17b-16e-instruct')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
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
            $mime = $this->normalizeMimeType(mime_content_type($path) ?: 'image/jpeg');
            $base64 = base64_encode((string) file_get_contents($path));
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $base64,
                ],
            ];
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => 500,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        $verifySSL = (getenv('CURL_SSL_VERIFY') !== '0' && getenv('CURL_SSL_VERIFY') !== 'false');
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
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
            $msg = $curlError !== '' ? 'AI (Groq): ' . $curlError : 'AI description service: request failed.';
            throw new Exception($msg);
        }
        $data = json_decode($response, true);
        if ($status !== 200) {
            $message = $data['error']['message'] ?? $data['error']['code'] ?? 'HTTP ' . $status;
            throw new Exception('AI (Groq): ' . (is_string($message) ? $message : json_encode($message)));
        }
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

    private function normalizeMimeType(string $mime): string
    {
        $map = [
            'image/jpg' => 'image/jpeg',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
        ];
        return $map[strtolower($mime)] ?? 'image/jpeg';
    }
}
