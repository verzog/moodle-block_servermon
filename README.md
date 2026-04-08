# Server Monitor Block for Moodle (`block_servermon`)

A lightweight Moodle block that displays live server health metrics on the admin Dashboard. Covers CPU, RAM, disk, top processes, Moodle page-performance metrics, cache store health, session info, and historical metric logging with CSV export.

---

## Requirements

- Moodle 4.5 or higher
- PHP 8.1 or higher
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
| **CPU Load** | `/proc/stat` (two-sample delta, 500 ms) | Aggregate CPU% plus per-core breakdown bars; 1m/5m/15m load averages shown below |
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

The poll stops automatically when you collapse the panel to avoid unnecessary background requests.

**How it works:** The AJAX endpoint (`process.php`) reads all `/proc/[pid]/stat` files twice with a 200 ms gap and calculates per-process CPU% from the tick delta, mirroring what `ps` does internally. On servers where `/proc` is unavailable, it falls back to `ps aux --sort=-%cpu` via `shell_exec()` if that function is permitted.

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

Shows the active session backend type (file, Redis, Memcached, or database), the serialised size of the current session, and lock wait time. If Redis is detected, the full connection configuration is displayed:

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

The block records server metrics once per minute to a lightweight database table (`block_servermon_log`). A **Download metrics CSV (last 7 days)** link appears at the bottom of the block when log records exist.

#### What is logged

| Column | Description |
|---|---|
| `timestamp` | UTC time of the sample |
| `cpu_core0_pct` … `cpu_coreN_pct` | Per-core CPU% (one column per physical core) |
| `ram_pct` | RAM used as a percentage of total |
| `disk_pct` | Disk used as a percentage of total (for the configured disk path) |

The CSV includes a UTF-8 BOM for clean opening in Microsoft Excel.

#### Scheduled task

A scheduled task (`collect_metrics`) runs every minute to write samples independently of page loads. It uses the same `/proc/stat` sampling logic as the live block display.

---

## Admin Settings

Go to **Site Administration → Plugins → Blocks → Server Monitor** to configure:

| Setting | Default | Description |
|---|---|---|
| **Disk path** | `/` | The filesystem path used for disk space reporting. Set this to your data mount point (e.g. `/data`) if your Moodle `moodledata` directory is on a separate partition. |

This setting also applies to the scheduled task so that logged disk metrics reflect the same mount point shown in the block.

---

### Hosting Type Detection

The block attempts to classify the server environment using a **six-layer detection system**. This is a **best-effort heuristic only** — results should be treated as an informed guess rather than a confirmed fact.

#### Detection layers (in priority order)

**Layer 1 — Control panel / platform fingerprints**

| Platform | Files/dirs checked | Label shown |
|---|---|---|
| YunoHost | `/etc/yunohost/`, `/usr/share/yunohost/` | YunoHost / Self-hosted VPS |
| Plesk | `/etc/plesk/`, `/usr/local/psa/version` | VPS or Dedicated with Plesk |
| cPanel | `/usr/local/cpanel/`, `/etc/cpanel/cpanel.config` | Shared or VPS with cPanel |
| DirectAdmin | `/etc/directadmin/directadmin.conf` | Shared or VPS with DirectAdmin |
| Webmin/Virtualmin | `/etc/webmin/`, `/usr/share/webmin/version` | Self-managed Server with Webmin |
| ISPConfig | `/usr/local/ispconfig/` | Shared or VPS with ISPConfig |
| HestiaCP / VestaCP | `/usr/local/hestia/`, `/usr/local/vesta/` | VPS with HestiaCP/VestaCP |
| CentOS Web Panel | `/usr/local/cwpsrv/` | VPS with CWP |
| Parallels/Virtuozzo | `/opt/psa/` | Shared or VPS with Parallels |

**Layer 2 — Cloud provider detection**

Reads `/sys/class/dmi/id/sys_vendor` to identify the cloud host directly.

| Provider detected |
|---|
| AWS, Azure, Google Cloud, DigitalOcean, Hetzner, Contabo, Vultr, Linode/Akamai, OVH |

**Layer 3 — Virtualisation type**

Reads `/sys/class/dmi/id/product_name` and `/proc/cpuinfo` hypervisor flags.

| Detected | Notes |
|---|---|
| KVM | Used by Contabo, DigitalOcean, Vultr, Linode |
| VMware | VMware ESXi environments |
| VirtualBox | Local development machines |
| QEMU/KVM | QEMU-based hypervisors |
| Xen | AWS older instances, some dedicated providers |
| Hyper-V | Microsoft Azure, Windows Server hosts |
| Container (LXC/Docker) | Detected via `/proc/1/environ` |

**Layer 4 — Bare-metal chassis detection**

Reads `/sys/class/dmi/id/chassis_type`. Chassis codes 17 (Rack Mount), 23 (Blade), and 24 (Blade Enclosure) indicate physical dedicated hardware.

**Layer 5 — OS fingerprint**

Reads `/etc/os-release`, `/etc/debian_version`, or `/etc/redhat-release` for the Linux distribution name and version.

**Layer 6 — Resource-based scoring (final fallback)**

Only used if no label was set by layers 1–4. Scores based on CPU count, RAM, `/proc/net/dev` readability, and PHP process username.

| Score | Label |
|---|---|
| 3+ | Likely Dedicated Server |
| 1–2 | Likely VPS or Shared Hosting |
| 0 | Likely Shared Hosting |

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
| Moodle | 4.5+ |
| PHP | 8.1+ |
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
Minimally. The main block render reads from OS memory-mapped files and runs a 500 ms CPU sample. The process panel only polls when open. Metric logging is rate-limited to once per minute and runs via a scheduled task. No expensive queries are performed.

---

## License

GNU General Public License v3 or later
http://www.gnu.org/copyleft/gpl.html
