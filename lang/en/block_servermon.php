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
$string['load_averages'] = '1m: {$a->one} · 5m: {$a->five} · 15m: {$a->fifteen}';
$string['obs_cache_file'] = 'Application cache miss rate ~{$a}% — adding Redis/APCu as the application store would cut file I/O.';
$string['obs_cache_redis'] = 'Application cache miss rate ~{$a}% on Redis — consider increasing Redis maxmemory or reviewing the eviction policy.';
$string['os_label'] = 'Operating System';
$string['php_label'] = 'PHP Version';
$string['pluginname'] = 'Server Monitor';
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
