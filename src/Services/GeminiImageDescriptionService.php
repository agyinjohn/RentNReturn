<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

class GeminiImageDescriptionService implements AiImageDescriptionServiceInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function describeImages(array $imagePaths): array
    {
        if (empty($imagePaths)) {
            return ['title' => null, 'description' => 'No images provided.', 'attributes' => []];
        }

        $prompt = AiImageDescriptionServiceInterface::DESCRIPTION_PROMPT;

        // Gemini API requires image part(s) first, then text (order matters for vision).
        $parts = [];
        foreach (array_slice($imagePaths, 0, 5) as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $rawMime = mime_content_type($path);
            $mime = $this->normalizeMimeType($rawMime);
            $base64 = base64_encode((string) file_get_contents($path));
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => $base64,
                ],
            ];
        }
        if (empty($parts)) {
            return ['title' => null, 'description' => 'No valid images could be read.', 'attributes' => []];
        }
        $parts[] = ['text' => $prompt];

        $body = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 500,
                'temperature' => 0.4,
            ],
        ];

        $verifySSL = (getenv('CURL_SSL_VERIFY') !== '0' && getenv('CURL_SSL_VERIFY') !== 'false');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . urlencode($this->apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $msg = $curlError !== '' ? 'AI (Gemini): ' . $curlError : 'AI description service: request failed.';
            throw new Exception($msg);
        }
        $data = json_decode($response, true);
        if ($status !== 200) {
            $message = $data['error']['message'] ?? $data['error']['status'] ?? 'Unknown error';
            if (isset($data['error']['details'][0]['message'])) {
                $message = $data['error']['details'][0]['message'];
            }
            throw new Exception('AI description failed: ' . (is_string($message) ? $message : json_encode($message)));
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '' && !empty($data['candidates'][0]['finishReason'])) {
            $reason = $data['candidates'][0]['finishReason'];
            throw new Exception('AI did not return text (reason: ' . $reason . '). Try another image.');
        }
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
