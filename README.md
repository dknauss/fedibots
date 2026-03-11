# Fedibots

A PHP framework for creating write-only ActivityPub bots on the fediverse.

Inspired by [Terence Eden's ActivityBot](https://gitlab.com/edent/activity-bot/), fedibots is a clean-room implementation that provides a reusable template for spinning up fediverse bots. Each bot is a clone of this repo with its own configuration and content provider.

## Status

**Planning phase.** See [PLAN.md](PLAN.md) for the full implementation plan.

## Goals

- Zero runtime dependencies (PHP 8.2+ with OpenSSL only)
- Each bot = one subdomain, one config file, one content provider
- Flat-file storage (no database required)
- Cron-driven posting via CLI tools
- First bot: daily WordPress security tips from [wp-security-benchmark](https://github.com/dknauss/wp-security-benchmark)

## License

MIT (code) / CC BY-SA 4.0 (content files)
