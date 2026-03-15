<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;
use Fedibots\Storage\StorageInterface;

final class Collections
{
    public function __construct(
        private Config $config,
        private StorageInterface $storage,
    ) {
    }

    public function followers(): void
    {
        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        $baseUrl = $this->config->baseUrl();
        $count = $this->storage->getFollowerCount();

        $collection = [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => "{$baseUrl}/followers",
            'type'       => 'OrderedCollection',
            'totalItems' => $count,
        ];

        // Add paginated items if followers exist
        if ($count > 0) {
            $followers = $this->storage->getFollowers();
            $collection['orderedItems'] = array_map(
                fn($f) => $f['actor_url'] ?? '',
                $followers
            );
        }

        echo json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function following(): void
    {
        $this->emptyCollection('following');
    }

    public function outbox(): void
    {
        $this->emptyCollection('outbox');
    }

    private function emptyCollection(string $type): void
    {
        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        echo json_encode([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $this->config->baseUrl() . "/{$type}",
            'type'       => 'OrderedCollection',
            'totalItems' => 0,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
