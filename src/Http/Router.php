<?php

declare(strict_types=1);

namespace Fedibots\Http;

use Fedibots\Config;
use Fedibots\Storage\StorageInterface;
use Fedibots\ActivityPub\WebFinger;
use Fedibots\ActivityPub\Actor;
use Fedibots\ActivityPub\NodeInfo;
use Fedibots\ActivityPub\Collections;
use Fedibots\ActivityPub\Inbox;
use Fedibots\ActivityPub\Outbox;
use Fedibots\ActivityPub\Signature;
use Fedibots\ActivityPub\Delivery;

final class Router
{
    public function __construct(
        private Config $config,
        private StorageInterface $storage,
    ) {
    }

    public function dispatch(): void
    {
        $path = trim($_GET['path'] ?? '', '/');

        match ($path) {
            '.well-known/webfinger' => (new WebFinger($this->config))->handle(),
            '.well-known/nodeinfo'  => (new NodeInfo($this->config))->discovery(),
            'nodeinfo/2.1'          => (new NodeInfo($this->config))->schema(),
            'inbox'                 => (new Inbox($this->config, $this->storage, new Signature($this->config)))->handle(),
            'outbox'                => $this->outbox()->collection(),
            'action/send'           => $this->outbox()->send(),
            'followers'             => (new Collections($this->config, $this->storage))->followers(),
            'following'             => (new Collections($this->config, $this->storage))->following(),
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

    private function outbox(): Outbox
    {
        $sig = new Signature($this->config);
        $delivery = new Delivery($this->config, $this->storage, $sig);
        return new Outbox($this->config, $this->storage, $sig, $delivery);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
}
