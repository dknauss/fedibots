<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the WP Security Tips ContentProvider.
 */
final class ContentProviderTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/fedibots_cp_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        // Copy tips.json
        $tipsSource = __DIR__ . '/../../content/examples/wp-security/tips.json';
        if (file_exists($tipsSource)) {
            copy($tipsSource, $this->testDir . '/tips.json');
        } else {
            // Create minimal test tips
            file_put_contents($this->testDir . '/tips.json', json_encode([
                ['id' => '1.1', 'title' => 'Test TLS', 'section' => 'Web Server', 'level' => 1, 'tip' => 'Enable TLS.', 'hashtags' => ['WordPress', 'TLS']],
                ['id' => '1.2', 'title' => 'Test Headers', 'section' => 'Web Server', 'level' => 1, 'tip' => 'Set headers.', 'hashtags' => ['WordPress', 'WebSecurity']],
                ['id' => '2.1', 'title' => 'Test PHP', 'section' => 'PHP Config', 'level' => 2, 'tip' => 'Disable expose_php.', 'hashtags' => ['WordPress', 'PHP']],
            ]));
        }

        // Require the ContentProvider
        require_once __DIR__ . '/../../content/examples/wp-security/ContentProvider.php';
    }

    protected function tearDown(): void
    {
        $stateFile = $this->testDir . '/state.json';
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        if (file_exists($this->testDir . '/tips.json')) {
            unlink($this->testDir . '/tips.json');
        }
        rmdir($this->testDir);
    }

    public function testGeneratePostReturnsPost(): void
    {
        $cp = new \ContentProvider($this->testDir);
        $post = $cp->generatePost();

        $this->assertNotNull($post);
        $this->assertStringContainsString('WP Security Tip', $post->content);
        $this->assertSame('en', $post->language);
        $this->assertNotEmpty($post->hashtags);
    }

    public function testPostsAreSequential(): void
    {
        $cp = new \ContentProvider($this->testDir);

        $post1 = $cp->generatePost();
        $post2 = $cp->generatePost();

        $this->assertStringContainsString('#1.1', $post1->content);
        $this->assertStringContainsString('#1.2', $post2->content);
    }

    public function testStateTracksProgress(): void
    {
        $cp = new \ContentProvider($this->testDir);
        $cp->generatePost();

        $status = $cp->getStatus();
        $this->assertSame(1, $status['next_index']);
        $this->assertSame(1, $status['total_posted']);
        $this->assertSame('1.1', $status['last_posted_id']);
        $this->assertNotNull($status['last_posted_at']);
    }

    public function testCyclesBackToStart(): void
    {
        $tips = json_decode(file_get_contents($this->testDir . '/tips.json'), true);
        $totalTips = count($tips);

        $cp = new \ContentProvider($this->testDir);

        // Exhaust all tips
        for ($i = 0; $i < $totalTips; $i++) {
            $cp->generatePost();
        }

        // Next post should cycle back to first tip
        $post = $cp->generatePost();
        $this->assertStringContainsString('#1.1', $post->content);
    }

    public function testGetName(): void
    {
        $cp = new \ContentProvider($this->testDir);
        $this->assertSame('WordPress Security Tips', $cp->getName());
    }

    public function testPostFitsIn500Chars(): void
    {
        $cp = new \ContentProvider($this->testDir);
        $tips = json_decode(file_get_contents($this->testDir . '/tips.json'), true);

        for ($i = 0; $i < count($tips); $i++) {
            $post = $cp->generatePost();
            $hashtagLine = '#' . implode(' #', $post->hashtags);
            $total = strlen($post->content) + 1 + strlen($hashtagLine);
            $this->assertLessThanOrEqual(500, $total, "Tip index {$i} exceeds 500 chars ({$total})");
        }
    }

    public function testEmptyTipsReturnsNull(): void
    {
        file_put_contents($this->testDir . '/tips.json', '[]');
        $cp = new \ContentProvider($this->testDir);
        $this->assertNull($cp->generatePost());
    }
}
