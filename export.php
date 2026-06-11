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
 * CSV export endpoint for block_servermon metric history.
 *
 * Streams the last 7 days of logged server metrics as a CSV download.
 * Requires site:config capability (site administrators only).
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$cutoff  = time() - (7 * DAYSECS);
$records = $DB->get_records_select(
    'block_servermon_log',
    'timecreated >= :cutoff',
    ['cutoff' => $cutoff],
    'timecreated ASC'
);

// Determine max core count across all records for consistent columns.
$maxcores = 0;
foreach ($records as $row) {
    if ($row->cpu_percore) {
        $cores = count(json_decode($row->cpu_percore, true) ?? []);
        if ($cores > $maxcores) {
            $maxcores = $cores;
        }
    }
}

// Output CSV headers.
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="servermon_metrics_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');

// Build header row.
$headers = ['timestamp'];
for ($i = 0; $i < $maxcores; $i++) {
    $headers[] = 'cpu_core' . $i . '_pct';
}
$headers[] = 'ram_pct';
$headers[] = 'disk_pct';
fputcsv($out, $headers);

// Write data rows.
foreach ($records as $row) {
    $percore = $row->cpu_percore ? json_decode($row->cpu_percore, true) : [];
    $line    = [userdate($row->timecreated, '%Y-%m-%d %H:%M:%S')];
    for ($i = 0; $i < $maxcores; $i++) {
        $line[] = $percore[$i] ?? '';
    }
    $line[] = $row->ram_pct ?? '';
    $line[] = $row->disk_pct ?? '';
    fputcsv($out, $line);
}

fclose($out);
exit;
