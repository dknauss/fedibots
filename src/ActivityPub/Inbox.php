<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;
use Fedibots\Storage\StorageInterface;

final class Inbox
{
    public function __construct(
        private Config $config,
        private StorageInterface $storage,
        private Signature $signature,
    ) {
    }

    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $rawBody = file_get_contents('php://input');
        $activity = json_decode($rawBody, true);

        if (!is_array($activity) || !isset($activity['type'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid activity']);
            return;
        }

        // Verify HTTP signature
        $verifiedActor = $this->signature->verify();
        if ($verifiedActor === null) {
            $this->storage->log('inbox', 'Signature verification failed', [
                'type' => $activity['type'] ?? 'unknown',
                'actor' => $activity['actor'] ?? 'unknown',
            ]);
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }

        $this->storage->log('inbox', "Received {$activity['type']}", [
            'actor' => $verifiedActor,
            'id' => $activity['id'] ?? null,
        ]);

        match ($activity['type']) {
            'Follow'  => $this->handleFollow($activity, $verifiedActor),
            'Undo'    => $this->handleUndo($activity, $verifiedActor),
            'Create'  => $this->handleCreate($activity),
            'Like', 'Announce' => $this->handleEngagement($activity),
            'Delete', 'Update' => $this->acknowledge(),
            default   => $this->acknowledge(),
        };
    }

    private function handleFollow(array $activity, string $actorUrl): void
    {
        $actorData = $this->signature->fetchJson($actorUrl);

        $this->storage->saveFollower($actorUrl, [
            'actor_url'    => $actorUrl,
            'inbox'        => $actorData['inbox'] ?? null,
            'shared_inbox' => $actorData['endpoints']['sharedInbox'] ?? $actorData['inbox'] ?? null,
            'username'     => $actorData['preferredUsername'] ?? null,
            'display_name' => $actorData['name'] ?? null,
            'domain'       => parse_url($actorUrl, PHP_URL_HOST),
            'follow_id'    => $activity['id'] ?? null,
        ]);

        // Send Accept
        $this->sendAccept($activity, $actorUrl, $actorData);

        $this->storage->log('follow', "Accepted follow from {$actorUrl}");

        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'accepted']);
    }

    private function handleUndo(array $activity, string $actorUrl): void
    {
        $object = $activity['object'] ?? [];

        // Undo Follow
        if (is_array($object) && ($object['type'] ?? '') === 'Follow') {
            $this->storage->removeFollower($actorUrl);
            $this->storage->log('unfollow', "Removed follower {$actorUrl}");
        }

        // Undo Like or Announce — just acknowledge
        $this->acknowledge();
    }

    private function handleCreate(array $activity): void
    {
        // Store replies and mentions for engagement tracking (Phase 5)
        $id = $activity['id'] ?? uniqid('inbox-');
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $this->storage->saveInbox($safeId, [
            'received_at' => date('c'),
            'activity' => $activity,
        ]);
        $this->acknowledge();
    }

    private function handleEngagement(array $activity): void
    {
        // Store likes and boosts for engagement tracking (Phase 5)
        $id = $activity['id'] ?? uniqid('engagement-');
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
        $this->storage->saveInbox($safeId, [
            'received_at' => date('c'),
            'activity' => $activity,
        ]);
        $this->acknowledge();
    }

    private function sendAccept(array $followActivity, string $actorUrl, ?array $actorData): void
    {
        $baseUrl = $this->config->baseUrl();
        $myActorUrl = $this->config->actorUrl();

        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => "{$baseUrl}/accept/" . uniqid(),
            'type'     => 'Accept',
            'actor'    => $myActorUrl,
            'object'   => $followActivity,
        ];

        $body = json_encode($accept, JSON_UNESCAPED_SLASHES);
        $inbox = $actorData['inbox'] ?? "{$actorUrl}/inbox";

        $headers = $this->signature->sign($inbox, $body);
        $headers[] = 'Content-Length: ' . strlen($body);

        $ch = curl_init($inbox);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Fedibots/0.1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->storage->log('accept', "Sent Accept to {$inbox}", [
            'http_code' => $httpCode,
        ]);
    }

    private function acknowledge(): void
    {
        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'accepted']);
    }
}
