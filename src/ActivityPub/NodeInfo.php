<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;

final class NodeInfo
{
    private const VERSION = '0.1.0';

    public function __construct(private Config $config)
    {
    }

    /**
     * /.well-known/nodeinfo — discovery document
     */
    public function discovery(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        echo json_encode([
            'links' => [
                [
                    'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'type' => 'application/json',
                    'href' => $this->config->baseUrl() . '/nodeinfo/2.1',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * /nodeinfo/2.1 — full server metadata
     */
    public function schema(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        // Count posts if directory exists
        $postsDir = dirname(__DIR__, 2) . '/data/posts';
        $postCount = 0;
        if (is_dir($postsDir)) {
            $postCount = count(glob("{$postsDir}/*.json"));
        }

        echo json_encode([
            'version'  => '2.1',
            'software' => [
                'name'       => 'fedibots',
                'version'    => self::VERSION,
                'repository' => 'https://github.com/dknauss/fedibots',
            ],
            'protocols'         => ['activitypub'],
            'services'          => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => false,
            'usage' => [
                'users'      => ['total' => 1],
                'localPosts' => $postCount,
            ],
            'metadata' => [
                'nodeName'        => $this->config->get('REALNAME') ?? 'Fedibots Instance',
                'nodeDescription' => $this->config->get('SUMMARY') ?? 'A minimal ActivityPub bot',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
