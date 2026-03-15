<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/Content/ContentProviderInterface.php';
require_once __DIR__ . '/../../../src/Content/Post.php';

use Fedibots\Content\ContentProviderInterface;
use Fedibots\Content\Post;

/**
 * WordPress Security Tips bot.
 *
 * Posts daily security tips extracted from the WordPress Security Benchmark.
 * Cycles through all tips sequentially, then loops. Tracks state in state.json.
 *
 * To use: copy this directory's files into your bot's content/ directory:
 *   cp content/examples/wp-security/ContentProvider.php content/ContentProvider.php
 *   cp content/examples/wp-security/tips.json content/tips.json
 */
final class ContentProvider implements ContentProviderInterface
{
    private string $tipsFile;
    private string $stateFile;

    public function __construct(?string $dataDir = null)
    {
        $dir = $dataDir ?? __DIR__;
        $this->tipsFile  = $dir . '/tips.json';
        $this->stateFile = $dir . '/state.json';
    }

    public function generatePost(): ?Post
    {
        $tips = $this->loadTips();
        if (empty($tips)) {
            return null;
        }

        $state = $this->loadState();
        $index = $state['next_index'] ?? 0;

        // Wrap around if we've exhausted all tips
        if ($index >= count($tips)) {
            $index = 0;
        }

        $tip = $tips[$index];

        // Update state
        $state['next_index'] = $index + 1;
        $state['last_posted_id'] = $tip['id'];
        $state['last_posted_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $state['total_posted'] = ($state['total_posted'] ?? 0) + 1;
        $this->saveState($state);

        return $this->formatTip($tip);
    }

    public function getName(): string
    {
        return 'WordPress Security Tips';
    }

    public function getStatus(): array
    {
        $tips = $this->loadTips();
        $state = $this->loadState();

        return [
            'total_tips'     => count($tips),
            'next_index'     => $state['next_index'] ?? 0,
            'total_posted'   => $state['total_posted'] ?? 0,
            'last_posted_id' => $state['last_posted_id'] ?? null,
            'last_posted_at' => $state['last_posted_at'] ?? null,
        ];
    }

    /**
     * Format a tip as a fediverse post.
     *
     * Target format (within 500 chars):
     *   WP Security Tip #1.1: Ensure TLS 1.2+ is enforced
     *
     *   [tip text]
     *
     *   Level 1 | Web Server Config
     *   #WordPress #InfoSec #TLS
     */
    private function formatTip(array $tip): Post
    {
        $title = "WP Security Tip #{$tip['id']}: {$tip['title']}";
        $footer = "Level {$tip['level']} | {$tip['section']}";

        // Build content without hashtags (they go in the Post object)
        $content = "{$title}\n\n{$tip['tip']}\n\n{$footer}";

        // Safety check: if content + hashtags would exceed 500, truncate tip text
        $hashtagLine = '#' . implode(' #', $tip['hashtags']);
        $totalLen = strlen($content) + 1 + strlen($hashtagLine);
        if ($totalLen > 500) {
            $available = 500 - strlen($title) - strlen($footer) - strlen($hashtagLine) - 6; // newlines
            $tipText = substr($tip['tip'], 0, $available - 3) . '...';
            $content = "{$title}\n\n{$tipText}\n\n{$footer}";
        }

        return new Post(
            content: $content,
            hashtags: $tip['hashtags'],
            language: 'en',
        );
    }

    private function loadTips(): array
    {
        if (!file_exists($this->tipsFile)) {
            return [];
        }
        return json_decode(file_get_contents($this->tipsFile), true) ?? [];
    }

    private function loadState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }
        return json_decode(file_get_contents($this->stateFile), true) ?? [];
    }

    private function saveState(array $state): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
    }
}
