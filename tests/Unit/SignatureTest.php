<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\ActivityPub\Signature;
use Fedibots\Config;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        // Generate a test keypair
        $keyConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($keyConfig);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $publicKey = $details['key'];

        $privateEscaped = str_replace("\n", '\\n', trim($privateKey));
        $publicEscaped  = str_replace("\n", '\\n', trim($publicKey));

        $this->envPath = sys_get_temp_dir() . '/fedibots_test_' . uniqid() . '.env';
        file_put_contents($this->envPath, <<<ENV
USERNAME=testbot
KEY_PRIVATE={$privateEscaped}
KEY_PUBLIC={$publicEscaped}
PASSWORD=\$2y\$10\$fakehashfakehashfakehashfakehashfakehashfakehash
ENV
        );

        $_SERVER['HTTP_HOST'] = 'bot.example.com';
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->envPath)) {
            unlink($this->envPath);
        }
    }

    public function testSignProducesRequiredHeaders(): void
    {
        $config = new Config($this->envPath);
        $signature = new Signature($config);

        $headers = $signature->sign('https://mastodon.social/inbox', '{"type":"Create"}');

        $headerMap = [];
        foreach ($headers as $h) {
            [$name, $value] = explode(': ', $h, 2);
            $headerMap[$name] = $value;
        }

        $this->assertArrayHasKey('Host', $headerMap);
        $this->assertSame('mastodon.social', $headerMap['Host']);
        $this->assertArrayHasKey('Date', $headerMap);
        $this->assertArrayHasKey('Signature', $headerMap);
        $this->assertArrayHasKey('Content-Type', $headerMap);
        $this->assertSame('application/activity+json', $headerMap['Content-Type']);
        $this->assertArrayHasKey('Digest', $headerMap);
    }

    public function testSignatureHeaderFormat(): void
    {
        $config = new Config($this->envPath);
        $signature = new Signature($config);

        $headers = $signature->sign('https://mastodon.social/inbox', '{}');

        $sigHeader = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Signature:')) {
                $sigHeader = substr($h, 11);
            }
        }

        $this->assertStringContainsString('keyId=', $sigHeader);
        $this->assertStringContainsString('algorithm="rsa-sha256"', $sigHeader);
        $this->assertStringContainsString('headers="(request-target) host date digest"', $sigHeader);
        $this->assertStringContainsString('signature=', $sigHeader);
        $this->assertStringContainsString('#main-key', $sigHeader);
    }

    public function testDigestIsCorrectSha256(): void
    {
        $config = new Config($this->envPath);
        $signature = new Signature($config);

        $body = '{"type":"Create","object":{"type":"Note"}}';
        $headers = $signature->sign('https://mastodon.social/inbox', $body);

        $digestHeader = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Digest:')) {
                $digestHeader = substr($h, 8);
            }
        }

        $expected = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $this->assertSame($expected, $digestHeader);
    }

    public function testSignWithoutBodyOmitsDigest(): void
    {
        $config = new Config($this->envPath);
        $signature = new Signature($config);

        $headers = $signature->sign('https://mastodon.social/inbox', '');

        foreach ($headers as $h) {
            $this->assertStringNotContainsString('Digest:', $h);
        }

        $sigHeader = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Signature:')) {
                $sigHeader = substr($h, 11);
            }
        }
        $this->assertStringContainsString('headers="(request-target) host date"', $sigHeader);
    }

    public function testSignatureIsVerifiable(): void
    {
        $config = new Config($this->envPath);
        $signature = new Signature($config);

        $body = '{"test":true}';
        $url = 'https://remote.example.com/inbox';
        $headers = $signature->sign($url, $body);

        // Extract the signature parts
        $sigHeader = '';
        $dateHeader = '';
        $digestHeader = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Signature:')) $sigHeader = substr($h, 11);
            if (str_starts_with($h, 'Date:')) $dateHeader = substr($h, 6);
            if (str_starts_with($h, 'Digest:')) $digestHeader = substr($h, 8);
        }

        // Parse signature header
        preg_match_all('/(\w+)="([^"]*)"/', $sigHeader, $m, PREG_SET_ORDER);
        $parts = [];
        foreach ($m as $match) {
            $parts[$match[1]] = $match[2];
        }

        // Reconstruct signed string
        $signedString = "(request-target): post /inbox\n"
            . "host: remote.example.com\n"
            . "date: {$dateHeader}\n"
            . "digest: {$digestHeader}";

        // Verify with public key from config (which handles \n unescaping)
        $pubKey = openssl_pkey_get_public($config->getRequired('KEY_PUBLIC'));

        $sigBytes = base64_decode($parts['signature']);
        $result = openssl_verify($signedString, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $result, 'Signature should verify against the public key');
    }
}
