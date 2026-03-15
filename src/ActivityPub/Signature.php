<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;

final class Signature
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Verify an incoming HTTP signature on a POST request.
     * Returns the actor URL if valid, null if verification fails.
     */
    public function verify(): ?string
    {
        $signatureHeader = $_SERVER['HTTP_SIGNATURE'] ?? '';
        if ($signatureHeader === '') {
            return null;
        }

        $parts = $this->parseSignatureHeader($signatureHeader);
        if ($parts === null) {
            return null;
        }

        // Validate date (±1 hour tolerance)
        $dateHeader = $_SERVER['HTTP_DATE'] ?? '';
        if ($dateHeader !== '') {
            $headerTime = strtotime($dateHeader);
            if ($headerTime === false || abs(time() - $headerTime) > 3600) {
                return null;
            }
        }

        // Validate digest if present
        $digestHeader = $_SERVER['HTTP_DIGEST'] ?? '';
        $rawBody = file_get_contents('php://input');
        if ($digestHeader !== '') {
            $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $rawBody, true));
            if ($digestHeader !== $expectedDigest) {
                return null;
            }
        }

        // Fetch the remote actor's public key
        $keyId = $parts['keyId'];
        $actorUrl = preg_replace('/#.*$/', '', $keyId);
        $remoteActor = $this->fetchJson($actorUrl);
        if ($remoteActor === null) {
            return null;
        }

        $publicKeyPem = $remoteActor['publicKey']['publicKeyPem'] ?? null;
        if ($publicKeyPem === null) {
            return null;
        }

        // Reconstruct the signed string
        $headers = explode(' ', $parts['headers']);
        $signedParts = [];
        foreach ($headers as $header) {
            $value = match ($header) {
                '(request-target)' => strtolower($_SERVER['REQUEST_METHOD']) . ' ' . $_SERVER['REQUEST_URI'],
                'host'             => $_SERVER['HTTP_HOST'] ?? '',
                'date'             => $_SERVER['HTTP_DATE'] ?? '',
                'digest'           => $_SERVER['HTTP_DIGEST'] ?? '',
                'content-type'     => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '',
                default            => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '',
            };
            $signedParts[] = "{$header}: {$value}";
        }
        $signedString = implode("\n", $signedParts);

        // Verify the signature
        $signature = base64_decode($parts['signature']);
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return null;
        }

        $result = openssl_verify($signedString, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1 ? $actorUrl : null;
    }

    /**
     * Sign an outgoing HTTP request.
     * Returns headers array to include in the request.
     */
    public function sign(string $url, string $body = '', string $method = 'POST'): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';
        $date = gmdate('D, d M Y H:i:s T');

        $privateKeyPem = $this->config->getRequired('KEY_PRIVATE');
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Failed to load private key');
        }

        $actorUrl = $this->config->actorUrl();
        $keyId = "{$actorUrl}#main-key";

        $headersToSign = ['(request-target)', 'host', 'date'];
        $signedParts = [
            "(request-target): " . strtolower($method) . " {$path}",
            "host: {$host}",
            "date: {$date}",
        ];

        $digest = '';
        if ($body !== '') {
            $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
            $headersToSign[] = 'digest';
            $signedParts[] = "digest: {$digest}";
        }

        $signedString = implode("\n", $signedParts);
        openssl_sign($signedString, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $signatureB64 = base64_encode($signature);
        $headersList = implode(' ', $headersToSign);

        $headers = [
            'Host: ' . $host,
            'Date: ' . $date,
            'Content-Type: application/activity+json',
            "Signature: keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"{$headersList}\",signature=\"{$signatureB64}\"",
        ];

        if ($digest !== '') {
            $headers[] = 'Digest: ' . $digest;
        }

        return $headers;
    }

    private function parseSignatureHeader(string $header): ?array
    {
        $parts = [];
        // Match key="value" pairs
        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parts[$match[1]] = $match[2];
            }
        }

        if (!isset($parts['keyId'], $parts['signature'], $parts['headers'])) {
            return null;
        }

        return $parts;
    }

    /**
     * Fetch a remote JSON document (actor profile, etc.)
     */
    public function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/activity+json, application/ld+json',
                'User-Agent: Fedibots/0.1.0',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
