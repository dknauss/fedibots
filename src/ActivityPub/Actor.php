<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;

final class Actor
{
    public function __construct(private Config $config)
    {
    }

    public function handle(): void
    {
        header('Content-Type: application/activity+json');
        header('Access-Control-Allow-Origin: *');

        $baseUrl  = $this->config->baseUrl();
        $actorUrl = $this->config->actorUrl();
        $username = $this->config->getRequired('USERNAME');

        $actor = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id'                        => $actorUrl,
            'type'                      => 'Application',
            'preferredUsername'          => $username,
            'name'                      => $this->config->get('REALNAME') ?? $username,
            'summary'                   => $this->config->get('SUMMARY') ?? '',
            'url'                       => $actorUrl,
            'published'                 => $this->config->get('BIRTHDAY') ?? date('c'),
            'manuallyApprovesFollowers' => false,
            'discoverable'              => true,
            'indexable'                 => true,
            'inbox'                     => "{$baseUrl}/inbox",
            'outbox'                    => "{$baseUrl}/outbox",
            'following'                 => "{$baseUrl}/following",
            'followers'                 => "{$baseUrl}/followers",
            'publicKey' => [
                'id'           => "{$actorUrl}#main-key",
                'owner'        => $actorUrl,
                'publicKeyPem' => $this->config->getRequired('KEY_PUBLIC'),
            ],
        ];

        // Optional avatar
        $avatar = $this->config->get('AVATAR');
        if ($avatar !== null && $avatar !== '') {
            $avatarUrl = str_starts_with($avatar, 'http') ? $avatar : "{$baseUrl}/{$avatar}";
            $actor['icon'] = [
                'type'      => 'Image',
                'mediaType' => $this->guessMimeType($avatar),
                'url'       => $avatarUrl,
            ];
        }

        // Optional banner
        $banner = $this->config->get('BANNER');
        if ($banner !== null && $banner !== '') {
            $bannerUrl = str_starts_with($banner, 'http') ? $banner : "{$baseUrl}/{$banner}";
            $actor['image'] = [
                'type'      => 'Image',
                'mediaType' => $this->guessMimeType($banner),
                'url'       => $bannerUrl,
            ];
        }

        echo json_encode($actor, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'svg'          => 'image/svg+xml',
            default        => 'image/png',
        };
    }
}
