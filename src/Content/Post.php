<?php

declare(strict_types=1);

namespace Fedibots\Content;

final class Post
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $contentWarning = null,
        public readonly array $images = [],       // [{path, alt, mime}]
        public readonly array $hashtags = [],
        public readonly ?string $inReplyTo = null,
        public readonly string $visibility = 'public', // public|unlisted|direct
        public readonly ?string $language = null,
    ) {
    }

    /**
     * Convert to an ActivityPub Note object.
     */
    public function toNote(string $actorUrl, string $baseUrl, string $postId): array
    {
        $noteUrl = "{$baseUrl}/posts/{$postId}";
        $published = gmdate('Y-m-d\TH:i:s\Z');

        // Build addressees based on visibility
        [$to, $cc] = match ($this->visibility) {
            'public'   => [
                ['https://www.w3.org/ns/activitystreams#Public'],
                ["{$baseUrl}/followers"],
            ],
            'unlisted' => [
                ["{$baseUrl}/followers"],
                ['https://www.w3.org/ns/activitystreams#Public'],
            ],
            'direct'   => [[], []],
            default    => [
                ['https://www.w3.org/ns/activitystreams#Public'],
                ["{$baseUrl}/followers"],
            ],
        };

        // Auto-link hashtags in content
        $htmlContent = $this->formatContent($baseUrl);

        $note = [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => $noteUrl,
            'type'      => 'Note',
            'published' => $published,
            'attributedTo' => $actorUrl,
            'content'   => $htmlContent,
            'to'        => $to,
            'cc'        => $cc,
            'url'       => $noteUrl,
        ];

        if ($this->contentWarning !== null) {
            $note['summary'] = $this->contentWarning;
            $note['sensitive'] = true;
        }

        if ($this->inReplyTo !== null) {
            $note['inReplyTo'] = $this->inReplyTo;
        }

        if ($this->language !== null) {
            $note['contentMap'] = [$this->language => $htmlContent];
        }

        if (!empty($this->hashtags)) {
            $note['tag'] = array_map(fn(string $tag) => [
                'type' => 'Hashtag',
                'href' => "{$baseUrl}/tags/" . strtolower($tag),
                'name' => "#{$tag}",
            ], $this->hashtags);
        }

        if (!empty($this->images)) {
            $note['attachment'] = array_map(fn(array $img) => [
                'type'      => 'Document',
                'mediaType' => $img['mime'] ?? 'image/png',
                'url'       => str_starts_with($img['path'], 'http')
                    ? $img['path']
                    : "{$baseUrl}/{$img['path']}",
                'name'      => $img['alt'] ?? '',
            ], $this->images);
        }

        return $note;
    }

    /**
     * Wrap a Note in a Create activity.
     */
    public function toCreateActivity(string $actorUrl, string $baseUrl, string $postId): array
    {
        $note = $this->toNote($actorUrl, $baseUrl, $postId);

        return [
            '@context'  => 'https://www.w3.org/ns/activitystreams',
            'id'        => "{$baseUrl}/outbox/{$postId}",
            'type'      => 'Create',
            'actor'     => $actorUrl,
            'published' => $note['published'],
            'to'        => $note['to'],
            'cc'        => $note['cc'],
            'object'    => $note,
        ];
    }

    /**
     * Format plain text content as HTML with auto-linked hashtags.
     */
    private function formatContent(string $baseUrl): string
    {
        $text = htmlspecialchars($this->content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = nl2br($text);

        // Auto-link hashtags
        $text = preg_replace_callback(
            '/#(\w+)/u',
            fn($m) => '<a href="' . $baseUrl . '/tags/' . strtolower($m[1])
                . '" class="mention hashtag" rel="tag">#<span>'
                . $m[1] . '</span></a>',
            $text
        );

        // Auto-link URLs
        $text = preg_replace_callback(
            '/(?<!["\'>])(https?:\/\/[^\s<]+)/i',
            fn($m) => '<a href="' . $m[1] . '" rel="nofollow noopener noreferrer" target="_blank">'
                . $m[1] . '</a>',
            $text
        );

        return '<p>' . $text . '</p>';
    }
}
