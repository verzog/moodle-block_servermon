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
 * Scheduled task to collect and log server metrics for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_servermon\task;

/**
 * Collects per-core CPU, RAM, and disk snapshots every 5 minutes.
 *
 * Stores results in {block_servermon_log} and prunes rows older than 7 days.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collect_metrics extends \core\task\scheduled_task {
    /**
     * Returns the human-readable task name shown in the Moodle scheduled tasks UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_collect_metrics', 'block_servermon');
    }

    /**
     * Executes the metric collection snapshot.
     *
     * Reads per-core CPU usage via two /proc/stat samples (1 s apart),
     * RAM from /proc/meminfo, disk from disk_free_space(), writes one row
     * to {block_servermon_log}, then purges rows older than 7 days.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $islinux = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN');

        [$percore, $cores] = $this->collect_cpu_percore($islinux);

        $record = new \stdClass();
        $record->timecreated = time();
        $record->cpu_cores   = $cores;
        $record->cpu_percore = !empty($percore) ? json_encode($percore) : null;
        $record->ram_pct     = $this->collect_ram_pct($islinux);
        $record->disk_pct    = $this->collect_disk_pct($islinux);

        $DB->insert_record('block_servermon_log', $record);

        $cutoff = time() - (7 * DAYSECS);
        $DB->delete_records_select('block_servermon_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
    }

    /**
     * Sample /proc/stat twice (1 s apart) and return per-core CPU percentages.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Two-element array: [float[] percore, int cores].
     */
    private function collect_cpu_percore(bool $islinux): array {
        $percore = [];
        $cores   = 0;

        if (!$islinux || !is_readable('/proc/stat')) {
            return [$percore, $cores];
        }

        $snap1 = $this->read_proc_stat();
        sleep(1); // 1-second sample window — acceptable in a background task.
        $snap2 = $this->read_proc_stat();

        $core = 0;
        while (isset($snap1['cpu' . $core], $snap2['cpu' . $core])) {
            $pct = $this->calc_cpu_pct($snap1['cpu' . $core], $snap2['cpu' . $core]);
            $percore[] = $pct !== null ? $pct : 0.0;
            $core++;
        }
        $cores = $core;

        return [$percore, $cores];
    }

    /**
     * Read RAM usage percentage from /proc/meminfo.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return float|null RAM usage percentage, or null if unavailable.
     */
    private function collect_ram_pct(bool $islinux): ?float {
        if (!$islinux || !is_readable('/proc/meminfo')) {
            return null;
        }
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return null;
        }
        preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $mtotal);
        preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $mavail);
        if (!$mtotal || !$mavail) {
            return null;
        }
        $totalkb = (int) $mtotal[1];
        $freekb  = (int) $mavail[1];
        return $totalkb > 0 ? round((($totalkb - $freekb) / $totalkb) * 100, 1) : null;
    }

    /**
     * Read disk usage percentage via PHP disk functions.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return float|null Disk usage percentage, or null if unavailable.
     */
    private function collect_disk_pct(bool $islinux): ?float {
        if (!function_exists('disk_total_space')) {
            return null;
        }
        $path  = $islinux ? '/' : 'C:\\\\';
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if (!$total || !$free || $total <= 0) {
            return null;
        }
        return round((($total - $free) / $total) * 100, 1);
    }

    /**
     * Read /proc/stat and return an array keyed by cpu line name.
     *
     * Each value is an array of integer tick counts:
     * [user, nice, system, idle, iowait, irq, softirq, steal].
     *
     * @return array
     */
    private function read_proc_stat(): array {
        $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }
        $result = [];
        foreach ($lines as $line) {
            if (strpos($line, 'cpu') !== 0) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            $key   = trim(array_shift($parts));
            $result[$key] = array_map('intval', $parts);
        }
        return $result;
    }

    /**
     * Calculate CPU usage percentage from two /proc/stat tick snapshots.
     *
     * @param array $s1 First snapshot tick array.
     * @param array $s2 Second snapshot tick array.
     * @return float|null Usage percentage 0-100, or null if calculation fails.
     */
    private function calc_cpu_pct(array $s1, array $s2): ?float {
        $idle1  = ($s1[3] ?? 0) + ($s1[4] ?? 0);
        $idle2  = ($s2[3] ?? 0) + ($s2[4] ?? 0);
        $total1 = array_sum($s1);
        $total2 = array_sum($s2);

        $dtotal = $total2 - $total1;
        $didle  = $idle2 - $idle1;

        if ($dtotal <= 0) {
            return null;
        }

        return round((($dtotal - $didle) / $dtotal) * 100, 1);
    }
}
