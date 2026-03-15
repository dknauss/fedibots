<?php

declare(strict_types=1);

namespace Fedibots\Content;

interface ContentProviderInterface
{
    /**
     * Generate the next post content.
     * Returns null if there is nothing to post.
     */
    public function generatePost(): ?Post;

    /**
     * Human-readable name of this content provider.
     */
    public function getName(): string;

    /**
     * Return metadata about the content source.
     */
    public function getStatus(): array;
}
