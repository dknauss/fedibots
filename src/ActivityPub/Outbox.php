<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;
use Fedibots\Storage\StorageInterface;
use Fedibots\Content\Post;

final class Outbox
{
    public function __construct(
        private Config $config,
        private StorageInterface $storage,
        private Signature $signature,
        private Delivery $delivery,
    ) {
    }

    /**
     * Handle GET /outbox — serve the outbox collection.
     */
    public function collection(): void
    {
        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        $baseUrl = $this->config->baseUrl();
        $posts = $this->storage->getRecentPosts(20);

        $collection = [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => "{$baseUrl}/outbox",
            'type'       => 'OrderedCollection',
            'totalItems' => count($posts),
        ];

        if (!empty($posts)) {
            $collection['orderedItems'] = $posts;
        }

        echo json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Handle GET /posts/{id} — serve a published Note.
     */
    public function item(string $postId): void
    {
        $post = $this->storage->getPost($postId);
        if ($post === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Post not found']);
            return;
        }

        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        $note = ($post['type'] ?? null) === 'Create' && isset($post['object']) && is_array($post['object'])
            ? $post['object']
            : $post;

        echo json_encode($note, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Handle POST /action/send — create and broadcast a post.
     * Requires password authentication.
     */
    public function send(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // Authenticate
        $password = $_POST['password'] ?? $_SERVER['HTTP_X_PASSWORD'] ?? '';
        $storedHash = $this->config->get('PASSWORD');
        if ($storedHash === null || $storedHash === '' || !password_verify($password, $storedHash)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        // Build the post
        $content = $_POST['content'] ?? '';
        if ($content === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Content is required']);
            return;
        }

        $hashtags = [];
        if (!empty($_POST['hashtags'])) {
            $hashtags = array_map('trim', explode(',', $_POST['hashtags']));
        }

        $post = new Post(
            content: $content,
            contentWarning: !empty($_POST['cw']) ? $_POST['cw'] : null,
            hashtags: $hashtags,
            visibility: $_POST['visibility'] ?? 'public',
            language: $_POST['language'] ?? $this->config->get('LANGUAGE') ?? 'en',
        );

        $result = $this->createAndDeliver($post);

        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Create a post from a Post object and deliver to all followers.
     * Used by both the HTTP API and the CLI tool.
     */
    public function createAndDeliver(Post $post): array
    {
        $actorUrl = $this->config->actorUrl();
        $baseUrl = $this->config->baseUrl();
        $postId = $this->generateId();

        $createActivity = $post->toCreateActivity($actorUrl, $baseUrl, $postId);

        // Save the post
        $this->storage->savePost($postId, $createActivity);

        // Deliver to all followers
        $followers = $this->storage->getFollowers();
        $delivered = $this->delivery->broadcast($createActivity, $followers);

        $this->storage->log('post', "Published post {$postId}", [
            'followers_count' => count($followers),
            'delivered' => $delivered,
        ]);

        return [
            'status'    => 'published',
            'id'        => $postId,
            'url'       => "{$baseUrl}/posts/{$postId}",
            'delivered' => $delivered,
            'followers' => count($followers),
        ];
    }

    private function generateId(): string
    {
        return date('Y-m-d-His') . '-' . bin2hex(random_bytes(4));
    }
}
