<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Strings are ordered alphabetically by key per Moodle coding style.
$string['adminonly']               = 'This block is visible to site administrators only.';
$string['debug_cache_app']         = 'Application cache ({$a})';
$string['debug_cache_hits']        = 'Hits';
$string['debug_cache_io']          = 'I/O (bytes)';
$string['debug_cache_misses']      = 'Misses';
$string['debug_cache_request']     = 'Request cache (in-memory, per-request)';
$string['debug_cache_session']     = 'Session cache';
$string['debug_cache_static']      = 'Static accelerator (in-process)';
$string['debug_cache_store']       = 'Store';
$string['debug_cache_title']       = 'Cache store performance';
$string['debug_dbtime']            = 'DB query time';
$string['debug_dbrw']              = 'DB reads/writes';
$string['debug_memory']            = 'RAM used';
$string['debug_obs']               = 'Observation';
$string['debug_pagetime']          = 'Page load';
$string['debug_session']           = 'Session handler';
$string['debug_session_detail']    = 'Session type: {$a->type} • Session size: {$a->size} • Session wait: {$a->wait}';
$string['debug_session_redis']     = 'Redis: {$a->host}:{$a->port} · db={$a->db} · prefix={$a->prefix} · lock-timeout={$a->lock_timeout} s · lock-expire={$a->lock_expire} s';
$string['debug_session_warn']      = 'File sessions can cause AJAX request queuing — switching to Redis removes this risk.';
$string['debug_toggle']            = 'Moodle debug footer — key metrics';
$string['cpu_label']               = 'CPU Load';
$string['disk_label']              = 'Disk Space';
$string['gb_free']                 = '{$a->free} GB free';
$string['gb_used']                 = '{$a->used} GB used of {$a->total} GB';
$string['hostname_label']          = 'Hostname';
$string['hosting_label']           = 'Hosting Type';
$string['info_toggle']             = 'Server Info ▾';
$string['load_averages']           = '1m: {$a->one} · 5m: {$a->five} · 15m: {$a->fifteen}';
$string['os_label']                = 'Operating System';
$string['php_label']               = 'PHP Version';
$string['pluginname']              = 'Server Monitor';
$string['privacy:metadata']        = 'The Server Monitor block does not store any personal data.';
$string['ram_detail']              = '{$a->used} GB used of {$a->total} GB ({$a->free} GB free)';
$string['ram_label']               = 'Memory (RAM)';
$string['servermon:addinstance']   = 'Add a Server Monitor block';
$string['servermon:myaddinstance'] = 'Add a Server Monitor block to Dashboard';
$string['status_high']             = 'HIGH';
$string['status_moderate']         = 'MODERATE';
$string['status_ok']               = 'OK';
$string['status_unknown']          = 'UNKNOWN';
$string['timestamp_label']         = 'Last checked';
$string['unavailable']             = 'Unavailable';
$string['uptime_label']            = 'Server Uptime';
$string['webserver_label']         = 'Web Server';
