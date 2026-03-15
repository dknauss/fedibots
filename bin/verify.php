#!/usr/bin/env php
<?php

/**
 * Verify a Fedibots deployment.
 * Checks config, keys, directories, and optionally tests live endpoints.
 *
 * Usage: php bin/verify.php
 *   Or:  php bin/verify.php https://bot.example.com  (also test live endpoints)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$passed = 0;
$failed = 0;
$warnings = 0;

echo "=== Fedibots Deployment Verification ===\n\n";

// Check .env exists
$envPath = $rootDir . '/.env';
if (file_exists($envPath)) {
    pass('.env file exists');
} else {
    fail('.env file missing — run: php bin/setup.php');
}

// Load config
if (file_exists($envPath)) {
    $env = parseEnv($envPath);

    // Required fields
    foreach (['USERNAME', 'KEY_PRIVATE', 'KEY_PUBLIC', 'PASSWORD'] as $key) {
        if (!empty($env[$key])) {
            pass("{$key} is set");
        } else {
            fail("{$key} is missing or empty in .env");
        }
    }

    // Validate RSA keys
    if (!empty($env['KEY_PRIVATE'])) {
        $privKey = str_replace('\\n', "\n", $env['KEY_PRIVATE']);
        $key = openssl_pkey_get_private($privKey);
        if ($key !== false) {
            pass('Private key is valid RSA');
        } else {
            fail('Private key is invalid — regenerate with: php bin/keygen.php');
        }
    }

    if (!empty($env['KEY_PUBLIC'])) {
        $pubKey = str_replace('\\n', "\n", $env['KEY_PUBLIC']);
        $key = openssl_pkey_get_public($pubKey);
        if ($key !== false) {
            pass('Public key is valid RSA');
        } else {
            fail('Public key is invalid — regenerate with: php bin/keygen.php');
        }
    }

    // Verify password is a bcrypt hash
    if (!empty($env['PASSWORD'])) {
        if (str_starts_with($env['PASSWORD'], '$2y$') || str_starts_with($env['PASSWORD'], '$2b$')) {
            pass('Password is a bcrypt hash');
        } else {
            fail('PASSWORD should be a bcrypt hash, not plaintext');
        }
    }
}

// Check directories
foreach (['data/followers', 'data/posts', 'data/inbox', 'data/logs'] as $dir) {
    $path = $rootDir . '/' . $dir;
    if (is_dir($path) && is_writable($path)) {
        pass("{$dir}/ exists and is writable");
    } elseif (is_dir($path)) {
        fail("{$dir}/ exists but is not writable");
    } else {
        warn("{$dir}/ does not exist — will be created on first use");
    }
}

// Check required source files
$requiredFiles = [
    'index.php',
    '.htaccess',
    'src/Config.php',
    'src/Http/Router.php',
    'src/ActivityPub/WebFinger.php',
    'src/ActivityPub/Actor.php',
    'src/ActivityPub/Inbox.php',
    'src/ActivityPub/Outbox.php',
    'src/ActivityPub/Signature.php',
    'src/ActivityPub/Delivery.php',
    'content/ContentProvider.php',
];

foreach ($requiredFiles as $file) {
    if (file_exists($rootDir . '/' . $file)) {
        pass("{$file} exists");
    } else {
        fail("{$file} is missing");
    }
}

// Check PHP version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.2.0', '>=')) {
    pass("PHP version {$phpVersion} (>= 8.2 required)");
} else {
    fail("PHP version {$phpVersion} — 8.2+ required");
}

// Check OpenSSL extension
if (extension_loaded('openssl')) {
    pass('OpenSSL extension loaded');
} else {
    fail('OpenSSL extension not loaded');
}

// Check cURL extension
if (extension_loaded('curl')) {
    pass('cURL extension loaded');
} else {
    fail('cURL extension not loaded — required for delivery');
}

// Optional: test live endpoints
$liveUrl = $argv[1] ?? null;
if ($liveUrl !== null) {
    $liveUrl = rtrim($liveUrl, '/');
    echo "\n--- Live Endpoint Tests ---\n";

    $username = $env['USERNAME'] ?? 'unknown';

    // WebFinger
    $wfUrl = "{$liveUrl}/.well-known/webfinger?resource=acct:{$username}@" . parse_url($liveUrl, PHP_URL_HOST);
    $wfResponse = httpGet($wfUrl);
    if ($wfResponse !== null && isset($wfResponse['subject'])) {
        pass("WebFinger responds with subject: {$wfResponse['subject']}");
    } else {
        fail("WebFinger endpoint failed at: {$wfUrl}");
    }

    // Actor
    $actorUrl = "{$liveUrl}/{$username}";
    $actorResponse = httpGet($actorUrl, 'application/activity+json');
    if ($actorResponse !== null && ($actorResponse['type'] ?? '') === 'Application') {
        pass("Actor endpoint responds with type: Application");
    } else {
        fail("Actor endpoint failed at: {$actorUrl}");
    }

    // Outbox
    $outboxUrl = "{$liveUrl}/outbox";
    $outboxResponse = httpGet($outboxUrl, 'application/activity+json');
    if ($outboxResponse !== null && ($outboxResponse['type'] ?? '') === 'OrderedCollection') {
        pass("Outbox responds with " . ($outboxResponse['totalItems'] ?? 0) . " items");
    } else {
        fail("Outbox endpoint failed at: {$outboxUrl}");
    }
}

// Summary
echo "\n=== Results ===\n";
echo "Passed:   {$passed}\n";
echo "Failed:   {$failed}\n";
echo "Warnings: {$warnings}\n";

if ($failed > 0) {
    echo "\nFix the failures above before deploying.\n";
    exit(1);
}

if ($warnings > 0) {
    echo "\nWarnings are non-critical but should be reviewed.\n";
}

echo "\nAll checks passed!\n";
exit(0);

// --- Helper functions ---

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo "  [OK]   {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "  [FAIL] {$msg}\n";
}

function warn(string $msg): void
{
    global $warnings;
    $warnings++;
    echo "  [WARN] {$msg}\n";
}

function parseEnv(string $path): array
{
    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $values[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
    }
    return $values;
}

function httpGet(string $url, string $accept = 'application/json'): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Accept: {$accept}",
            'User-Agent: Fedibots-Verify/0.1.0',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }
    return json_decode($response, true);
}
