<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['cpu_core'] = 'Core {$a}';
$string['cpu_label'] = 'CPU Load';
$string['csv_export'] = 'Download metrics CSV (last 7 days)';
$string['debug_cache_app'] = 'Application cache ({$a})';
$string['debug_cache_hits'] = 'Hits';
$string['debug_cache_io'] = 'I/O (bytes)';
$string['debug_cache_misses'] = 'Misses';
$string['debug_cache_request'] = 'Request cache (in-memory, per-request)';
$string['debug_cache_session'] = 'Session cache';
$string['debug_cache_static'] = 'Static accelerator (in-process)';
$string['debug_cache_store'] = 'Store';
$string['debug_cache_title'] = 'Cache store performance';
$string['debug_dbrw'] = 'DB reads/writes';
$string['debug_dbtime'] = 'DB query time';
$string['debug_memory'] = 'RAM used';
$string['debug_obs'] = 'Observation';
$string['debug_pagetime'] = 'Page load';
$string['debug_session'] = 'Session handler';
$string['debug_session_detail'] = 'Session type: {$a->type} • Session size: {$a->size}';
$string['debug_session_redis'] = 'Redis: {$a->host}:{$a->port} · db={$a->db} · prefix={$a->prefix} · lock-timeout={$a->lock_timeout} s · lock-expire={$a->lock_expire} s';
$string['debug_session_warn'] = 'File sessions can cause AJAX request queuing — switching to Redis removes this risk.';
$string['debug_toggle'] = 'Moodle debug footer — key metrics';
$string['disk_detail'] = '{$a->used} GB used of {$a->total} GB ({$a->free} GB free)';
$string['disk_label'] = 'Disk Space';
$string['hosting_label'] = 'Hosting Type';
$string['hosting_reason_cores'] = '{$a} CPU cores visible';
$string['hosting_reason_hostname'] = '/etc/hostname present';
$string['hosting_reason_netdev'] = '/proc/net/dev readable';
$string['hosting_reason_ram'] = '{$a} GB RAM';
$string['hosting_reason_user'] = 'Running as {$a}';
$string['hosting_shared'] = 'Likely shared hosting (unconfirmed)';
$string['hosting_shared_small'] = 'Likely shared hosting or small VPS (unconfirmed)';
$string['hosting_vps'] = 'Likely VPS or dedicated (unconfirmed)';
$string['hosting_windows'] = 'Windows Server (unconfirmed)';
$string['hostname_label'] = 'Hostname';
$string['info_toggle'] = 'Server Info ▾';
$string['iso_caveat_unreadable'] = 'Note: {$a} pool file(s) were unreadable, so other (possibly isolated) pools could not be checked.';
$string['iso_current_dedicated'] = 'This Moodle request runs as the dedicated OS user {$a->user} (via {$a->sapi}).';
$string['iso_current_generic'] = 'This Moodle request runs as {$a->user} (via {$a->sapi}) — a shared/privileged account, so this site is not isolated from others on the server.';
$string['iso_flag_generic'] = 'Runs as a generic web user';
$string['iso_flag_noopenbasedir'] = 'No open_basedir or chroot fence';
$string['iso_flag_nouser'] = 'User not found in /etc/passwd';
$string['iso_flag_ok'] = 'Isolated';
$string['iso_flag_opensocket'] = 'World-writable listen socket';
$string['iso_flag_root'] = 'Runs as root';
$string['iso_flag_sharedhome'] = 'Shares a home directory with another pool';
$string['iso_flag_shareduser'] = 'Shares its OS user with another pool';
$string['iso_flag_systemuser'] = 'Runs as a system account (UID < 1000)';
$string['iso_flag_undetermined'] = 'User set elsewhere — undetermined';
$string['iso_hard_chroot'] = 'chroot';
$string['iso_hard_mode'] = 'socket mode';
$string['iso_hard_openbasedir'] = 'open_basedir';
$string['iso_pool_issues'] = 'Review';
$string['iso_pool_meta'] = 'User: {$a->user} · Listen: {$a->listen}';
$string['iso_pools_intro'] = 'Each pool should run as its own OS user with its own listen socket, fenced by open_basedir or chroot. Shared users or open sockets mean sites can reach each other.';
$string['iso_pools_none'] = 'No PHP-FPM pool configuration found in the standard locations. The server may use mod_php or a single pool.';
$string['iso_pools_some_unreadable'] = '{$a} additional pool file(s) could not be read, so this list may be incomplete.';
$string['iso_pools_title'] = 'PHP-FPM pools';
$string['iso_pools_unreadable'] = 'PHP-FPM pool files exist but are not readable by the web server user.';
$string['iso_proc_leak'] = 'This process can read {$a->count} other user(s)\' processes ({$a->users}) — /proc is not mounted with hidepid, so other tenants\' command-line arguments (which can contain secrets) are visible.';
$string['iso_proc_ok'] = 'This process can only see its own processes — /proc appears to be mounted with hidepid, hiding other tenants\' process lists.';
$string['iso_proc_title'] = 'Process visibility (/proc hidepid)';
$string['iso_systemcount'] = '…plus {$a} lower-level system account(s) (UID < 1000); on some platforms other per-site app users live here too and are not all listed.';
$string['iso_tag_current'] = 'this request';
$string['iso_tag_pool'] = 'FPM pool';
$string['iso_toggle'] = 'OS users & PHP-FPM pools — shared-server isolation ▾';
$string['iso_user_name'] = 'User';
$string['iso_user_shell'] = 'Shell';
$string['iso_user_uid'] = 'UID';
$string['iso_users_intro'] = 'Regular accounts (UID ≥ 1000) plus the account this request runs as and any FPM-pool owners. On an isolated server each site has its own dedicated user — often a shell-less, system-range account.';
$string['iso_users_none'] = 'No regular login users found.';
$string['iso_users_title'] = 'Operating-system users';
$string['iso_users_unavailable'] = '/etc/passwd is not readable — the OS user list is unavailable on this host.';
$string['iso_verdict_current'] = 'This Moodle runs as the shared/privileged account {$a} — it is not isolated from other sites on this server (unconfirmed).';
$string['iso_verdict_good'] = 'Each PHP-FPM pool runs as its own dedicated user and is fenced (open_basedir/chroot, private socket) — sites appear properly isolated (unconfirmed).';
$string['iso_verdict_incomplete'] = 'Some PHP-FPM pool details could not be read (unreadable files, or user/group set in an included fragment), so isolation cannot be fully confirmed.';
$string['iso_verdict_otherweak'] = 'This Moodle runs on its own dedicated pool, but the server also exposes one or more generic/shared pools (flagged above) that do not serve this site — server-wide isolation is incomplete (unconfirmed).';
$string['iso_verdict_partial'] = 'Pools run as dedicated users, but some hardening is missing (no open_basedir/chroot, a shared home, or cross-tenant /proc visibility) — isolation is incomplete (unconfirmed).';
$string['iso_verdict_proconly'] = 'No PHP-FPM pools were readable, but this process can see {$a} other user(s)\' processes — /proc is not hidepid-protected (unconfirmed).';
$string['iso_verdict_single'] = 'A single PHP-FPM pool running as a dedicated user — fine for one tenant, but not multi-site isolation (unconfirmed).';
$string['iso_verdict_title'] = 'Isolation assessment';
$string['iso_verdict_unknown'] = 'No PHP-FPM pools were readable, so per-site isolation cannot be determined.';
$string['iso_verdict_weak'] = 'One or more pools have a serious isolation problem (generic/shared user, world-open socket, or missing account) — see the per-pool flags above (unconfirmed).';
$string['load_averages'] = '1m: {$a->one} · 5m: {$a->five} · 15m: {$a->fifteen}';
$string['obs_cache_file'] = 'Application cache miss rate ~{$a}% — adding Redis/APCu as the application store would cut file I/O.';
$string['obs_cache_redis'] = 'Application cache miss rate ~{$a}% on Redis — consider increasing Redis maxmemory or reviewing the eviction policy.';
$string['os_label'] = 'Operating System';
$string['php_label'] = 'PHP Version';
$string['pluginname'] = 'Server Monitor';
$string['print_button'] = 'Print / Save as PDF';
$string['print_header'] = '{$a->name} — {$a->host} — {$a->date}';
$string['privacy:metadata'] = 'The Server Monitor block does not store any personal data.';
$string['proc_core'] = 'Core';
$string['proc_cpu'] = 'CPU%';
$string['proc_empty'] = 'No process data available.';
$string['proc_error'] = 'Unable to load process data.';
$string['proc_loading'] = 'Loading…';
$string['proc_mem'] = 'MEM%';
$string['proc_name'] = 'Process';
$string['proc_pid'] = 'PID';
$string['proc_toggle'] = 'Top processes by CPU ▾';
$string['ram_detail'] = '{$a->used} GB used of {$a->total} GB ({$a->free} GB free)';
$string['ram_label'] = 'Memory (RAM)';
$string['servermon:addinstance'] = 'Add a Server Monitor block';
$string['servermon:myaddinstance'] = 'Add a Server Monitor block to Dashboard';
$string['setting_disk_path'] = 'Disk path to monitor';
$string['setting_disk_path_desc'] = 'Filesystem path used to measure disk usage (e.g. /data). Defaults to / when left blank or when the path is not readable. Use this when your data directory lives on a separate mount point.';
$string['status_high'] = 'HIGH';
$string['status_moderate'] = 'MODERATE';
$string['status_ok'] = 'OK';
$string['status_unknown'] = 'UNKNOWN';
$string['store_apcu'] = 'APCu store';
$string['store_file'] = 'file store';
$string['store_memcached'] = 'memcached store';
$string['store_redis'] = 'redis store';
$string['task_collect_metrics'] = 'Server Monitor - collect metric snapshot';
$string['timestamp_label'] = 'Last checked';
$string['unavailable'] = 'Unavailable';
$string['uptime_label'] = 'Server Uptime';
$string['webserver_label'] = 'Web Server';
