<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\Config;
use Fedibots\Http\Router;
use Fedibots\Storage\FlatFile;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private string $envPath;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->envPath = sys_get_temp_dir() . '/fedibots_router_' . uniqid() . '.env';
        $this->dataDir = sys_get_temp_dir() . '/fedibots_router_data_' . uniqid();

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
        $_GET = [];
    }

    public function testRouterServesPublishedPostNote(): void
    {
        $config = new Config($this->envPath);
        $storage = new FlatFile($this->dataDir);
        $storage->savePost('test-1', [
            'type' => 'Create',
            'object' => [
                'id' => 'https://bot.example.com/posts/test-1',
                'type' => 'Note',
                'content' => '<p>Hello</p>',
            ],
        ]);

        $_GET['path'] = 'posts/test-1';

        ob_start();
        (new Router($config, $storage))->dispatch();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);

        $this->assertSame('Note', $decoded['type']);
        $this->assertSame('https://bot.example.com/posts/test-1', $decoded['id']);
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
