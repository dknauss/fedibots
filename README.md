# Fedibots

A PHP framework for creating write-only ActivityPub bots on the fediverse.

Inspired by [Terence Eden's ActivityBot](https://gitlab.com/edent/activity-bot/), fedibots is a clean-room implementation that provides a reusable template for spinning up fediverse bots. Each bot is a clone of this repo with its own configuration and content provider.

## Requirements

- PHP 8.2+ with OpenSSL and cURL extensions
- A web server (Apache or nginx) with HTTPS
- A (sub)domain pointing to the bot's directory

No database. No Composer dependencies at runtime. No containers.

## Quick Start

```bash
# Clone the repo as your bot
git clone https://github.com/dknauss/fedibots.git my-bot
cd my-bot

# Run interactive setup (generates keys, creates .env)
php bin/setup.php

# Edit the content provider with your bot's logic
nano content/ContentProvider.php

# Test a dry-run post
php bin/post.php --dry-run

# Deploy to a web server, then verify
php bin/verify.php https://my-bot.example.com
```

Set `BASE_URL` in `.env` to the bot's final canonical HTTPS URL. Scheduled CLI posts and HTTP signatures use that value.

## How It Works

Each bot is a standalone ActivityPub server. Other fediverse users can search for `@botname@yourdomain.com`, follow it, and see posts in their timeline.

**Architecture:**

```
content/ContentProvider.php   <-- Your content logic
        |
        v
bin/post.php (cron)  -->  Outbox  -->  Delivery (multi-cURL)
                                          |
                                          v
                                    Followers' inboxes
```

**Endpoints served by `index.php`:**

| Path | Purpose |
|------|---------|
| `/.well-known/webfinger` | Account discovery |
| `/.well-known/nodeinfo` | Server metadata |
| `/{username}` | Actor profile (JSON-LD) |
| `/inbox` | Receive follow/unfollow |
| `/outbox` | Published posts collection |
| `/posts/{id}` | Individual published Note |
| `/followers` | Follower list |
| `/action/send` | Create & broadcast a post (POST, password-protected) |

## Creating Your Bot

### 1. Content Provider

Edit `content/ContentProvider.php` to implement the `ContentProviderInterface`:

```php
<?php
use Fedibots\Content\ContentProviderInterface;
use Fedibots\Content\Post;

final class ContentProvider implements ContentProviderInterface
{
    public function generatePost(): ?Post
    {
        return new Post(
            content: 'Your bot post content here.',
            hashtags: ['Bot', 'Fediverse'],
            language: 'en',
        );
    }

    public function getName(): string { return 'My Bot'; }
    public function getStatus(): array { return ['ready' => true]; }
}
```

Return `null` from `generatePost()` to skip posting (e.g., if content is exhausted for the day).

### 2. Schedule Posts

Add a cron job to post on a schedule:

```bash
# Post daily at 2 PM UTC
0 14 * * * /usr/bin/php /path/to/my-bot/bin/post.php

# Or via the HTTP API
0 14 * * * curl -X POST -d "content=Hello&password=yourpass" https://my-bot.example.com/action/send
```

### 3. Web Server Config

**Apache** — the included `.htaccess` handles rewrites. Ensure `mod_rewrite` is enabled.

**nginx:**

```nginx
server {
    listen 443 ssl;
    server_name my-bot.example.com;
    root /var/www/my-bot;
    index index.php;

    location / {
        rewrite ^/(.*)$ /index.php?path=$1 last;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block access to sensitive files
    location ~ /\.(env|git|htaccess) { deny all; }
    location ~ ^/(data|content|src|bin|tests|vendor)/ { deny all; }
}
```

## Example: WordPress Security Tips Bot

The `content/examples/wp-security/` directory contains a complete reference bot that posts daily security tips from the [WordPress Security Benchmark](https://github.com/dknauss/wp-security-benchmark).

```bash
# Import tips from the Benchmark
php bin/import-tips.php /path/to/WordPress-Security-Benchmark.md

# Copy the example into place
cp content/examples/wp-security/ContentProvider.php content/ContentProvider.php
cp content/examples/wp-security/tips.json content/tips.json
```

50 tips across 13 security domains. At one post per day, that's ~7 weeks before cycling.

## CLI Tools

| Command | Purpose |
|---------|---------|
| `php bin/setup.php` | Interactive setup wizard |
| `php bin/keygen.php` | Generate RSA keypair |
| `php bin/post.php` | Trigger a post (use with cron) |
| `php bin/post.php --dry-run` | Preview post without sending |
| `php bin/verify.php` | Check local deployment health |
| `php bin/verify.php https://...` | Also test live endpoints |
| `php bin/import-tips.php FILE` | Parse Benchmark markdown into tips.json |

## Development

```bash
composer install --dev
vendor/bin/phpunit
```

36 tests, 210 assertions covering Post, Signature, FlatFile storage, and ContentProvider.

## Project Structure

```
fedibots/
├── index.php              # Front controller
├── .htaccess              # Apache rewrites
├── .env.example           # Config template
├── src/
│   ├── Config.php
│   ├── Http/              # Router, Request
│   ├── ActivityPub/       # WebFinger, Actor, Inbox, Outbox, Signature, Delivery
│   ├── Storage/           # FlatFile (JSON on disk)
│   └── Content/           # Post, ContentProviderInterface
├── content/               # Your bot's content provider
│   └── examples/          # Reference implementations
├── bin/                   # CLI tools
├── data/                  # Runtime data (gitignored)
└── tests/                 # PHPUnit tests
```

## Acknowledgments

- [Terence Eden's ActivityBot](https://gitlab.com/edent/activity-bot/) — the inspiration for this project
- [ActivityPub specification](https://www.w3.org/TR/activitypub/)
- [Delightful ActivityPub Development](https://codeberg.org/fediverse/delightful-activitypub-development)

## License

MIT (code) / CC BY-SA 4.0 (content files)
