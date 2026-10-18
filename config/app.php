<?php

declare(strict_types=1);

$env = getenv('APP_ENV') ?: 'development';
$storagePath = dirname(__DIR__) . '/storage';

return [
    'name' => 'RentNReturn',
    // development | staging | production
    'env' => $env,
    // Debug defaults to on only in development. Production refuses to boot with debug on.
    'debug' => filter_var(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : ($env === 'development' ? '1' : '0'), FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'),
    'timezone' => 'UTC',
    // Set to 1 only when running behind a reverse proxy/load balancer that sets X-Forwarded-* headers.
    'trust_proxy_headers' => filter_var(getenv('TRUST_PROXY_HEADERS') ?: '0', FILTER_VALIDATE_BOOLEAN),

    'database' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'p2p_rent',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],

    'storage_path' => $storagePath,
    'log' => [
        'path' => getenv('LOG_PATH') ?: $storagePath . '/logs',
        // debug | info | warning | error
        'level' => getenv('LOG_LEVEL') ?: 'info',
    ],

    'upload' => [
        'listing_images_path' => __DIR__ . '/../public/uploads/listings',
        // Draft/AI images live outside the public web root until the listing is saved.
        'draft_images_path' => $storagePath . '/uploads/drafts',
        'tmp_path' => $storagePath . '/tmp',
        'max_files' => 5,
        'min_files' => 1,
        'max_size_mb' => 5,
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'min_dimension' => 100,
        'max_dimension' => 10000,
        'max_megapixels' => 50,
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
        // Idle timeout in seconds; the session is destroyed after this long without a request.
        'lifetime' => (int) (getenv('SESSION_LIFETIME') ?: 7200),
        'secure' => $env !== 'development',
    ],

    'mail' => [
        // 'log' writes emails to storage/logs/mail.log (development only); 'smtp' sends them.
        'driver' => getenv('MAIL_DRIVER') ?: 'log',
        'host' => getenv('MAIL_HOST') ?: '',
        'port' => (int) (getenv('MAIL_PORT') ?: 587),
        'username' => getenv('MAIL_USERNAME') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        // tls (STARTTLS, usually port 587) | ssl (usually port 465) | none
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@rentnreturn.local',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'RentNReturn',
        'log_path' => getenv('MAIL_LOG_PATH') ?: $storagePath . '/logs/mail.log',
    ],

    'tokens' => [
        'password_reset_ttl' => 3600,
        'email_verify_ttl' => 86400,
    ],

    // [max attempts, window seconds]
    'rate_limits' => [
        'login_email' => [(int) (getenv('RATE_LOGIN_PER_EMAIL') ?: 5), 900],
        'login_ip' => [(int) (getenv('RATE_LOGIN_PER_IP') ?: 30), 900],
        'password_reset_email' => [3, 3600],
        'password_reset_ip' => [10, 3600],
        'signup_ip' => [10, 3600],
        'verify_resend_user' => [3, 3600],
        'ai_describe_user_hour' => [(int) (getenv('RATE_AI_PER_HOUR') ?: 10), 3600],
        'ai_describe_user_day' => [(int) (getenv('RATE_AI_PER_DAY') ?: 30), 86400],
    ],
];
