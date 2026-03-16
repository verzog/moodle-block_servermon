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
