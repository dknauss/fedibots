<?php

declare(strict_types=1);

namespace Fedibots\Storage;

final class FlatFile implements StorageInterface
{
    private string $dataDir;
    private int $maxLogs;

    public function __construct(string $dataDir, int $maxLogs = 2048)
    {
        $this->dataDir = rtrim($dataDir, '/');
        $this->maxLogs = $maxLogs;
        $this->ensureDirectories();
    }

    public function saveFollower(string $actorUrl, array $data): void
    {
        $filename = $this->hashFilename($actorUrl);
        $data['actor_url'] = $actorUrl;
        $data['followed_at'] = $data['followed_at'] ?? date('c');
        $this->writeJson("followers/{$filename}.json", $data);
    }

    public function removeFollower(string $actorUrl): void
    {
        $filename = $this->hashFilename($actorUrl);
        $path = "{$this->dataDir}/followers/{$filename}.json";
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function getFollower(string $actorUrl): ?array
    {
        $filename = $this->hashFilename($actorUrl);
        return $this->readJson("followers/{$filename}.json");
    }

    public function getFollowers(): array
    {
        return $this->readAllJson('followers');
    }

    public function getFollowerCount(): int
    {
        $dir = "{$this->dataDir}/followers";
        if (!is_dir($dir)) {
            return 0;
        }
        return count(glob("{$dir}/*.json"));
    }

    public function savePost(string $id, array $data): void
    {
        $this->writeJson("posts/{$id}.json", $data);
    }

    public function getPost(string $id): ?array
    {
        return $this->readJson("posts/{$id}.json");
    }

    public function getRecentPosts(int $limit = 20): array
    {
        $posts = $this->readAllJson('posts');
        // Sort by published date descending
        usort($posts, fn($a, $b) => ($b['published'] ?? '') <=> ($a['published'] ?? ''));
        return array_slice($posts, 0, $limit);
    }

    public function saveInbox(string $id, array $data): void
    {
        $this->writeJson("inbox/{$id}.json", $data);
    }

    public function log(string $type, string $message, array $context = []): void
    {
        $logDir = "{$this->dataDir}/logs";
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = [
            'timestamp' => date('c'),
            'type'      => $type,
            'message'   => $message,
            'context'   => $context,
        ];

        $logFile = "{$logDir}/log.jsonl";
        file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        // Prune if over limit
        if (filesize($logFile) > 0) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (count($lines) > $this->maxLogs) {
                $lines = array_slice($lines, -$this->maxLogs);
                file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
            }
        }
    }

    private function hashFilename(string $url): string
    {
        return hash('sha256', $url);
    }

    private function writeJson(string $relativePath, array $data): void
    {
        $path = "{$this->dataDir}/{$relativePath}";
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function readJson(string $relativePath): ?array
    {
        $path = "{$this->dataDir}/{$relativePath}";
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function readAllJson(string $subdir): array
    {
        $dir = "{$this->dataDir}/{$subdir}";
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $items[] = $data;
            }
        }
        return $items;
    }

    private function ensureDirectories(): void
    {
        foreach (['followers', 'posts', 'inbox', 'logs'] as $dir) {
            $path = "{$this->dataDir}/{$dir}";
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
