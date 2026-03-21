<?php

declare(strict_types=1);

namespace Fedibots;

final class Config
{
    private array $values = [];
    private string $domain;
    private string $baseUrl;

    public function __construct(string $envPath)
    {
        if (!file_exists($envPath)) {
            throw new \RuntimeException(".env file not found at: {$envPath}");
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            // Handle \n escape sequences (for RSA keys)
            $value = str_replace('\\n', "\n", $value);
            $this->values[$key] = $value;
        }

        $configuredBaseUrl = rtrim($this->values['BASE_URL'] ?? '', '/');
        if ($configuredBaseUrl !== '') {
            $parts = parse_url($configuredBaseUrl);
            $host = $parts['host'] ?? null;
            if ($host === null) {
                throw new \RuntimeException('BASE_URL must include a valid host');
            }

            $this->baseUrl = $configuredBaseUrl;
            $this->domain = $host;
            return;
        }

        $this->domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $this->baseUrl = "{$scheme}://{$this->domain}";
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function getRequired(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required config key missing: {$key}");
        }
        return $value;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function actorUrl(): string
    {
        return $this->baseUrl . '/' . $this->getRequired('USERNAME');
    }
}
