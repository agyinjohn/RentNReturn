<?php

declare(strict_types=1);

namespace App\Services;

class AiImageDescriptionServiceFactory
{
    /**
     * @param array{provider: string, groq_api_key?: string, groq_model?: string, openai_api_key: string, openai_model?: string, gemini_api_key: string, gemini_model?: string} $aiConfig
     */
    public static function create(array $aiConfig): AiImageDescriptionServiceInterface
    {
        $provider = $aiConfig['provider'] ?? 'pipeline';
        if ($provider === 'groq') {
            return new GroqImageDescriptionService(
                $aiConfig['groq_api_key'] ?? '',
                $aiConfig['groq_model'] ?? 'meta-llama/llama-4-scout-17b-16e-instruct'
            );
        }
        if ($provider === 'openai') {
            return new OpenAiImageDescriptionService(
                $aiConfig['openai_api_key'],
                $aiConfig['openai_model'] ?? 'gpt-4o'
            );
        }
        if ($provider === 'gemini') {
            return new GeminiImageDescriptionService(
                $aiConfig['gemini_api_key'],
                $aiConfig['gemini_model'] ?? 'gemini-1.5-flash'
            );
        }
        // pipeline: try Groq first, then Gemini, then OpenAI (first with a key wins on success)
        $services = [];
        if (!empty($aiConfig['groq_api_key'])) {
            $services[] = new GroqImageDescriptionService(
                $aiConfig['groq_api_key'],
                $aiConfig['groq_model'] ?? 'meta-llama/llama-4-scout-17b-16e-instruct'
            );
        }
        if (!empty($aiConfig['gemini_api_key'])) {
            $services[] = new GeminiImageDescriptionService(
                $aiConfig['gemini_api_key'],
                $aiConfig['gemini_model'] ?? 'gemini-1.5-flash'
            );
        }
        if (!empty($aiConfig['openai_api_key'])) {
            $services[] = new OpenAiImageDescriptionService(
                $aiConfig['openai_api_key'],
                $aiConfig['openai_model'] ?? 'gpt-4o'
            );
        }
        if (count($services) === 0) {
            throw new \RuntimeException('AI pipeline requires at least one of GROQ_API_KEY, GEMINI_API_KEY, or OPENAI_API_KEY.');
        }
        if (count($services) === 1) {
            return $services[0];
        }
        return new PipelineImageDescriptionService(...$services);
    }
}
