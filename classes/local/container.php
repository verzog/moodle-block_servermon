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
 * Container detection and cgroup resource limits for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_servermon\local;

/**
 * Detects whether PHP runs inside a container and reads the container's own limits.
 *
 * Inside a container /proc/meminfo and /proc/stat describe the whole host, so the
 * gauges would show the host's RAM and CPU. The container's real memory and CPU
 * allowance, and its own usage, live in the cgroup files under /sys/fs/cgroup.
 * Both cgroup v2 (Debian 12, Ubuntu 22.04 and later) and cgroup v1 are handled.
 *
 * Every path is read relative to a root directory so tests can point the class at
 * a fixture tree instead of the live filesystem. Nothing here is stored.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class container {
    /** @var string Directory prepended to every absolute path read (empty for the live system). */
    private string $root;

    /** @var array Environment variables to inspect (name => value). */
    private array $env;

    /** @var array|null Cached detection result. */
    private ?array $detected = null;

    /**
     * Constructor.
     *
     * @param string $root Directory to treat as the filesystem root; empty for the live system.
     * @param array|null $env Environment variables to inspect; null reads the real environment.
     */
    public function __construct(string $root = '', ?array $env = null) {
        $this->root = rtrim($root, '/');
        $this->env  = $env ?? ['KUBERNETES_SERVICE_HOST' => (string) getenv('KUBERNETES_SERVICE_HOST')];
    }

    /**
     * Detect whether this process runs inside a container.
     *
     * Clues: dockerenv (/.dockerenv), containerenv (/run/.containerenv, Podman),
     * mountinfo (Docker or Kubernetes mount paths), cgroup (container names in
     * /proc/1/cgroup, cgroup v1) and kubernetes (KUBERNETES_SERVICE_HOST is set).
     *
     * @return array Keys: container (bool), runtime (string|null: docker, podman,
     *               kubernetes, lxc or containerd), clues (string[] clue codes).
     */
    public function detect(): array {
        if ($this->detected !== null) {
            return $this->detected;
        }

        $clues    = [];
        $runtimes = [];

        if (file_exists($this->path('/.dockerenv'))) {
            $clues[]    = 'dockerenv';
            $runtimes[] = 'docker';
        }
        if (file_exists($this->path('/run/.containerenv'))) {
            $clues[]    = 'containerenv';
            $runtimes[] = 'podman';
        }

        $runtime = self::runtime_from_mountinfo($this->read_lines('/proc/self/mountinfo'));
        if ($runtime !== null) {
            $clues[]    = 'mountinfo';
            $runtimes[] = $runtime;
        }

        $runtime = self::runtime_from_cgroup($this->read_lines('/proc/1/cgroup'));
        if ($runtime !== null) {
            $clues[]    = 'cgroup';
            $runtimes[] = $runtime;
        }

        if (!empty($this->env['KUBERNETES_SERVICE_HOST'])) {
            $clues[]    = 'kubernetes';
            $runtimes[] = 'kubernetes';
        }

        $this->detected = [
            'container' => !empty($clues),
            'runtime'   => self::pick_runtime($runtimes),
            'clues'     => $clues,
        ];
        return $this->detected;
    }

    /**
     * Read the container's memory limit and current usage.
     *
     * Usage excludes inactive page cache, matching what `docker stats` reports.
     *
     * @param int|null $hosttotal Host RAM in bytes; a limit at or above it counts as no limit.
     * @return array|null Keys: limit, used (bytes) — or null when no limit is set or readable.
     */
    public function memory(?int $hosttotal = null): ?array {
        $dir = $this->cgroup_dir('memory');
        if ($dir === null) {
            return null;
        }

        if ($this->cgroup_version() === 2) {
            $limit   = self::parse_limit($this->read_file($dir . '/memory.max'));
            $current = self::parse_limit($this->read_file($dir . '/memory.current'));
            $stat    = self::parse_keyed($this->read_lines($dir . '/memory.stat'));
            $inactive = $stat['inactive_file'] ?? 0;
        } else {
            $limit   = self::parse_limit($this->read_file($dir . '/memory.limit_in_bytes'));
            $current = self::parse_limit($this->read_file($dir . '/memory.usage_in_bytes'));
            $stat    = self::parse_keyed($this->read_lines($dir . '/memory.stat'));
            $inactive = $stat['total_inactive_file'] ?? 0;
        }

        if ($limit === null || $current === null || $limit <= 0) {
            return null;
        }
        // Cgroup v1 reports "no limit" as a huge number rather than "max".
        if ($hosttotal !== null && $hosttotal > 0 && $limit >= $hosttotal) {
            return null;
        }

        return ['limit' => $limit, 'used' => max(0, min($limit, $current - $inactive))];
    }

    /**
     * Read the container's CPU allowance.
     *
     * The allowance is the smallest of the CPU quota (cpu.max, or cfs_quota_us with
     * cgroup v1), the number of CPUs it may run on (cpuset) and the host's CPU count.
     *
     * @param int $hostcpus Number of CPUs visible on the host.
     * @return array Keys: cpus (float allowance), limited (bool, true when below the host count).
     */
    public function cpu_allowance(int $hostcpus): array {
        $hostcpus = max(1, $hostcpus);
        $cpus     = (float) $hostcpus;

        $dir = $this->cgroup_dir('cpu');
        if ($dir !== null) {
            if ($this->cgroup_version() === 2) {
                $quota = self::parse_cpu_max($this->read_file($dir . '/cpu.max'));
            } else {
                $quota = self::parse_cfs_quota(
                    $this->read_file($dir . '/cpu.cfs_quota_us'),
                    $this->read_file($dir . '/cpu.cfs_period_us')
                );
            }
            if ($quota !== null) {
                $cpus = min($cpus, $quota);
            }
        }

        $setdir = $this->cgroup_dir('cpuset');
        if ($setdir !== null) {
            $file  = $this->cgroup_version() === 2 ? '/cpuset.cpus.effective' : '/cpuset.cpus';
            $count = self::parse_cpuset($this->read_file($setdir . $file) ?? '');
            if ($count > 0) {
                $cpus = min($cpus, (float) $count);
            }
        }

        return ['cpus' => round($cpus, 2), 'limited' => $cpus < $hostcpus];
    }

    /**
     * Read the container's total CPU time used so far, in microseconds.
     *
     * @return int|null Cumulative CPU time, or null when cgroup CPU accounting is unreadable.
     */
    public function cpu_usage_usec(): ?int {
        if ($this->cgroup_version() === 2) {
            $dir = $this->cgroup_dir('cpu');
            if ($dir === null) {
                return null;
            }
            $stat = self::parse_keyed($this->read_lines($dir . '/cpu.stat'));
            return $stat['usage_usec'] ?? null;
        }

        $dir = $this->cgroup_dir('cpuacct');
        if ($dir === null) {
            return null;
        }
        $nanoseconds = self::parse_limit($this->read_file($dir . '/cpuacct.usage'));
        return $nanoseconds === null ? null : intdiv($nanoseconds, 1000);
    }

    /**
     * Measure the container's CPU usage over a short window.
     *
     * @param float $cpus CPU allowance from cpu_allowance().
     * @param int $windowusec Sample window in microseconds.
     * @return float|null Usage as a percentage of the allowance, or null when unreadable.
     */
    public function sample_cpu_pct(float $cpus, int $windowusec): ?float {
        $usage1 = $this->cpu_usage_usec();
        $wall1  = hrtime(true);
        if ($usage1 === null) {
            return null;
        }
        usleep($windowusec);
        $usage2 = $this->cpu_usage_usec();
        $wall2  = hrtime(true);
        if ($usage2 === null) {
            return null;
        }
        return self::cpu_pct_from_samples($usage1, $usage2, intdiv($wall2 - $wall1, 1000), $cpus);
    }

    /**
     * Turn two CPU-time readings into a percentage of the CPU allowance.
     *
     * @param int $usage1 First cumulative CPU time (microseconds).
     * @param int $usage2 Second cumulative CPU time (microseconds).
     * @param int $wallusec Wall-clock time between the readings (microseconds).
     * @param float $cpus CPU allowance.
     * @return float|null Percentage 0-100, or null when the inputs cannot give a result.
     */
    public static function cpu_pct_from_samples(int $usage1, int $usage2, int $wallusec, float $cpus): ?float {
        if ($wallusec <= 0 || $cpus <= 0 || $usage2 < $usage1) {
            return null;
        }
        $pct = (($usage2 - $usage1) / ($wallusec * $cpus)) * 100;
        return round(min(100.0, $pct), 1);
    }

    /**
     * Identify the container runtime from /proc/self/mountinfo lines.
     *
     * Docker bind-mounts /etc/hostname, /etc/hosts and /etc/resolv.conf from
     * /var/lib/docker/containers/<id>/, which works on cgroup v1 and v2 alike.
     *
     * @param array $lines Lines from /proc/self/mountinfo.
     * @return string|null docker, kubernetes or podman — or null when no container mount is found.
     */
    public static function runtime_from_mountinfo(array $lines): ?string {
        foreach ($lines as $line) {
            if (strpos($line, '/docker/containers/') !== false) {
                return 'docker';
            }
            if (strpos($line, '/kubelet/pods/') !== false) {
                return 'kubernetes';
            }
            if (strpos($line, '/containers/storage/overlay-containers/') !== false) {
                return 'podman';
            }
        }
        return null;
    }

    /**
     * Identify the container runtime from /proc/1/cgroup lines.
     *
     * With cgroup v1 the paths name the runtime (/docker/<id>, /kubepods/...). With
     * cgroup v2 and a private cgroup namespace the file is just "0::/", which gives
     * no clue, so this only ever adds evidence.
     *
     * @param array $lines Lines from /proc/1/cgroup.
     * @return string|null kubernetes, docker, lxc or containerd — or null when nothing matches.
     */
    public static function runtime_from_cgroup(array $lines): ?string {
        $patterns = [
            'kubernetes' => '#/kubepods#',
            'docker'     => '#/docker[/-]|docker-[0-9a-f]+\.scope#',
            'lxc'        => '#/lxc[/.]#',
            'containerd' => '#/containerd[/-]|cri-containerd#',
        ];
        foreach ($patterns as $runtime => $pattern) {
            foreach ($lines as $line) {
                if (preg_match($pattern, $line)) {
                    return $runtime;
                }
            }
        }
        return null;
    }

    /**
     * Count the CPUs in a cpuset list such as "0-3,6".
     *
     * @param string $list Cpuset list.
     * @return int Number of CPUs, 0 when the list is empty or malformed.
     */
    public static function parse_cpuset(string $list): int {
        $count = 0;
        foreach (explode(',', trim($list)) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $count += max(0, (int) $m[2] - (int) $m[1] + 1);
            } else if (preg_match('/^\d+$/', $part)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Parse a cgroup v2 cpu.max value such as "150000 100000" or "max 100000".
     *
     * @param string|null $value File contents.
     * @return float|null CPU quota in CPUs, or null when unlimited or unreadable.
     */
    public static function parse_cpu_max(?string $value): ?float {
        if ($value === null) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($value));
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[1] <= 0) {
            return null;
        }
        return (int) $parts[0] / (int) $parts[1];
    }

    /**
     * Parse cgroup v1 cpu.cfs_quota_us and cpu.cfs_period_us values.
     *
     * @param string|null $quota Quota contents (-1 means unlimited).
     * @param string|null $period Period contents.
     * @return float|null CPU quota in CPUs, or null when unlimited or unreadable.
     */
    public static function parse_cfs_quota(?string $quota, ?string $period): ?float {
        if ($quota === null || $period === null) {
            return null;
        }
        $q = (int) trim($quota);
        $p = (int) trim($period);
        if ($q <= 0 || $p <= 0) {
            return null;
        }
        return $q / $p;
    }

    /**
     * Parse a single-number cgroup file, treating "max" as unreadable.
     *
     * @param string|null $value File contents.
     * @return int|null The number, or null for "max", empty or non-numeric contents.
     */
    public static function parse_limit(?string $value): ?int {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        // Values above PHP_INT_MAX (cgroup v1 "no limit") become a float; treat as no limit.
        $number = $value + 0;
        return is_int($number) ? $number : null;
    }

    /**
     * Parse "key value" lines (memory.stat, cpu.stat) into an array of integers.
     *
     * @param array $lines File lines.
     * @return array Key => integer value.
     */
    public static function parse_keyed(array $lines): array {
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2 && ctype_digit($parts[1])) {
                $result[$parts[0]] = (int) $parts[1];
            }
        }
        return $result;
    }

    /**
     * Choose the most specific runtime from the detected candidates.
     *
     * Kubernetes and Podman are more specific than the generic Docker and
     * containerd clues they can also produce.
     *
     * @param array $runtimes Runtime names found by the individual clues.
     * @return string|null The chosen runtime, or null when none was named.
     */
    private static function pick_runtime(array $runtimes): ?string {
        foreach (['kubernetes', 'podman', 'docker', 'lxc', 'containerd'] as $runtime) {
            if (in_array($runtime, $runtimes, true)) {
                return $runtime;
            }
        }
        return null;
    }

    /**
     * Report which cgroup version is mounted at /sys/fs/cgroup.
     *
     * @return int|null 2 for the unified hierarchy, 1 for per-controller mounts, null when absent.
     */
    private function cgroup_version(): ?int {
        if (file_exists($this->path('/sys/fs/cgroup/cgroup.controllers'))) {
            return 2;
        }
        foreach (['memory', 'cpu', 'cpuacct', 'cpu,cpuacct', 'cpuacct,cpu', 'cpuset'] as $name) {
            if (is_dir($this->path('/sys/fs/cgroup/' . $name))) {
                return 1;
            }
        }
        return null;
    }

    /**
     * Find this process's cgroup directory for a controller.
     *
     * Prefers the path named in /proc/self/cgroup (needed when the container shares
     * the host's cgroup namespace) and falls back to the mount root, which is the
     * container's own cgroup when it has a private namespace (the Docker default).
     *
     * @param string $controller Controller name: memory, cpu, cpuacct or cpuset.
     * @return string|null Absolute directory (without the test root), or null when absent.
     */
    private function cgroup_dir(string $controller): ?string {
        $version = $this->cgroup_version();
        if ($version === null) {
            return null;
        }

        $lines = $this->read_lines('/proc/self/cgroup');
        if ($version === 2) {
            $base = '/sys/fs/cgroup';
            $sub  = self::cgroup_path($lines, '');
        } else {
            $base = $this->v1_mount($controller);
            if ($base === null) {
                return null;
            }
            $sub = self::cgroup_path($lines, $controller);
        }

        if ($sub !== null && $sub !== '/' && is_dir($this->path($base . $sub))) {
            return $base . $sub;
        }
        return is_dir($this->path($base)) ? $base : null;
    }

    /**
     * Find the cgroup v1 mount directory for a controller.
     *
     * Controllers are often co-mounted, such as /sys/fs/cgroup/cpu,cpuacct.
     *
     * @param string $controller Controller name.
     * @return string|null Mount directory, or null when not mounted.
     */
    private function v1_mount(string $controller): ?string {
        $candidates = [
            'memory'  => ['memory'],
            'cpu'     => ['cpu', 'cpu,cpuacct', 'cpuacct,cpu'],
            'cpuacct' => ['cpuacct', 'cpu,cpuacct', 'cpuacct,cpu'],
            'cpuset'  => ['cpuset'],
        ];
        foreach ($candidates[$controller] ?? [] as $name) {
            if (is_dir($this->path('/sys/fs/cgroup/' . $name))) {
                return '/sys/fs/cgroup/' . $name;
            }
        }
        return null;
    }

    /**
     * Read this process's cgroup path for a controller from /proc/self/cgroup lines.
     *
     * @param array $lines Lines such as "0::/" (v2) or "4:memory:/docker/abc" (v1).
     * @param string $controller Controller name, or '' for the cgroup v2 unified entry.
     * @return string|null The path, or null when the controller is not listed.
     */
    private static function cgroup_path(array $lines, string $controller): ?string {
        foreach ($lines as $line) {
            $parts = explode(':', trim($line), 3);
            if (count($parts) !== 3) {
                continue;
            }
            $names = $parts[1] === '' ? [''] : explode(',', $parts[1]);
            if (in_array($controller, $names, true)) {
                return $parts[2];
            }
        }
        return null;
    }

    /**
     * Prefix an absolute path with the configured root.
     *
     * @param string $path Absolute path.
     * @return string Path to read.
     */
    private function path(string $path): string {
        return $this->root . $path;
    }

    /**
     * Read a small file, suppressing open_basedir and permission warnings.
     *
     * @param string $path Absolute path.
     * @return string|null File contents, or null when unreadable.
     */
    private function read_file(string $path): ?string {
        $full = $this->path($path);
        if (!@is_readable($full)) {
            return null;
        }
        $contents = @file_get_contents($full);
        return $contents === false ? null : $contents;
    }

    /**
     * Read a file as non-empty lines.
     *
     * @param string $path Absolute path.
     * @return array Lines without trailing newlines, or an empty array when unreadable.
     */
    private function read_lines(string $path): array {
        $contents = $this->read_file($path);
        if ($contents === null) {
            return [];
        }
        return array_values(array_filter(explode("\n", $contents), 'strlen'));
    }
}
