#!/usr/bin/env php
<?php

/**
 * Generate an RSA keypair for ActivityPub HTTP signatures.
 * Outputs keys as single-line strings (newlines escaped as \n) for .env.
 *
 * Usage: php bin/keygen.php
 */

declare(strict_types=1);

$config = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$key = openssl_pkey_new($config);
if ($key === false) {
    fwrite(STDERR, "Error: Failed to generate RSA keypair. Is OpenSSL installed?\n");
    exit(1);
}

openssl_pkey_export($key, $privateKey);
$details = openssl_pkey_get_details($key);
$publicKey = $details['key'];

$privateEscaped = str_replace("\n", '\\n', trim($privateKey));
$publicEscaped = str_replace("\n", '\\n', trim($publicKey));

echo "# Paste these into your .env file:\n\n";
echo "KEY_PRIVATE={$privateEscaped}\n\n";
echo "KEY_PUBLIC={$publicEscaped}\n";
