# Server Monitor Block for Moodle (`block_servermon`)

A lightweight Moodle block that displays live server health metrics on the admin Dashboard. Designed as a simple open-source alternative to plugins like Edwiser Site Monitor.

---

## Requirements

- Moodle 5.0 or higher
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
| **CPU Load** | `/proc/cpuinfo`, `sys_getloadavg()` | Shows current load as % of total CPU capacity, plus 1m/5m/15m load averages |
| **Memory (RAM)** | `/proc/meminfo` | Shows used/free/total in GB |
| **Disk Space** | `disk_total_space()`, `disk_free_space()` | Shows used/free/total in GB for the root partition |

#### Status colours

| Colour | Meaning | Threshold |
|---|---|---|
| 🟢 Green | OK | Below 60% |
| 🟡 Amber | Moderate | 60–80% |
| 🔴 Red | High | Above 80% |

---

### Server Info Panel

Collapsed by default. Click **Server Info ▾** to expand. Contains:

| Field | Description |
|---|---|
| **Server Uptime** | How long the server has been running since last reboot, read from `/proc/uptime` |
| **PHP Version** | The PHP version Moodle is currently running on |
| **Database** | Database engine family and version, read from Moodle's `$DB` object (e.g. `PostgreSQL 15.4`, `MySQL 8.0.32`) |
| **Hostname** | The server's hostname as returned by `gethostname()` |
| **Web Server** | The web server software (e.g. Apache, Nginx), read from `$_SERVER['SERVER_SOFTWARE']` |
| **Hosting Type** | A heuristic estimate of the hosting environment (see below) |
| **Last Checked** | The timestamp when the block last rendered — data is live on each page load |

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

Reads `/etc/os-release`, `/etc/debian_version`, or `/etc/redhat-release` for the Linux distribution name and version. Shown as a signal detail only (e.g. `Debian GNU/Linux 12 (bookworm)`).

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
- Per-process CPU breakdown
- GPU usage
- Temperature sensors

### Hosting restrictions

Some shared hosting providers restrict access to `/proc/meminfo`, `/proc/cpuinfo`, or `sys_getloadavg()`. If your host blocks these, the affected metrics will display as **Unavailable**. This is a hosting-level restriction and cannot be worked around from within a Moodle plugin.

### Data is not historical

The block reads live values at the moment the Dashboard page loads. It does not store historical data or generate graphs over time. For historical monitoring, consider a dedicated server monitoring tool such as Netdata, Munin, or Glances.

---

## Visibility & Security

- The block content is **only rendered for site administrators**. Any other user who somehow has the block on their dashboard will see nothing.
- No data is written to the Moodle database by this block.
- No personal data is collected or stored (see Privacy section below).

---

## Privacy

This block stores no personal data of any kind. It reads server-level OS metrics only. The Moodle privacy API null provider is implemented accordingly.

---

## Compatibility

| Component | Requirement |
|---|---|
| Moodle | 5.0+ |
| PHP | 8.1+ |
| Database | MySQL, MariaDB, PostgreSQL (block does not query the DB directly) |
| OS | Linux (full support), Windows Server (partial — gauges unavailable) |
| Theme | Any Moodle theme |

---

## Frequently Asked Questions

**The CPU/RAM/Disk shows "Unavailable" — is the plugin broken?**
No. Your hosting provider has restricted access to the OS-level files the block reads from. This is common on shared hosting. The block itself is working correctly.

**Why does the Hosting Type say "unconfirmed"?**
Because it genuinely cannot be confirmed from within PHP alone. The label is a heuristic estimate based on available signals, not a definitive answer. See the Hosting Type Detection section above for full details.

**Can I add this block to pages other than the Dashboard?**
No — the block is restricted to My Dashboard (`applicable_formats`) by design. This keeps sensitive server information away from course pages.

**Will this slow down my Moodle site?**
Extremely unlikely. All data is read from OS memory-mapped files or built-in PHP functions. The total execution time per page load is under a millisecond on any modern server. No database queries are performed.

**The Hosting Type score seems wrong for my setup.**
This is expected in some cases — see the "Why this can be wrong" section above. The signal reasons shown under the label should help you understand what the block detected.

---

## License

GNU General Public License v3 or later
http://www.gnu.org/copyleft/gpl.html
