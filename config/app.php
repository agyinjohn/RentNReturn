<?php

declare(strict_types=1);

return [
    'name' => 'RentNReturn',
    'env' => getenv('APP_ENV') ?: 'development',
    'debug' => filter_var(getenv('APP_DEBUG') ?: '1', FILTER_VALIDATE_BOOLEAN),
    'url' => getenv('APP_URL') ?: 'http://localhost:8080',
    'timezone' => 'UTC',

    'database' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'p2p_rent',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],

    'upload' => [
        'listing_images_path' => __DIR__ . '/../public/uploads/listings',
        'max_files' => 5,
        'min_files' => 1,
        'max_size_mb' => 5,
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'ai' => [
        // 'groq' | 'gemini' | 'openai' | 'pipeline' (try Groq, then Gemini, then OpenAI)
        'provider' => getenv('AI_PROVIDER') ?: 'pipeline',
        'groq_api_key' => getenv('GROQ_API_KEY') ?: '',
        'groq_model' => getenv('GROQ_MODEL') ?: 'meta-llama/llama-4-scout-17b-16e-instruct',
        'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
        'openai_model' => getenv('OPENAI_MODEL') ?: 'gpt-4o',
        'gemini_api_key' => getenv('GEMINI_API_KEY') ?: '',
        'gemini_model' => getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash',
    ],

    'session' => [
        'name' => 'rentnreturn_session',
        'lifetime' => 7200,
        'secure' => (getenv('APP_ENV') ?: 'development') === 'production',
    ],
];
