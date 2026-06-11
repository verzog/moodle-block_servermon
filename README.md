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
