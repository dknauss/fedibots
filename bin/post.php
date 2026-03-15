#!/usr/bin/env php
<?php

/**
 * CLI tool to trigger a bot post.
 * Loads the content provider, generates a post, and broadcasts to followers.
 *
 * Usage: php bin/post.php
 *   Or:  php bin/post.php --dry-run  (preview without sending)
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);

require_once $rootDir . '/src/Config.php';
require_once $rootDir . '/src/Storage/StorageInterface.php';
require_once $rootDir . '/src/Storage/FlatFile.php';
require_once $rootDir . '/src/Content/ContentProviderInterface.php';
require_once $rootDir . '/src/Content/Post.php';
require_once $rootDir . '/src/ActivityPub/Signature.php';
require_once $rootDir . '/src/ActivityPub/Delivery.php';
require_once $rootDir . '/src/ActivityPub/Outbox.php';
require_once $rootDir . '/content/ContentProvider.php';

// Parse flags
$dryRun = in_array('--dry-run', $argv);

// Load config
$envPath = $rootDir . '/.env';
if (!file_exists($envPath)) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}

$config  = new Fedibots\Config($envPath);
$storage = new Fedibots\Storage\FlatFile(
    $rootDir . '/data',
    (int) ($config->get('MAX_LOGS') ?? 2048)
);

// Generate post from content provider
$provider = new ContentProvider();
$post = $provider->generatePost();

if ($post === null) {
    echo "No post to send (content provider returned null).\n";
    exit(0);
}

echo "Post content:\n";
echo "---\n";
echo $post->content . "\n";
if (!empty($post->hashtags)) {
    echo "Hashtags: #" . implode(' #', $post->hashtags) . "\n";
}
if ($post->contentWarning !== null) {
    echo "CW: {$post->contentWarning}\n";
}
echo "Visibility: {$post->visibility}\n";
echo "---\n\n";

if ($dryRun) {
    echo "[Dry run] Post not sent.\n";

    // Show the Note JSON
    $actorUrl = $config->actorUrl();
    $baseUrl  = $config->baseUrl();
    $note = $post->toNote($actorUrl, $baseUrl, 'preview-' . date('Y-m-d'));
    echo "\nNote JSON:\n";
    echo json_encode($note, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Create and deliver
$signature = new Fedibots\ActivityPub\Signature($config);
$delivery  = new Fedibots\ActivityPub\Delivery($config, $storage, $signature);
$outbox    = new Fedibots\ActivityPub\Outbox($config, $storage, $signature, $delivery);

$result = $outbox->createAndDeliver($post);

echo "Published: {$result['url']}\n";
echo "Delivered to {$result['delivered']}/{$result['followers']} follower inboxes.\n";
