<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\Storage\FlatFile;
use PHPUnit\Framework\TestCase;

final class FlatFileStorageTest extends TestCase
{
    private string $dataDir;
    private FlatFile $storage;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/fedibots_test_' . uniqid();
        $this->storage = new FlatFile($this->dataDir, 10);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dataDir);
    }

    public function testDirectoriesCreated(): void
    {
        $this->assertDirectoryExists($this->dataDir . '/followers');
        $this->assertDirectoryExists($this->dataDir . '/posts');
        $this->assertDirectoryExists($this->dataDir . '/inbox');
        $this->assertDirectoryExists($this->dataDir . '/logs');
    }

    public function testSaveAndGetFollower(): void
    {
        $url = 'https://mastodon.social/users/alice';
        $this->storage->saveFollower($url, ['inbox' => 'https://mastodon.social/inbox']);

        $follower = $this->storage->getFollower($url);
        $this->assertNotNull($follower);
        $this->assertSame($url, $follower['actor_url']);
        $this->assertSame('https://mastodon.social/inbox', $follower['inbox']);
        $this->assertArrayHasKey('followed_at', $follower);
    }

    public function testRemoveFollower(): void
    {
        $url = 'https://mastodon.social/users/bob';
        $this->storage->saveFollower($url, []);
        $this->assertNotNull($this->storage->getFollower($url));

        $this->storage->removeFollower($url);
        $this->assertNull($this->storage->getFollower($url));
    }

    public function testGetFollowers(): void
    {
        $this->storage->saveFollower('https://a.com/1', ['inbox' => 'https://a.com/inbox']);
        $this->storage->saveFollower('https://b.com/2', ['inbox' => 'https://b.com/inbox']);

        $followers = $this->storage->getFollowers();
        $this->assertCount(2, $followers);
    }

    public function testGetFollowerCount(): void
    {
        $this->assertSame(0, $this->storage->getFollowerCount());
        $this->storage->saveFollower('https://a.com/1', []);
        $this->assertSame(1, $this->storage->getFollowerCount());
        $this->storage->saveFollower('https://b.com/2', []);
        $this->assertSame(2, $this->storage->getFollowerCount());
    }

    public function testSaveAndGetPost(): void
    {
        $this->storage->savePost('test-1', ['type' => 'Create', 'published' => '2026-01-01']);

        $post = $this->storage->getPost('test-1');
        $this->assertNotNull($post);
        $this->assertSame('Create', $post['type']);
    }

    public function testGetRecentPosts(): void
    {
        $this->storage->savePost('old', ['published' => '2026-01-01']);
        $this->storage->savePost('new', ['published' => '2026-03-01']);
        $this->storage->savePost('mid', ['published' => '2026-02-01']);

        $posts = $this->storage->getRecentPosts(2);
        $this->assertCount(2, $posts);
        $this->assertSame('2026-03-01', $posts[0]['published']);
        $this->assertSame('2026-02-01', $posts[1]['published']);
    }

    public function testLog(): void
    {
        $this->storage->log('test', 'Hello', ['key' => 'value']);

        $logFile = $this->dataDir . '/logs/log.jsonl';
        $this->assertFileExists($logFile);
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $entry = json_decode($lines[0], true);
        $this->assertSame('test', $entry['type']);
        $this->assertSame('Hello', $entry['message']);
    }

    public function testLogPruning(): void
    {
        // maxLogs is 10
        for ($i = 0; $i < 15; $i++) {
            $this->storage->log('test', "Message {$i}");
        }

        $logFile = $this->dataDir . '/logs/log.jsonl';
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertLessThanOrEqual(10, count($lines));
    }

    public function testGetNonExistentFollower(): void
    {
        $this->assertNull($this->storage->getFollower('https://nowhere.com/nobody'));
    }

    public function testGetNonExistentPost(): void
    {
        $this->assertNull($this->storage->getPost('nonexistent'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
