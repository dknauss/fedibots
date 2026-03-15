<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;

final class WebFinger
{
    public function __construct(private Config $config)
    {
    }

    public function handle(): void
    {
        $resource = $_GET['resource'] ?? '';
        $username = $this->config->getRequired('USERNAME');
        $domain = $this->config->domain();
        $expected = "acct:{$username}@{$domain}";

        if ($resource !== $expected) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown resource']);
            return;
        }

        header('Content-Type: application/jrd+json');
        header('Access-Control-Allow-Origin: *');

        echo json_encode([
            'subject' => $expected,
            'links' => [
                [
                    'rel'  => 'self',
                    'type' => 'application/activity+json',
                    'href' => $this->config->actorUrl(),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
