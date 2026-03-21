<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $envPath;

    protected function tearDown(): void
    {
        if (isset($this->envPath) && file_exists($this->envPath)) {
            unlink($this->envPath);
        }

        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }

    public function testBaseUrlPrefersConfiguredCanonicalUrl(): void
    {
        $this->envPath = sys_get_temp_dir() . '/fedibots_config_' . uniqid() . '.env';
        file_put_contents($this->envPath, <<<ENV
BASE_URL=https://bot.example.com
USERNAME=testbot
ENV
        );

        $_SERVER['HTTP_HOST'] = 'internal.example.net';
        $_SERVER['HTTPS'] = 'off';

        $config = new Config($this->envPath);

        $this->assertSame('https://bot.example.com', $config->baseUrl());
        $this->assertSame('bot.example.com', $config->domain());
        $this->assertSame('https://bot.example.com/testbot', $config->actorUrl());
    }
}
