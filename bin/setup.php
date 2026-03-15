#!/usr/bin/env php
<?php

/**
 * Interactive setup wizard for a new Fedibots instance.
 * Generates RSA keys, creates .env, and sets up data directories.
 *
 * Usage: php bin/setup.php
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$envPath = $rootDir . '/.env';

echo "=== Fedibots Setup ===\n\n";

if (file_exists($envPath)) {
    echo ".env file already exists at: {$envPath}\n";
    echo "Overwrite? [y/N] ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        echo "Setup cancelled.\n";
        exit(0);
    }
}

// Gather bot identity
$username = prompt('Bot username (alphanumeric, no spaces)', 'mybot');
$realname = prompt('Display name', ucfirst($username));
$summary  = prompt('Bio (short, HTML links allowed)', "A fediverse bot powered by Fedibots.");
$language = prompt('Language code', 'en');

// Generate posting password
$password = prompt('Posting password (used for API authentication)');
if ($password === '') {
    $password = bin2hex(random_bytes(16));
    echo "  Generated random password: {$password}\n";
}
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// Generate RSA keypair
echo "\nGenerating RSA keypair...\n";
$keyConfig = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$key = openssl_pkey_new($keyConfig);
if ($key === false) {
    fwrite(STDERR, "Error: Failed to generate RSA keypair. Is OpenSSL installed?\n");
    exit(1);
}
openssl_pkey_export($key, $privateKey);
$details = openssl_pkey_get_details($key);
$publicKey = $details['key'];

$privateEscaped = str_replace("\n", '\\n', trim($privateKey));
$publicEscaped  = str_replace("\n", '\\n', trim($publicKey));
echo "RSA keypair generated.\n";

// Write .env
$birthday = date('Y-m-d\TH:i:s\Z');

$env = <<<ENV
# Bot identity
USERNAME={$username}
REALNAME={$realname}
SUMMARY={$summary}
BIRTHDAY={$birthday}
LANGUAGE={$language}

# Profile images (relative paths to media/ directory, or full URLs)
AVATAR=
BANNER=

# Posting password (bcrypt hash)
PASSWORD={$passwordHash}

# RSA keys (regenerate with: php bin/keygen.php)
KEY_PRIVATE={$privateEscaped}
KEY_PUBLIC={$publicEscaped}

# Logging
MAX_LOGS=2048
ENV;

file_put_contents($envPath, $env . "\n");
echo "\n.env created at: {$envPath}\n";

// Ensure data directories exist
foreach (['data/followers', 'data/posts', 'data/inbox', 'data/logs', 'media'] as $dir) {
    $path = $rootDir . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}
echo "Data directories created.\n";

echo "\n=== Setup Complete ===\n";
echo "Username:  @{$username}\n";
echo "Password:  {$password}\n";
echo "\nNext steps:\n";
echo "  1. Deploy this directory to a web server with a subdomain\n";
echo "  2. Point the subdomain to this directory\n";
echo "  3. Run: php bin/verify.php\n";
echo "  4. Edit content/ContentProvider.php with your bot's content\n";
echo "  5. Set up a cron job: 0 14 * * * php /path/to/bin/post.php\n";

function prompt(string $question, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo "{$question}{$suffix}: ";
    $input = trim(fgets(STDIN));
    return $input !== '' ? $input : $default;
}
