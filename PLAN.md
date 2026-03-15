# Fedibots: PHP ActivityPub Bot Framework

## Context

Terence Eden's [ActivityBot](https://gitlab.com/edent/activity-bot/) proved that a write-only ActivityPub server fits in a single PHP file (~64KB). With botsin.space shut down (Dec 2024), there's demand for simple self-hosted fediverse bots. This project creates a clean-room PHP framework inspired by ActivityBot's approach, structured as a reusable template for spinning up bots. The first bot posts daily WordPress security tips from the existing [wp-security-benchmark](https://github.com/dknauss/wp-security-benchmark) (42 controls = 6 weeks of daily content).

## Architecture Decisions

- **One subdomain per bot** — in ActivityPub, the domain IS the identity. Multi-user routing adds complexity for no benefit. Most PHP hosts support unlimited subdomains.
- **Clone-and-configure template** — the repo is the bot. Clone it, run setup, write a content provider, deploy.
- **Zero runtime dependencies** — no Composer packages needed to run. OpenSSL extension only. Dev dependencies (PHPUnit, PHPStan) via Composer.
- **Clean-room implementation** — inspired by ActivityBot's design, not forked. Licensed MIT (content files CC BY-SA 4.0).
- **PHP 8.2+** minimum.

## Project Structure

```
fedibots/
├── index.php                          # Thin front controller (~20 lines)
├── .htaccess                          # Apache rewrites (nginx config in README)
├── .env.example                       # Template config
├── composer.json                      # Dev deps only (PHPUnit, PHPStan)
├── README.md
├── LICENSE                            # MIT
│
├── src/
│   ├── Config.php                     # .env loader
│   ├── Http/
│   │   ├── Router.php                 # Path dispatch
│   │   └── Request.php                # Request wrapper
│   ├── ActivityPub/
│   │   ├── WebFinger.php              # /.well-known/webfinger
│   │   ├── NodeInfo.php               # /.well-known/nodeinfo
│   │   ├── Actor.php                  # Actor JSON-LD profile
│   │   ├── Inbox.php                  # Follow/Unfollow handling
│   │   ├── Outbox.php                 # Post creation + /action/send
│   │   ├── Signature.php              # HTTP Signatures (RSA-SHA256)
│   │   ├── Delivery.php               # Multi-cURL broadcast to inboxes
│   │   └── Collections.php            # Followers/following endpoints
│   ├── Storage/
│   │   ├── StorageInterface.php       # Contract (future: SQLite backend)
│   │   └── FlatFile.php               # JSON flat-file storage
│   ├── Content/
│   │   ├── ContentProviderInterface.php  # What every bot implements
│   │   └── Post.php                      # Post value object
│   ├── Monitor/
│   │   └── HashtagMonitor.php            # Poll RSS feeds for hashtag posts
│   └── Report/
│       └── DigestGenerator.php           # Generate engagement digest posts
│
├── content/
│   ├── ContentProvider.php            # YOUR bot's content (edit this)
│   └── examples/
│       ├── wp-security/
│       │   ├── ContentProvider.php    # WP security tips provider
│       │   └── tips.json              # 42 controls from Benchmark
│       └── random-quote/
│           └── ContentProvider.php    # Trivial example
│
├── bin/
│   ├── setup.php                      # Interactive: generate keys, create .env
│   ├── keygen.php                     # RSA keypair generation
│   ├── post.php                       # CLI: trigger a post (for cron)
│   ├── monitor.php                    # Poll hashtag feeds (for cron)
│   ├── report.php                     # Generate + post engagement digest
│   ├── verify.php                     # Check deployment health
│   └── import-tips.php                # Parse Benchmark.md into tips.json
│
├── data/                              # Runtime (gitignored)
│   ├── followers/
│   ├── posts/
│   ├── inbox/                         # Incoming replies/mentions
│   ├── monitored/                     # Posts found via hashtag monitoring
│   ├── reports/                       # Generated digest reports (JSON)
│   ├── logs/
│   └── stats.json                     # Engagement counters
│
├── media/                             # Attachments (gitignored)
│
└── tests/
    ├── Unit/
    │   ├── SignatureTest.php
    │   ├── ActorTest.php
    │   ├── WebFingerTest.php
    │   └── ContentProviderTest.php
    └── Integration/
        ├── InboxTest.php
        └── DeliveryTest.php
```

## Implementation Phases

### Phase 1: Skeleton + Discovery Endpoints
Create the front controller, router, config loader, and the three endpoints needed for a bot to be *found* on the fediverse:
- `WebFinger.php` — responds to `?resource=acct:user@domain`
- `Actor.php` — serves actor JSON-LD with public key
- `NodeInfo.php` — server metadata
- `bin/keygen.php` — RSA key generation
- `.htaccess` + `index.php` + `.env.example`

**Verify:** `curl` the WebFinger endpoint; search for the bot from a Mastodon account.

### Phase 2: Follow/Unfollow + Signatures
Enable accounts to follow the bot:
- `Signature.php` — RSA-SHA256 signing and verification
- `Inbox.php` — accept Follow, process Undo(Follow)
- `FlatFile.php` + `StorageInterface.php` — persist followers as JSON
- `Collections.php` — followers/following endpoints

**Verify:** Follow from Mastodon, confirm follower stored, confirm Accept sent back.

### Phase 3: Posting + Delivery
The core purpose — broadcasting posts to followers:
- `Post.php` — value object (content, hashtags, images, CW, visibility)
- `ContentProviderInterface.php` — the contract bots implement
- `Outbox.php` — create posts, serve outbox collection, handle `/action/send`
- `Delivery.php` — multi-cURL broadcast using shared inboxes where available
- `bin/post.php` — CLI tool that calls ContentProvider and triggers send

**Verify:** `php bin/post.php`, confirm post appears in Mastodon followers' timelines.

### Phase 4: WP Security Tips Bot
The reference implementation:
- `bin/import-tips.php` — parses `WordPress-Security-Benchmark.md`, extracts 42 controls into `tips.json`
- `content/examples/wp-security/ContentProvider.php` — selects next tip, formats it with hashtags, tracks state
- `content/examples/wp-security/tips.json` — structured tip data

Each tip formatted as:
```
WP Security Tip #1.1: Enforce TLS 1.2+

Only TLS 1.2/1.3 should be accepted. TLS 1.0/1.1 have known
vulnerabilities (BEAST, POODLE) and all browsers dropped support.

Level 1 | Web Server Config
#WordPress #WebSecurity #InfoSec
```

Cron: `0 14 * * * php /path/to/bin/post.php`

### Phase 5: Engagement Tracking + Topic Monitoring
Extend the inbox and add reporting so the bot operator knows what's happening.

**Inbox expansion** (`Inbox.php`):
- Accept and store `Create` activities (replies/mentions) in `data/inbox/`
- Accept `Announce` and `Like` if received (note: Mastodon does NOT push these to remote servers — only counts are visible)
- Store all incoming activities as JSON files for review
- Track engagement counters in `data/stats.json`

**Topic monitoring** (`src/Monitor/HashtagMonitor.php`):
- Poll RSS feeds for hashtag timelines (no auth needed, simplest approach)
  - e.g., `https://mastodon.social/tags/WordPressSecurity.rss`
  - Configurable list of instances and hashtags in `.env`
- Store discovered posts in `data/monitored/`
- Deduplicate by post URL
- Mark posts as "interesting" based on keyword matching
- Collect only — no automatic boosting/resharing

**Reporting** (`bin/report.php` + `src/Report/DigestGenerator.php`):
- `bin/report.php` — CLI tool (cron-driven) that:
  1. Reads engagement data from `data/stats.json` and `data/inbox/`
  2. Reads monitored discussions from `data/monitored/`
  3. Generates a digest post and broadcasts it to followers
  4. Writes `data/reports/YYYY-MM-DD.json` for operator review
  5. Sends webhook notification to Discord/Slack (`WEBHOOK_URL` in `.env`)

**.env additions:**
```env
# Monitoring — comma-separated, add your own instances
MONITOR_HASHTAGS=WordPress,WordPressSecurity,WPSec,InfoSec
MONITOR_INSTANCES=mastodon.social,infosec.exchange,phpc.social,wptoots.social
MONITOR_KEYWORDS=vulnerability,CVE,exploit,hardening,wp-config,brute force
MONITOR_INTERVAL=3600

# Reporting — all three channels active
REPORT_FREQUENCY=weekly
WEBHOOK_URL=https://discord.com/api/webhooks/...
```

**Cron additions:**
```
0 */1 * * * php /path/to/bin/monitor.php    # Poll hashtags hourly
0 9  * 1 * php /path/to/bin/report.php      # Weekly digest Monday 9am
```

**Verify:** Run `bin/monitor.php`, confirm posts stored in `data/monitored/`. Run `bin/report.php`, confirm digest post appears in followers' timelines.

### Phase 6: CLI Tools, Tests, Docs
- `bin/setup.php` — interactive setup wizard
- `bin/verify.php` — deployment health check
- Unit tests for Signature, Actor, WebFinger, ContentProvider, FlatFile, HashtagMonitor, DigestGenerator
- Integration tests for Inbox and Delivery (mocked HTTP)
- `README.md` — full setup guide + "create your own bot" tutorial
- GitHub Actions CI (PHPUnit + PHPStan level 6)

## Content Pipeline

```
OUTBOUND (daily tips):
  wp-security-benchmark/WordPress-Security-Benchmark.md
      -> bin/import-tips.php (parse 42 controls)
      -> tips.json (structured tip data)
      -> ContentProvider.php (select next tip)
      -> bin/post.php (cron)
      -> Delivery.php (broadcast to followers)

INBOUND (engagement):
  Fediverse -> Inbox.php
      -> data/inbox/ (replies, mentions stored as JSON)
      -> data/stats.json (follower count, reply count)

MONITORING (hashtag watch):
  bin/monitor.php (cron, hourly)
      -> HashtagMonitor.php polls RSS feeds
      -> data/monitored/ (interesting posts stored)

REPORTING (weekly digest):
  bin/report.php (cron, weekly)
      -> DigestGenerator.php reads inbox + monitored + stats
      -> Posts digest to followers
      -> Writes data/reports/YYYY-MM-DD.json
      -> Webhook to Discord/Slack
```

## Key Interface

```php
interface ContentProviderInterface {
    public function generatePost(): ?Post;
    public function getName(): string;
    public function getStatus(): array;
}
```

Each new bot = implement this interface + add a `.env` config.

## Verification

After each phase, deploy to a test subdomain and verify:
1. WebFinger discovery from Mastodon search
2. Follow/unfollow from a real account
3. Post delivery to follower timelines
4. `bin/verify.php` health check passes
5. Unit tests pass (`vendor/bin/phpunit`)

## References

- [ActivityBot on GitLab](https://gitlab.com/edent/activity-bot/) — primary inspiration
- [Eden's intro blog post](https://shkspr.mobi/blog/2024/11/introducing-activitybot-the-simplest-way-to-build-mastodon-bots/)
- [Eden's earlier single-file AP server](https://shkspr.mobi/blog/2024/02/a-tiny-incomplete-single-user-write-only-activitypub-server-in-php/)
- [FOSDEM 2025 presentation](https://shkspr.mobi/blog/2025/02/presenting-activitybot-at-fosdem/)
- [BotKit by Fedify](https://botkit.fedify.dev/) — TypeScript alternative
- [Delightful ActivityPub Development](https://codeberg.org/fediverse/delightful-activitypub-development) — curated resource list
- [WordPress ActivityPub plugin](https://activitypub.blog/)
