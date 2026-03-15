<?php

declare(strict_types=1);

namespace Fedibots\Storage;

interface StorageInterface
{
    public function saveFollower(string $actorUrl, array $data): void;
    public function removeFollower(string $actorUrl): void;
    public function getFollower(string $actorUrl): ?array;
    public function getFollowers(): array;
    public function getFollowerCount(): int;

    public function savePost(string $id, array $data): void;
    public function getPost(string $id): ?array;
    public function getRecentPosts(int $limit = 20): array;

    public function saveInbox(string $id, array $data): void;

    public function log(string $type, string $message, array $context = []): void;
}
