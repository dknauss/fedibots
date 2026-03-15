<?php

declare(strict_types=1);

namespace Fedibots\Http;

use Fedibots\Config;
use Fedibots\ActivityPub\WebFinger;
use Fedibots\ActivityPub\Actor;
use Fedibots\ActivityPub\NodeInfo;
use Fedibots\ActivityPub\Collections;

final class Router
{
    public function __construct(private Config $config)
    {
    }

    public function dispatch(): void
    {
        $path = trim($_GET['path'] ?? '', '/');

        match ($path) {
            '.well-known/webfinger' => (new WebFinger($this->config))->handle(),
            '.well-known/nodeinfo'  => (new NodeInfo($this->config))->discovery(),
            'nodeinfo/2.1'          => (new NodeInfo($this->config))->schema(),
            'inbox'                 => $this->stubInbox(),
            'outbox'                => (new Collections($this->config))->outbox(),
            'followers'             => (new Collections($this->config))->followers(),
            'following'             => (new Collections($this->config))->following(),
            default                 => $this->matchDynamic($path),
        };
    }

    private function matchDynamic(string $path): void
    {
        $username = $this->config->getRequired('USERNAME');

        // Match /{username} or /@{username}
        if ($path === $username || $path === '@' . $username) {
            (new Actor($this->config))->handle();
            return;
        }

        $this->notFound();
    }

    private function stubInbox(): void
    {
        // Phase 2 will implement full inbox handling
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(202);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'accepted']);
            return;
        }
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not allowed']);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
}
