# Server Monitor Block for Moodle (`block_servermon`)

A lightweight Moodle block that displays live server health metrics on the admin Dashboard. Covers CPU, RAM, disk, top processes, Moodle page-performance metrics, cache store health, session info, and historical metric logging with CSV export.

---

## Requirements

- Moodle 5.0 or higher
- PHP 8.2 or higher
- Linux-based server recommended (Windows Server is supported but most metrics will show as unavailable)
- Site administrator role to view the block

---

## Installation

1. Download `block_servermon.zip`
2. In Moodle, go to **Site Administration → Plugins → Install plugins**
3. Upload the zip file and click **Install plugin from the ZIP file**
4. Follow the on-screen confirmation steps
5. Go to your **Dashboard**, turn editing on, click **Add a block**, and select **Server Monitor**

Alternatively, unzip the file and copy the `servermon` folder to `/blocks/` on your server, then visit **Site Administration → Notifications** to complete the install.

---

## What It Shows

### Resource Gauges

Three colour-coded progress bars are shown at the top of the block:

| Metric | Source | Notes |
|---|---|---|
| **CPU Load** | Latest scheduled-task snapshot (max 10 minutes old); falls back to a live `/proc/stat` two-sample delta (500 ms) when no fresh snapshot exists | Aggregate CPU% plus per-core breakdown bars; 1m/5m/15m load averages (always live) shown below |
| **Memory (RAM)** | `/proc/meminfo` | Used/free/total in GB |
| **Disk Space** | `disk_total_space()`, `disk_free_space()` | Used/free/total in GB for the configured mount point (default `/`) |

#### Status colours

| Colour | Meaning | Threshold |
|---|---|---|
| 🟢 Green | OK | Below 60% |
| 🟡 Amber | Moderate | 60–80% |
| 🔴 Red | High | Above 80% |

---

### Top Processes by CPU

Collapsed by default. Click **Top processes by CPU ▾** to expand.

Once open, the panel polls the server every **5 seconds** via a lightweight AJAX request and renders a live table:

| Column | Description |
|---|---|
| **PID** | Process ID |
| **Process** | Command name (truncated to 15 chars by the kernel) |
| **CPU%** | CPU usage over the last ~200 ms sample window |
| **MEM%** | Resident set size as a percentage of total RAM |
| **Core** | Last CPU core the process ran on (0-indexed). Useful for correlating a saturated core in the per-core bars above with the process responsible. Shows `—` if unavailable. |

The poll stops automatically when you collapse the panel to avoid unnecessary background requests.

**How it works:** The AJAX endpoint (`process.php`) reads all `/proc/[pid]/stat` files twice with a 200 ms gap and calculates per-process CPU% from the tick delta, mirroring what `ps` does internally. The last-used CPU core is read from field 38 of `/proc/[pid]/stat`. On servers where `/proc` is unavailable, it falls back to `ps -A --sort=-%cpu -o pid,pcpu,pmem,psr,comm` via `shell_exec()` if that function is permitted.

> **Note on Core column accuracy:** The Linux scheduler migrates processes between cores freely. The value shown is the core the process was last seen on at the moment of the second `/proc` snapshot. For a process that is pinning a core solid, this will be consistent. For lightly loaded processes that bounce around, the value may change on each refresh — this is accurate behaviour.

---

### Server Info Panel

Collapsed by default. Click **Server Info ▾** to expand. Contains:

| Field | Description |
|---|---|
| **Server Uptime** | How long the server has been running since last reboot, read from `/proc/uptime` |
| **PHP Version** | The PHP version Moodle is currently running on |
| **OS** | The OS platform string (`PHP_OS`) |
| **Hostname** | The server's hostname as returned by `gethostname()` |
| **Web Server** | The web server software (e.g. Apache, Nginx), read from `$_SERVER['SERVER_SOFTWARE']` |
| **Hosting Type** | A heuristic estimate of the hosting environment (see below) |
| **Last Checked** | The timestamp when the block last rendered — data is live on each page load |

---

### OS Users & PHP-FPM Pools — Shared-Server Isolation

Collapsed by default. Click **OS users & PHP-FPM pools — shared-server isolation ▾** to expand.

This section helps you confirm that a multi-tenant (shared) server isolates each hosted site correctly: ideally every site runs under its own operating-system user **and** its own PHP-FPM pool — fenced by `open_basedir`/`chroot`, listening on its own private socket — so one site cannot read another's files, connect to its pool, or enumerate its processes.

#### This request's user

A banner at the top shows which OS user **this Moodle request** is running as, and via which PHP SAPI. This is the single most relevant fact: if Moodle itself runs as a generic web account (`www-data`, `nginx`, …) or as `root`, the banner turns amber and the overall verdict is downgraded to **Weak**, because this site shares an account with others regardless of how the rest of the server looks.

#### Operating-system users

Lists regular accounts read from `/etc/passwd` (UID ≥ 1000) **plus** the account serving the current request and any FPM-pool owners — regardless of UID. This matters because some isolation platforms create per-app users in the **system UID range** (notably YunoHost, where an app may run as a `nologin` user with a UID below 1000); a plain "UID ≥ 1000" filter would hide exactly the users that prove isolation. The current-request account is tagged **this request**, and FPM-pool owners are tagged **FPM pool**. Remaining low-UID system accounts are counted (and on some platforms other per-site users live there too and aren't all listed individually).

| Column | Description |
|---|---|
| **User** | Account name (plus tags) |
| **UID** | Numeric user ID |
| **Shell** | Login shell (often `nologin` for per-app users) |

If `/etc/passwd` is not readable (some locked-down hosts), the list shows as unavailable.

#### PHP-FPM pools

Scans the standard pool configuration directories (`/etc/php/*/fpm/pool.d/`, `/etc/php-fpm.d/`, `/usr/local/etc/php-fpm.d/`) and renders each pool as a card showing its user/group, listen socket, and the hardening directives it sets (`open_basedir`, `chroot`, socket `listen.mode`). Each pool gets an **Isolated** badge or a **Review** badge with one or more flags:

| Flag | Meaning |
|---|---|
| **Runs as a generic web user** | Pool `user` is `www-data`/`nginx`/`apache`/… — shared with other sites |
| **Runs as root** | Pool runs as `root` |
| **User not found in /etc/passwd** | The configured `user` doesn't resolve to a real account |
| **Runs as a system account (UID < 1000)** | Not a dedicated per-site user |
| **Shares its OS user with another pool** | Two pools run as the same user |
| **Shares a home directory with another pool** | Two pool users have the same home |
| **World-writable listen socket** | `listen.mode` has the world-write bit set — any local user can connect to the pool |
| **No open_basedir or chroot fence** | Nothing restricts the pool to its own files |
| **User set elsewhere — undetermined** | `user` is blank or set in an unfollowed `include` |

If no pool files are found, the server likely uses mod_php or a single pool. If pool files exist but are not readable, that is reported too.

#### Process visibility (`/proc` hidepid)

Checks whether this PHP process can read the `/proc/[pid]/stat` of processes owned by **other** (non-root) users. If it can, `/proc` is not mounted with `hidepid`, meaning any tenant can enumerate every other tenant's processes and read their command-line arguments (which routinely contain DB passwords, API tokens, etc.). The section reports either "only its own processes" (hardened) or the count and names of the other users whose processes are visible. This is the signal behind, for example, a YunoHost Moodle seeing Uptime Kuma's processes.

#### Isolation assessment

A best-effort verdict (always marked *unconfirmed*, like the Hosting Type heuristic), combining the current request's user, the per-pool flags, unreadable config, and `/proc` visibility:

| Verdict | Meaning |
|---|---|
| **Good** | Two or more pools, each a dedicated user **and** fenced (`open_basedir`/`chroot`, private socket); no cross-tenant `/proc` leak |
| **Partial** | Either: pools run as dedicated users but some hardening is missing (no `open_basedir`/`chroot`, a shared home, cross-tenant `/proc` visibility); **or** this request runs on its own dedicated pool while the server also has generic/shared pools that don't serve it |
| **Weak** | A serious problem affecting this site: the request itself (or its own pool) runs as a generic/shared user or `root`, has a world-open socket, or a missing account |
| **Single** | A single pool running as a dedicated user — fine for one tenant, not multi-site isolation |
| **Incomplete** | Some pool files were unreadable, or a pool's `user`/`group` lives in an `include` fragment that wasn't followed — isolation can't be fully confirmed |
| **Unknown** | No pool configuration could be read |

The headline answers *"is **this** Moodle isolated?"* When the request runs on a confirmed dedicated user, serious problems confined to **other** pools downgrade the headline to **Partial** (not Weak) — those pools are still flagged individually so the server-wide warning isn't lost. If pool files were unreadable, the verdict line says so, since isolated app pools may simply not have been visible. `$pool` variables in directives (e.g. `user = $pool`) are resolved to the pool name, and php.ini-style inline comments are stripped, before assessing.

> This is a configuration audit, not a live security guarantee. It reads the FPM config files as written and the kernel's process table; it does not verify the running FPM master. Treat it as a checklist aid.

---

### Moodle Debug Footer — Key Metrics

Collapsed by default. Click **Moodle debug footer — key metrics ▾** to expand. Shows Moodle page-performance data for the current request:

#### Summary cards

| Card | Description |
|---|---|
| **Page Time** | Seconds elapsed since `REQUEST_TIME_FLOAT` |
| **Peak Memory** | PHP peak memory usage for this request (MB) |
| **DB Reads / Writes** | Number of SELECT and write queries issued by Moodle on this page load |
| **DB Query Time** | Total time spent in database queries (seconds) |

#### Session handler

Shows the active session backend type (file, Redis, Memcached, or database) and the serialised size of the current session. If Redis is detected, the full connection configuration is displayed:

- Host, port, database index
- Key prefix
- Lock timeout and lock expiry settings

A warning is shown if the file-based session handler is in use, as this can become a bottleneck under load.

#### Cache store performance

Shows hit/miss counts and I/O bytes across the four MUC cache modes:

| Row | Description |
|---|---|
| **Static cache** | In-process PHP static cache |
| **Application cache** | Persistent store (Redis, APCu, or file) |
| **Session cache** | Per-session store |
| **Request cache** | Single-request in-memory store |

If the application cache miss rate exceeds 50% and the store is file-based, an advisory note suggests adding Redis or APCu.

---

### Metric Logging & CSV Export

The block records server metrics every 5 minutes to a lightweight database table (`block_servermon_log`). A **Download metrics CSV (last 7 days)** link appears at the bottom of the block when log records exist.

#### What is logged

| Column | Description |
|---|---|
| `timestamp` | Time of the sample, rendered via `userdate()` in the exporting user's timezone |
| `cpu_core0_pct` … `cpu_coreN_pct` | Per-core CPU% (one column per physical core) |
| `ram_pct` | RAM used as a percentage of total |
| `disk_pct` | Disk used as a percentage of total (for the configured disk path) |

#### Scheduled task

A scheduled task (`collect_metrics`) runs every 5 minutes to write samples independently of page loads. It uses the same `/proc/stat` sampling logic as the block display, which in turn reads its CPU gauge from the most recent snapshot.

---

### Print / Save as PDF

A **Print / Save as PDF** button at the bottom of the block opens your browser's print dialog (where you can choose "Save as PDF"). A print stylesheet hides the rest of the Moodle page so only the block prints, preserves the colour-coded bars and badges, and adds a header line with the plugin name, hostname and timestamp.

The collapsible **Server Info**, **isolation** and **debug footer** sections are expanded automatically for the printout and restored afterwards. The live **Top processes** panel is left as you have it — expand it before printing if you want that snapshot included. This is a client-side print (no server-side PDF library), so the output matches exactly what the browser renders.

---

## Admin Settings

Go to **Site Administration → Plugins → Blocks → Server Monitor** to configure:

| Setting | Default | Description |
|---|---|---|
| **Disk path** | `/` | The filesystem path used for disk space reporting. Set this to your data mount point (e.g. `/data`) if your Moodle `moodledata` directory is on a separate partition. |

This setting also applies to the scheduled task so that logged disk metrics reflect the same mount point shown in the block.

---

### Hosting Type Detection

The block attempts to classify the server environment using a simple **resource-based scoring heuristic**. This is a **best-effort estimate only** — results should be treated as an informed guess rather than a confirmed fact, and the label is always marked as "(unconfirmed)".

#### Signals (one point each)

| Signal | How it is checked |
|---|---|
| Two or more CPU cores visible | `processor` entries in `/proc/cpuinfo` |
| Roughly 1 GB+ of RAM visible | `MemTotal` in `/proc/meminfo` |
| Network device stats readable | `/proc/net/dev` is readable |
| Hostname file present | `/etc/hostname` exists |
| PHP runs as a dedicated user | Process owner is not a generic web user (`nobody`, `www-data`, `apache`, `nginx`) |

#### Labels by score

| Score | Label |
|---|---|
| 3+ | Likely VPS or dedicated (unconfirmed) |
| 1–2 | Likely shared hosting or small VPS (unconfirmed) |
| 0 | Likely shared hosting (unconfirmed) |

The matched signals are listed beneath the label in the Server Info panel so you can see why the guess was made. On Windows the label is simply "Windows Server (unconfirmed)".

---

## Limitations

### Metrics that cannot be shown

The following are **not available via SQL or PHP** and therefore cannot be shown by this block:

- Network I/O (bandwidth in/out)
- GPU usage
- Temperature sensors

### Hosting restrictions

Some shared hosting providers restrict access to `/proc/meminfo`, `/proc/cpuinfo`, or `sys_getloadavg()`. If your host blocks these, the affected metrics will display as **Unavailable**. This is a hosting-level restriction and cannot be worked around from within a Moodle plugin.

---

## Visibility & Security

- The block content is **only rendered for site administrators**. Any other user who somehow has the block on their dashboard will see nothing.
- The AJAX process endpoint (`process.php`) and the CSV export endpoint (`export.php`) both require the `moodle/site:config` capability and will return an error for any other user.
- No personal data is collected or stored (see Privacy section below).

---

## Privacy

This block collects no personal data. The metric log table records only server-level OS metrics (CPU%, RAM%, disk%) with a timestamp. No usernames, IP addresses, or session identifiers are stored. The Moodle privacy API null provider is implemented accordingly.

---

## Compatibility

| Component | Requirement |
|---|---|
| Moodle | 5.0+ |
| PHP | 8.2+ |
| Database | MySQL, MariaDB, PostgreSQL |
| OS | Linux (full support), Windows Server (gauges unavailable) |
| Theme | Any Moodle theme |

---

## Frequently Asked Questions

**The CPU/RAM/Disk shows "Unavailable" — is the plugin broken?**
No. Your hosting provider has restricted access to the OS-level files the block reads from. This is common on shared hosting. The block itself is working correctly.

**The Top Processes panel is empty.**
Either `/proc` is not readable (restricted hosting) or `shell_exec()` is disabled. Both are hosting-level restrictions. The block will show "No process data available" rather than an error.

**The CPU% shows over 100%.**
The block displays aggregate CPU% across all cores from a `/proc/stat` two-sample delta, so 100% is the maximum. If you see very high values, check the per-core breakdown bars below the main CPU bar — one or more cores may be fully saturated.

**The disk space looks wrong.**
If your Moodle data directory is on a separate partition (e.g. `/data`), configure the **Disk path** setting under **Site Administration → Plugins → Blocks → Server Monitor** to point to that mount point.

**Why does the Hosting Type say "unconfirmed"?**
Because it genuinely cannot be confirmed from within PHP alone. The label is a heuristic estimate. See the Hosting Type Detection section for full details.

**Can I add this block to pages other than the Dashboard?**
No — the block is restricted to My Dashboard (`applicable_formats`) by design. This keeps sensitive server information away from course pages.

**Will this slow down my Moodle site?**
Minimally. The CPU gauge is read from the most recent scheduled-task snapshot, so page render is not delayed; a live 500 ms CPU sample only runs as a fallback when no snapshot from the last 10 minutes exists. The process panel only polls while open. Metric logging runs every 5 minutes via a scheduled task. No expensive queries are performed.

---

## License

GNU General Public License v3 or later
https://www.gnu.org/copyleft/gpl.html
