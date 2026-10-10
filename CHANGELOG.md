# Changelog

All notable changes to block_servermon are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-10-10

### Added
- **Docker and other container detection.** The Hosting Type row now reports a
  detected container (Docker, Podman, Kubernetes, LXC or containerd) and the clues
  that identified it, instead of guessing from the host's CPU and RAM.
- **Container-aware gauges.** Inside a container the CPU gauge shows usage as a
  share of the container's CPU allowance, and the RAM gauge shows usage against
  its memory limit, read from cgroup v1 or v2. Each gauge falls back to the host
  figures, with a note, when no limit is set or the cgroup files are unreadable.
- Notes on the disk gauge, server uptime and isolation panel explaining which
  figures belong to the host rather than the container.
- **Metric log** gains `cpu_pct` (overall CPU%) and `container` columns, which are
  also included in the CSV export.

## [2.0.0] - 2026-10-09

### Changed
- **Moodle 5.1 is now the minimum version** (`requires` 2025100600) and the
  plugin declares support for Moodle 5.1 to 5.3 LTS (`supported` [501, 503]).
  Moodle 5.0 is no longer supported or tested; sites on 5.0 should stay on 1.9.1
  until they upgrade Moodle.

## [1.9.1] - 2026-06-21

### Fixed
- **Session handler diagnostic** now resolves the *effective* handler the way
  core's `\core\session\manager` does: when `$CFG->session_handler_class` is
  unset, the panel reports **database** sessions if `$CFG->dbsessions` is enabled
  (and the DB supports session locking), otherwise file. This stops a
  database-session site being mislabelled "defaults to file" and falsely raising
  the Redis-inactive / file-session warning.
- **Process-visibility (`/proc` hidepid)** no longer trusts `getmyuid()` (which
  returns the script *file* owner, not the worker's effective UID) — the check is
  skipped when the POSIX extension can't supply a true effective UID, rather than
  comparing against the wrong identity.
- **Process-visibility** distinguishes "no other-user processes were running" from
  "foreign processes exist but are hidden", so a host with nothing to sample no
  longer receives a false `hidepid`-hardened signal. The `hidepid` level is read
  from the `/proc` mount options so a hardened `hidepid=2` mount (which hides
  foreign `/proc/[pid]` dirs from the listing) is still recognised as hardened.
- **PHP-FPM pool audit** treats an *unresolvable* OS account (no readable
  `/etc/passwd` and no `posix_getpwnam()`) as an undetermined/incomplete result
  instead of a hard "user not found" failure that downgraded the verdict to Weak.
- **Isolation verdict** no longer returns the site-centric *Partial* ("on its own
  dedicated pool") result when another pool's config is undetermined (e.g. user set
  in an `include`) and the current request's pool was not positively matched as
  clean — such hosts now report *Incomplete*.

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

[2.1.0]: https://github.com/verzog/moodle-block_servermon/releases/tag/v2.1.0
[2.0.0]: https://github.com/verzog/moodle-block_servermon/releases/tag/v2.0.0
[1.9.1]: https://github.com/verzog/moodle-block_servermon/releases/tag/v1.9.1
[1.9.0]: https://github.com/verzog/moodle-block_servermon/releases/tag/v1.9.0
[1.8.1]: https://github.com/verzog/moodle-block_servermon/releases/tag/v1.8.1
