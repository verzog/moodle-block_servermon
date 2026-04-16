# block_servermon — Session Notes

**Date:** 2026-04-16
**Branch:** `claude/add-debug-footer-dropdown-diCuz`
**Version at end of session:** 1.4.0 (`2026040300`)
**Minimum Moodle:** 4.5 (`2024100700`)

---

## What Was Built This Session

### 1. Top-5 processes by CPU (v1.3.0)

The main feature request: a "mini top" display baked into the block.

**How it works:**
- New `process.php` AJAX endpoint reads `/proc/[pid]/stat` twice with a 200 ms
  gap and computes per-process CPU% from the tick delta — the same method `ps`
  uses internally.
- Falls back to `ps -A --sort=-%cpu -o pid,pcpu,pmem,psr,comm` via
  `shell_exec()` if `/proc` is not accessible.
- The block renders a collapsible `<details>` panel. JavaScript starts polling
  every 5 seconds when the panel is opened and cancels the interval when it
  is closed — no background load when collapsed.
- All process data is HTML-escaped in the JS renderer to prevent XSS.

### 2. CPU core column (v1.4.0)

Extends the process table with a "Core" column showing which CPU core each
process last ran on.

**How it works:**
- `/proc/[pid]/stat` field 38 (0-based) is `processor` — the last core used.
- Extracted by splitting the line after the closing `)` of the comm field and
  reading index 36 of the resulting array. This handles comm names that
  contain spaces or special characters correctly.
- `ps` fallback uses the `psr` column already included in the updated command.
- Shows `—` when the value is null (unavailable on restricted hosts).

**Why it is useful:** the per-core CPU bars already show which core is
saturated. The Core column in the process table closes the loop — you can
immediately identify which process is responsible.

**Known caveat:** the Linux scheduler migrates processes freely. The value is
"last core seen on" at the moment of the second snapshot. For a process that
is pinning a core solid this is stable; for lightly loaded processes it may
change on each 5-second refresh. This is accurate, not a bug.

---

## Key Decisions Made

### Compatibility — Moodle 4.5, not 5.0
The `$plugin->requires` was already set to `2024100700`, which is the Moodle
4.5 release version. The comment said "Moodle 5.0 minimum" — that was wrong
and has been corrected. The plugin is compatible with Moodle 4.5+. All APIs
used (`block_base`, `cache_helper`, `$PAGE->requires`, `moodle_url`) exist in
4.5.

### AJAX pattern chosen over AMD modules
A plain `fetch()` + `setInterval()` approach was used rather than a Moodle AMD
module. Rationale: the use-case is a single admin-only block with no shared
state, no require.js dependencies, and no need for i18n in the JS layer. The
simpler approach is easier to maintain and debug. If the block grows further,
migrating to AMD is straightforward.

### `/proc` sampling preferred over `ps`
Reading `/proc/[pid]/stat` directly was made the primary path (not `ps`) for
two reasons: `shell_exec()` is often disabled on production Moodle servers;
and the `/proc` approach gives us more fields (including `processor`) without
parsing `ps` output format differences across distributions.

### Disk path configurable via admin settings
A test user reported the block showed 4 GB of disk when the server actually had
6.8 TB at `/data`. `disk_total_space('/')` only queries the filesystem owning
`/`, not separate mounts. Fixed by adding a `disk_path` admin setting that
applies to both the live block and the scheduled task.

### log_metrics() alignment — deferred
The `block_servermon_log` table schema (from an earlier PR) stores `cpu_cores`
and `cpu_percore` (JSON). The `log_metrics()` call path was not fully audited
this session for alignment with the current schema. The scheduled task
(`collect_metrics.php`) was verified but the inline call in the block itself
may still reference old column names. **This should be checked before the next
production install.**

---

## PHPCS Rules Encountered (all fixed)

All violations were resolved before merging. They are documented fully in
`CLAUDE_MOODLE_DEV_GUIDE.md`. The ones that required multiple passes:

| Violation | Root cause |
|---|---|
| `VariableNameUnderscore` | First fix used camelCase, which triggered the next sniff |
| `VariableNameLowerCase` | Moodle procedural functions require all-lowercase (no camelCase, no underscores) |
| `ForbiddenGlobalUse` (`$PAGE`) | Block classes must use `$this->page` |
| `LangFilesOrdering` | `privacy:metadata` sorts before `proc_*` (`i` < `o`) |
| HTML line length | `MOODLE_ORG_DESCRIPTION.html` lines over 180 chars |
| Missed rename | One `$rssKb` survived inside an array literal after `replace_all` — always grep after bulk renames |

---

## CSS Issues Found and Fixed

A full consistency pass was done on `styles.css`:

- **Bug:** `.bsm-proc-table th` had `text-align: left` with specificity
  `(0,1,1)`, silently overriding `text-align: right/center` from the column
  classes (specificity `(0,1,0)`) on header cells. The CPU% and MEM% column
  headers were left-aligned despite the data cells being right-aligned.
- Font scale normalised from 11 near-duplicate sizes to 9 distinct steps.
- `border-radius` and divider opacity made consistent throughout.
- Redundant `opacity: 1` declaration removed.

---

## Files Added or Changed This Session

| File | Change |
|---|---|
| `block_servermon.php` | Added `render_process_section()`, `json_encode_url()`, `js_string()` helpers; `$this->page` fix |
| `process.php` | New — AJAX endpoint for process data |
| `styles.css` | Process table styles; full consistency pass |
| `lang/en/block_servermon.php` | Added `proc_core`, `proc_cpu`, `proc_empty`, `proc_error`, `proc_loading`, `proc_mem`, `proc_name`, `proc_pid`, `proc_toggle`; fixed `privacy:metadata` ordering |
| `version.php` | 1.2.0 → 1.3.0 → 1.4.0; corrected Moodle requires comment |
| `README.md` | Full rewrite for v1.3.0/1.4.0 feature set |
| `MOODLE_ORG_DESCRIPTION.html` | New — Moodle.org plugin directory HTML listing |
| `CLAUDE_MOODLE_DEV_GUIDE.md` | New — coding rules reference for future sessions |

---

## Recommended Next Steps

### High priority

1. **Audit `log_metrics()` against current DB schema**
   The `block_servermon_log` table has columns `cpu_cores`, `cpu_percore`
   (JSON), `ram_pct`, `disk_pct`. Check that the write path in both
   `block_servermon.php` and `classes/task/collect_metrics.php` uses exactly
   these column names. A mismatch will silently fail (wrapped in try/catch)
   leaving the log empty.

2. **Merge this branch to main and tag v1.4.0**
   All PHPCS violations are resolved, README and Moodle.org description are
   up to date. Branch is 3 commits ahead, 0 behind main.

3. **Install and smoke-test on the 12-core server**
   Verify: per-core bars show all 12 cores; process panel opens and refreshes;
   Core column shows expected values; disk reports `/data` correctly after
   setting the disk path in admin settings.

### Medium priority

4. **Slow query log integration**
   During this session the user mentioned a botched 5.0→5.1 upgrade and vendor
   slow query analysis. A future feature could surface the top slow queries
   from `mdl_logstore_standard_log` or from MySQL/PostgreSQL slow query logs
   directly — similar to how the process panel works but for DB queries.

5. **Network I/O metrics**
   `/proc/net/dev` provides interface-level byte counts. A two-sample delta
   (same pattern as CPU) would give current MB/s in and out. This was listed
   as a limitation in the README — it is technically feasible.

6. **Export date range setting**
   The CSV export currently hardcodes 7 days. An admin setting for retention
   period (7 / 14 / 30 days) would make it more flexible and control DB table
   growth at the same time.

7. **Process panel — highlight Moodle-related processes**
   Add a visual indicator (e.g. green background row) when a process name
   matches known Moodle patterns: `php`, `php-fpm`, `nginx`, `apache2`,
   `postgres`, `mysqld`. Helps operators immediately spot whether Moodle itself
   is the CPU consumer or something else on the server is.
