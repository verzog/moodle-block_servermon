# Changelog

All notable changes to block_servermon are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.9.0] - 2026-06-18

### Added
- **Redis sharing & security signals** in the session panel, derived from
  `config.php` alone (no live connection): warns on an empty
  `session_redis_prefix` (shared-instance key collision risk), a non-loopback
  host, and a missing `session_redis_auth` on an off-box host.
- **PHP OPcache health** section: hit rate, memory used, cached scripts and JIT
  status, with advisories for out-of-memory restarts, near-full key slots and
  `opcache.validate_timestamps`; warns when OPcache is installed but disabled.
- **Production-readiness checks**: `themedesignermode`, `debugdisplay`, `debug`
  level, and `cachejs` / `cachetemplates` / `langstringcache` each flagged
  **OK** or **Review** with a remediation note.
- **Server health** section: swap usage from `/proc/meminfo` (warns above 25%)
  and cron freshness, including an explicit warning when no scheduled task has
  ever run and a count of currently failing tasks.

### Changed
- Improved Redis session detection: surface the active handler class and flag
  the "Redis configured but sessions still file-based" misconfiguration and a
  missing `redis` PHP extension.
- Restyled the collapsible sections (Top processes, Server Info, OS users &
  PHP-FPM pools, Moodle debug footer) as full-width **toggle bars** with a
  rotating CSS chevron, and unified all four to a single consistent style.

### Fixed
- Production-readiness control-structure spacing flagged by phpcs
  (`PSR12.ControlStructures.ControlStructureSpacing`).

## [1.8.1] - 2026-06-15

- Earlier maintenance release. See the Git history for details.

[1.9.0]: https://github.com/verzog/moodle-block_servermon/releases/tag/v1.9.0
[1.8.1]: https://github.com/verzog/moodle-block_servermon/releases/tag/v1.8.1
