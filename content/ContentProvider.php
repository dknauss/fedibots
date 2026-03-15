<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Content/ContentProviderInterface.php';
require_once __DIR__ . '/../src/Content/Post.php';

use Fedibots\Content\ContentProviderInterface;
use Fedibots\Content\Post;

/**
 * Default content provider — edit this file to customize your bot's posts.
 *
 * This placeholder posts a simple greeting. Replace the generatePost()
 * method with your own content generation logic. See content/examples/
 * for reference implementations.
 */
final class ContentProvider implements ContentProviderInterface
{
    public function generatePost(): ?Post
    {
        return new Post(
            content: 'Hello from Fedibots! This is a test post. Edit content/ContentProvider.php to customize.',
            hashtags: ['Fedibots', 'ActivityPub', 'Fediverse'],
            language: 'en',
        );
    }

    public function getName(): string
    {
        return 'Default Bot';
    }

    public function getStatus(): array
    {
        return ['ready' => true];
    }
}
