<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\ActivityPub\Inbox;
use Fedibots\ActivityPub\Signature;
use Fedibots\Config;
use Fedibots\Storage\FlatFile;
use PHPUnit\Framework\TestCase;

final class InboxTest extends TestCase
{
    private string $envPath;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->envPath = sys_get_temp_dir() . '/fedibots_inbox_' . uniqid() . '.env';
        $this->dataDir = sys_get_temp_dir() . '/fedibots_inbox_data_' . uniqid();

        file_put_contents($this->envPath, <<<ENV
BASE_URL=https://bot.example.com
USERNAME=testbot
ENV
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->envPath)) {
            unlink($this->envPath);
        }

        $this->removeDir($this->dataDir);
        http_response_code(200);
    }

    public function testInvalidFollowTargetIsRejectedBeforePersistingFollower(): void
    {
        $config = new Config($this->envPath);
        $storage = new FlatFile($this->dataDir);
        $signature = new Signature($config);
        $inbox = new Inbox($config, $storage, $signature);

        $method = new \ReflectionMethod($inbox, 'handleFollow');

        ob_start();
        $method->invoke($inbox, [
            'type' => 'Follow',
            'actor' => 'https://remote.example/users/alice',
            'object' => 'https://bot.example.com/other-bot',
            'id' => 'https://remote.example/activities/follow-1',
        ], 'https://remote.example/users/alice');
        ob_end_clean();

        $this->assertSame(400, http_response_code());
        $this->assertSame(0, $storage->getFollowerCount());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

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
