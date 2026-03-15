<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;

final class Collections
{
    public function __construct(private Config $config)
    {
    }

    public function followers(): void
    {
        $this->respond('followers');
    }

    public function following(): void
    {
        $this->respond('following');
    }

    public function outbox(): void
    {
        $this->respond('outbox');
    }

    private function respond(string $type): void
    {
        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        $baseUrl = $this->config->baseUrl();

        // Count items from data directory if it exists
        $dataDir = dirname(__DIR__, 2) . "/data/{$type}";
        $count = 0;
        if (is_dir($dataDir)) {
            $count = count(glob("{$dataDir}/*.json"));
        }

        echo json_encode([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => "{$baseUrl}/{$type}",
            'type'       => 'OrderedCollection',
            'totalItems' => $count,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
