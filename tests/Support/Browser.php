<?php

declare(strict_types=1);

namespace Tests\Support;

/** Minimal browser: keeps its own cookies, does not follow redirects, can submit the CSRF token from the last page. */
final class Browser
{
    private string $cookieJar;
    public string $lastBody = '';

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'rnr-cookies-');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /** POST form fields; set $csrf to false to omit the token, or a string to send a specific one. */
    public function post(string $path, array $fields = [], bool|string $csrf = true, array $files = []): Response
    {
        if ($csrf === true) {
            $fields['_csrf'] = $this->csrfToken();
        } elseif (is_string($csrf)) {
            $fields['_csrf'] = $csrf;
        }
        foreach ($files as $name => $file) {
            $fields[$name] = $file instanceof \CURLFile ? $file : new \CURLFile($file);
        }
        return $this->request('POST', $path, $fields, $files !== []);
    }

    /** Current CSRF token for this browser's session (the token rotates at login, so always fetch a fresh page). */
    public function csrfToken(): string
    {
        $this->get('/forgot-password');
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->lastBody, $m);
        return $m[1] ?? '';
    }

    public function sessionId(): ?string
    {
        foreach (file($this->cookieJar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === 'rentnreturn_session') {
                return $parts[6];
            }
        }
        return null;
    }

    public function login(string $email, string $password): Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function request(string $method, string $path, array $fields = [], bool $multipart = false): Response
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (str_contains($headers['content-type'] ?? 'text/html', 'html')) {
            $this->lastBody = $body;
        }
        return new Response($status, $headers, $body);
    }
}
