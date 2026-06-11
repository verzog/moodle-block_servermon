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
 * AJAX endpoint: returns top-5 processes by CPU for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

echo json_encode(['processes' => bsm_get_top_processes()]);

/**
 * Return the top-5 processes sorted by current CPU usage.
 *
 * Tries /proc sampling first (Linux), then falls back to ps(1).
 *
 * @return array Array of process records: pid, name, cpu_pct, mem_pct, cpu_core.
 */
function bsm_get_top_processes(): array {
    $islinux = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN');

    if ($islinux && is_readable('/proc')) {
        $procs = bsm_get_processes_via_proc();
        if (!empty($procs)) {
            return $procs;
        }
    }

    return bsm_get_processes_via_ps();
}

/**
 * Sample /proc/[pid]/stat twice (200 ms apart) and derive per-process CPU%.
 *
 * @return array Top-5 process records, sorted by cpu_pct descending, with cpu_core.
 */
function bsm_get_processes_via_proc(): array {
    $snap1 = bsm_scan_proc_stats();
    usleep(200000); // 200 ms sample window.
    $snap2 = bsm_scan_proc_stats();

    if (empty($snap1) || empty($snap2)) {
        return [];
    }

    // Clock ticks per second (usually 100 on Linux; 250/1000 on some kernels).
    $hz = 100;
    if (function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')) {
        $ticks = posix_sysconf(POSIX_SC_CLK_TCK); // phpcs:ignore
        if ($ticks > 0) {
            $hz = $ticks;
        }
    }
    $elapsedticks = 0.2 * $hz;

    $memtotalkb = bsm_read_mem_total_kb();

    $processes = [];
    foreach ($snap2 as $pid => $s2) {
        if (!isset($snap1[$pid])) {
            continue; // Process started after snap1 — skip.
        }
        $s1     = $snap1[$pid];
        $delta  = $s2['cpu_ticks'] - $s1['cpu_ticks'];
        $cpupct = $elapsedticks > 0 ? round(($delta / $elapsedticks) * 100, 1) : 0.0;
        $mempct = $memtotalkb > 0 ? round(($s2['rss_kb'] / $memtotalkb) * 100, 1) : 0.0;

        $processes[] = [
            'pid'      => (int) $pid,
            'name'     => $s2['name'],
            'cpu_pct'  => max(0.0, $cpupct),
            'mem_pct'  => $mempct,
            'cpu_core' => $s2['cpu_core'],
        ];
    }

    usort($processes, function ($a, $b) {
        return $b['cpu_pct'] <=> $a['cpu_pct'];
    });

    return array_slice($processes, 0, 5);
}

/**
 * Read all readable /proc/[pid]/stat entries and return an array keyed by PID.
 *
 * Each value contains: name (string), cpu_ticks (int), rss_kb (int), cpu_core (int|null).
 * cpu_core is the last CPU the process ran on (field 38, 0-based, Linux 2.2.8+).
 *
 * @return array
 */
function bsm_scan_proc_stats(): array {
    $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
    if (!$dirs) {
        return [];
    }

    $result = [];
    foreach ($dirs as $dir) {
        $pid      = basename($dir);
        $statfile = $dir . '/stat';

        if (!is_readable($statfile)) {
            continue;
        }

        $stat = @file_get_contents($statfile);
        if ($stat === false) {
            continue;
        }

        // Format: pid (comm) state ppid pgrp session tty_nr tpgid flags minflt cminflt majflt cmajflt utime stime ...
        // The comm field is wrapped in () and may contain spaces but not ')'.
        // Utime = field index 13, stime = field index 14 (0-based).
        if (!preg_match('/^(\d+)\s+\((.+)\)\s+\S+(?:\s+\S+){10}\s+(\d+)\s+(\d+)/', $stat, $m)) {
            continue;
        }

        $utime = (int) $m[3];
        $stime = (int) $m[4];

        // Processor field (field 38, 0-based): split after the closing ')' and read index 36.
        // State is index 0 in that split; processor is 36 fields later.
        $cpucore = null;
        $rest    = substr($stat, (int) strrpos($stat, ')') + 1);
        $fields  = preg_split('/\s+/', trim($rest));
        if (isset($fields[36]) && ctype_digit((string) $fields[36])) {
            $cpucore = (int) $fields[36];
        }

        // RSS from /proc/[pid]/status (VmRSS line).
        $rsskb      = 0;
        $statusfile = $dir . '/status';
        if (is_readable($statusfile)) {
            $status = @file_get_contents($statusfile);
            if ($status && preg_match('/^VmRSS:\s+(\d+)\s+kB/im', $status, $rm)) {
                $rsskb = (int) $rm[1];
            }
        }

        $result[$pid] = [
            'name'      => $m[2],
            'cpu_ticks' => $utime + $stime,
            'rss_kb'    => $rsskb,
            'cpu_core'  => $cpucore,
        ];
    }

    return $result;
}

/**
 * Read total physical memory from /proc/meminfo.
 *
 * @return int Total memory in kB, or 0 if unavailable.
 */
function bsm_read_mem_total_kb(): int {
    if (!is_readable('/proc/meminfo')) {
        return 0;
    }
    $meminfo = @file_get_contents('/proc/meminfo');
    if (!$meminfo) {
        return 0;
    }
    if (preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Fall-back: read top-5 processes via ps(1) if shell_exec() is permitted.
 *
 * @return array Top-5 process records, sorted by cpu_pct descending.
 */
function bsm_get_processes_via_ps(): array {
    if (!function_exists('shell_exec')) {
        return [];
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled)) {
        return [];
    }

    // Sort by CPU descending; limit to 6 lines to capture 5 processes.
    $out = @shell_exec('ps -A --no-headers --sort=-%cpu -o pid,pcpu,pmem,psr,comm 2>/dev/null | head -6');
    if (!$out || strlen(trim($out)) === 0) {
        return [];
    }

    $lines     = array_filter(explode("\n", trim($out)));
    $processes = [];
    foreach (array_values($lines) as $line) {
        // Columns: PID %CPU %MEM PSR COMMAND (sorted by CPU descending).
        $parts = preg_split('/\s+/', trim($line), 5);
        if (count($parts) < 5) {
            continue;
        }
        $processes[] = [
            'pid'      => (int) $parts[0],
            'name'     => $parts[4],
            'cpu_pct'  => (float) $parts[1],
            'mem_pct'  => (float) $parts[2],
            'cpu_core' => ctype_digit((string) $parts[3]) ? (int) $parts[3] : null,
        ];
        if (count($processes) >= 5) {
            break;
        }
    }

    return $processes;
}
