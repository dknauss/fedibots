<?php

declare(strict_types=1);

namespace Fedibots\Tests\Unit;

use Fedibots\Content\Post;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
    public function testBasicPostCreation(): void
    {
        $post = new Post(content: 'Hello world');

        $this->assertSame('Hello world', $post->content);
        $this->assertNull($post->contentWarning);
        $this->assertSame([], $post->images);
        $this->assertSame([], $post->hashtags);
        $this->assertNull($post->inReplyTo);
        $this->assertSame('public', $post->visibility);
        $this->assertNull($post->language);
    }

    public function testPostWithAllFields(): void
    {
        $post = new Post(
            content: 'Security tip',
            contentWarning: 'Spoiler',
            images: [['path' => 'img.png', 'alt' => 'Alt text', 'mime' => 'image/png']],
            hashtags: ['WordPress', 'InfoSec'],
            inReplyTo: 'https://example.com/note/1',
            visibility: 'unlisted',
            language: 'en',
        );

        $this->assertSame('Security tip', $post->content);
        $this->assertSame('Spoiler', $post->contentWarning);
        $this->assertCount(1, $post->images);
        $this->assertSame(['WordPress', 'InfoSec'], $post->hashtags);
        $this->assertSame('https://example.com/note/1', $post->inReplyTo);
        $this->assertSame('unlisted', $post->visibility);
        $this->assertSame('en', $post->language);
    }

    public function testToNoteStructure(): void
    {
        $post = new Post(
            content: 'Test note',
            hashtags: ['Test'],
            language: 'en',
        );

        $note = $post->toNote(
            'https://bot.example.com/mybot',
            'https://bot.example.com',
            'test-123'
        );

        $this->assertSame('https://www.w3.org/ns/activitystreams', $note['@context']);
        $this->assertSame('https://bot.example.com/posts/test-123', $note['id']);
        $this->assertSame('Note', $note['type']);
        $this->assertSame('https://bot.example.com/mybot', $note['attributedTo']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $note['to']);
        $this->assertContains('https://bot.example.com/followers', $note['cc']);
        $this->assertArrayHasKey('published', $note);
        $this->assertArrayHasKey('contentMap', $note);
        $this->assertSame($note['content'], $note['contentMap']['en']);
    }

    public function testToNoteWithContentWarning(): void
    {
        $post = new Post(content: 'Sensitive', contentWarning: 'CW text');
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'cw-1');

        $this->assertSame('CW text', $note['summary']);
        $this->assertTrue($note['sensitive']);
    }

    public function testToNoteUnlistedVisibility(): void
    {
        $post = new Post(content: 'Unlisted', visibility: 'unlisted');
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'u-1');

        $this->assertContains('https://x.com/followers', $note['to']);
        $this->assertContains('https://www.w3.org/ns/activitystreams#Public', $note['cc']);
    }

    public function testToNoteDirectVisibility(): void
    {
        $post = new Post(content: 'DM', visibility: 'direct');
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'd-1');

        $this->assertSame([], $note['to']);
        $this->assertSame([], $note['cc']);
    }

    public function testToNoteHashtags(): void
    {
        $post = new Post(content: 'Tagged', hashtags: ['PHP', 'Security']);
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'h-1');

        $this->assertCount(2, $note['tag']);
        $this->assertSame('Hashtag', $note['tag'][0]['type']);
        $this->assertSame('#PHP', $note['tag'][0]['name']);
        $this->assertSame('https://x.com/tags/php', $note['tag'][0]['href']);
    }

    public function testToNoteImageAttachments(): void
    {
        $post = new Post(
            content: 'With image',
            images: [['path' => 'media/img.png', 'alt' => 'Screenshot', 'mime' => 'image/png']],
        );
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'i-1');

        $this->assertCount(1, $note['attachment']);
        $this->assertSame('Document', $note['attachment'][0]['type']);
        $this->assertSame('image/png', $note['attachment'][0]['mediaType']);
        $this->assertSame('https://x.com/media/img.png', $note['attachment'][0]['url']);
        $this->assertSame('Screenshot', $note['attachment'][0]['name']);
    }

    public function testToNoteAbsoluteImageUrl(): void
    {
        $post = new Post(
            content: 'Ext image',
            images: [['path' => 'https://cdn.example.com/img.jpg', 'alt' => 'CDN']],
        );
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'e-1');

        $this->assertSame('https://cdn.example.com/img.jpg', $note['attachment'][0]['url']);
    }

    public function testToCreateActivityWrapsNote(): void
    {
        $post = new Post(content: 'Create test');
        $activity = $post->toCreateActivity('https://x.com/bot', 'https://x.com', 'c-1');

        $this->assertSame('https://www.w3.org/ns/activitystreams', $activity['@context']);
        $this->assertSame('https://x.com/outbox/c-1', $activity['id']);
        $this->assertSame('Create', $activity['type']);
        $this->assertSame('https://x.com/bot', $activity['actor']);
        $this->assertSame('Note', $activity['object']['type']);
        $this->assertSame('https://x.com/posts/c-1', $activity['object']['id']);
    }

    public function testContentHtmlFormatting(): void
    {
        $post = new Post(content: "Line 1\nLine 2");
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'f-1');

        $this->assertStringContainsString('<br />', $note['content']);
        $this->assertStringStartsWith('<p>', $note['content']);
    }

    public function testContentHashtagAutoLink(): void
    {
        $post = new Post(content: 'Check #WordPress tips');
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'al-1');

        $this->assertStringContainsString('class="mention hashtag"', $note['content']);
        $this->assertStringContainsString('href="https://x.com/tags/wordpress"', $note['content']);
    }

    public function testContentHtmlEscaping(): void
    {
        $post = new Post(content: 'Use <script>alert("xss")</script>');
        $note = $post->toNote('https://x.com/bot', 'https://x.com', 'xss-1');

        $this->assertStringNotContainsString('<script>', $note['content']);
        $this->assertStringContainsString('&lt;script&gt;', $note['content']);
    }
}
