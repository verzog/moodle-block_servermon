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
 * Unit tests for container detection and cgroup limits.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_servermon\local;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the container class against fixture /proc and /sys/fs/cgroup trees.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(container::class)]
final class container_test extends \advanced_testcase {
    /** @var string Mountinfo line Docker adds for the container's /etc/hostname. */
    private const DOCKER_MOUNTINFO = '612 590 259:1 /var/lib/docker/containers/3f2a9c/hostname /etc/hostname'
        . ' rw,relatime - ext4 /dev/root rw';

    /**
     * Build a fixture filesystem root from a map of relative path => contents.
     *
     * @param array $files Absolute paths (as seen by the container) => file contents.
     * @return string Fixture root directory.
     */
    private function make_root(array $files): string {
        $root = make_request_directory();
        foreach ($files as $path => $contents) {
            $full = $root . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }
        return $root;
    }

    /**
     * Files for a Docker 20.10 container on cgroup v2 (Debian 12) with limits set.
     *
     * Matches `docker run --memory=2g --cpus=1.5 --cpuset-cpus=0-3`.
     *
     * @return array Path => contents.
     */
    private function docker_v2_files(): array {
        return [
            '/.dockerenv'                              => '',
            '/proc/self/mountinfo'                     => self::DOCKER_MOUNTINFO . "\n",
            '/proc/1/cgroup'                           => "0::/\n",
            '/proc/self/cgroup'                        => "0::/\n",
            '/sys/fs/cgroup/cgroup.controllers'        => "cpuset cpu io memory pids\n",
            '/sys/fs/cgroup/memory.max'                => "2147483648\n",
            '/sys/fs/cgroup/memory.current'            => "1073741824\n",
            '/sys/fs/cgroup/memory.stat'               => "anon 805306368\nfile 268435456\ninactive_file 268435456\n",
            '/sys/fs/cgroup/cpu.max'                   => "150000 100000\n",
            '/sys/fs/cgroup/cpu.stat'                  => "usage_usec 123456789\nuser_usec 100000000\nsystem_usec 23456789\n",
            '/sys/fs/cgroup/cpuset.cpus.effective'     => "0-3\n",
        ];
    }

    /**
     * A Docker container on cgroup v2 is detected from /.dockerenv and mountinfo.
     *
     * @return void
     */
    public function test_detect_docker_cgroup_v2(): void {
        $result = (new container($this->make_root($this->docker_v2_files()), []))->detect();

        $this->assertTrue($result['container']);
        $this->assertSame('docker', $result['runtime']);
        $this->assertSame(['dockerenv', 'mountinfo'], $result['clues']);
    }

    /**
     * A plain host (no container files, root cgroup) is not reported as a container.
     *
     * @return void
     */
    public function test_detect_plain_host(): void {
        $root = $this->make_root([
            '/proc/self/mountinfo' => "22 1 259:1 / / rw,relatime - ext4 /dev/root rw\n",
            '/proc/1/cgroup'       => "0::/init.scope\n",
        ]);
        $result = (new container($root, []))->detect();

        $this->assertFalse($result['container']);
        $this->assertNull($result['runtime']);
        $this->assertSame([], $result['clues']);
    }

    /**
     * Podman and Kubernetes clues are recognised, and the more specific runtime wins.
     *
     * @return void
     */
    public function test_detect_podman_and_kubernetes(): void {
        $podman = (new container($this->make_root(['/run/.containerenv' => '']), []))->detect();
        $this->assertSame('podman', $podman['runtime']);
        $this->assertSame(['containerenv'], $podman['clues']);

        $root = $this->make_root(['/proc/1/cgroup' => "11:memory:/kubepods/burstable/pod1/abc\n"]);
        $k8s  = (new container($root, ['KUBERNETES_SERVICE_HOST' => '10.0.0.1']))->detect();
        $this->assertSame('kubernetes', $k8s['runtime']);
        $this->assertSame(['cgroup', 'kubernetes'], $k8s['clues']);
    }

    /**
     * Memory limit and usage (minus inactive page cache) come from cgroup v2 files.
     *
     * @return void
     */
    public function test_memory_cgroup_v2(): void {
        $memory = (new container($this->make_root($this->docker_v2_files()), []))->memory(16 * 1073741824);

        $this->assertSame(['limit' => 2147483648, 'used' => 805306368], $memory);
    }

    /**
     * A cgroup v2 container without a memory limit ("max") reports no limit.
     *
     * @return void
     */
    public function test_memory_cgroup_v2_unlimited(): void {
        $files = ['/sys/fs/cgroup/memory.max' => "max\n"] + $this->docker_v2_files();

        $this->assertNull((new container($this->make_root($files), []))->memory(16 * 1073741824));
    }

    /**
     * Cgroup v1 memory limits are read, and the "no limit" sentinel is ignored.
     *
     * @return void
     */
    public function test_memory_cgroup_v1(): void {
        $files = [
            '/.dockerenv'                                 => '',
            '/proc/self/cgroup'                           => "9:memory:/docker/3f2a9c\n",
            '/sys/fs/cgroup/memory/memory.limit_in_bytes' => "1073741824\n",
            '/sys/fs/cgroup/memory/memory.usage_in_bytes' => "629145600\n",
            '/sys/fs/cgroup/memory/memory.stat'           => "cache 104857600\ntotal_inactive_file 104857600\n",
        ];
        $memory = (new container($this->make_root($files), []))->memory(8 * 1073741824);
        $this->assertSame(['limit' => 1073741824, 'used' => 524288000], $memory);

        $files['/sys/fs/cgroup/memory/memory.limit_in_bytes'] = "9223372036854771712\n";
        $this->assertNull((new container($this->make_root($files), []))->memory(8 * 1073741824));
    }

    /**
     * The CPU allowance is the smallest of the quota, the cpuset and the host count.
     *
     * @return void
     */
    public function test_cpu_allowance(): void {
        $container = new container($this->make_root($this->docker_v2_files()), []);
        $this->assertSame(['cpus' => 1.5, 'limited' => true], $container->cpu_allowance(8));

        $files = ['/sys/fs/cgroup/cpu.max' => "max 100000\n"] + $this->docker_v2_files();
        $container = new container($this->make_root($files), []);
        $this->assertSame(['cpus' => 4.0, 'limited' => true], $container->cpu_allowance(8));
        $this->assertSame(['cpus' => 2.0, 'limited' => false], $container->cpu_allowance(2));
    }

    /**
     * Cumulative CPU time is read from cpu.stat (v2) or cpuacct.usage (v1).
     *
     * @return void
     */
    public function test_cpu_usage_usec(): void {
        $this->assertSame(123456789, (new container($this->make_root($this->docker_v2_files()), []))->cpu_usage_usec());

        $root = $this->make_root(['/sys/fs/cgroup/cpu,cpuacct/cpuacct.usage' => "5000000000\n"]);
        $this->assertSame(5000000, (new container($root, []))->cpu_usage_usec());

        $this->assertNull((new container($this->make_root([]), []))->cpu_usage_usec());
    }

    /**
     * Provide CPU-time samples and the expected percentage of the allowance.
     *
     * @return array Test cases.
     */
    public static function cpu_pct_provider(): array {
        return [
            'half of one cpu'       => [0, 500000, 1000000, 1.0, 50.0],
            'one cpu of a 1.5 quota' => [0, 1000000, 1000000, 1.5, 66.7],
            'capped at 100'         => [0, 3000000, 1000000, 2.0, 100.0],
            'no wall time'          => [0, 1000, 0, 1.0, null],
            'counter went back'     => [5000, 1000, 1000000, 1.0, null],
        ];
    }

    /**
     * CPU percentages are calculated against the allowance.
     *
     * @param int $usage1 First CPU time.
     * @param int $usage2 Second CPU time.
     * @param int $wall Wall-clock microseconds.
     * @param float $cpus Allowance.
     * @param float|null $expected Expected percentage.
     * @return void
     */
    #[DataProvider('cpu_pct_provider')]
    public function test_cpu_pct_from_samples(int $usage1, int $usage2, int $wall, float $cpus, ?float $expected): void {
        $this->assertSame($expected, container::cpu_pct_from_samples($usage1, $usage2, $wall, $cpus));
    }

    /**
     * Cpuset lists, cpu.max and cfs quota values parse correctly.
     *
     * @return void
     */
    public function test_parsers(): void {
        $this->assertSame(5, container::parse_cpuset("0-3,6\n"));
        $this->assertSame(1, container::parse_cpuset('2'));
        $this->assertSame(0, container::parse_cpuset(''));

        $this->assertSame(0.5, container::parse_cpu_max("50000 100000\n"));
        $this->assertNull(container::parse_cpu_max("max 100000\n"));
        $this->assertNull(container::parse_cpu_max(null));

        $this->assertSame(2.0, container::parse_cfs_quota('200000', '100000'));
        $this->assertNull(container::parse_cfs_quota('-1', '100000'));

        $this->assertNull(container::parse_limit("max\n"));
        $this->assertSame(42, container::parse_limit("42\n"));
    }

    /**
     * Runtime names are read from mountinfo and cgroup lines.
     *
     * @return void
     */
    public function test_runtime_from_lines(): void {
        $this->assertSame('docker', container::runtime_from_mountinfo([self::DOCKER_MOUNTINFO]));
        $this->assertNull(container::runtime_from_mountinfo(['22 1 259:1 / / rw - ext4 /dev/root rw']));

        $this->assertSame('docker', container::runtime_from_cgroup(['12:cpu,cpuacct:/docker/3f2a9c']));
        $this->assertSame('docker', container::runtime_from_cgroup(['0::/system.slice/docker-3f2a9c.scope']));
        $this->assertSame('lxc', container::runtime_from_cgroup(['0::/lxc.payload.web']));
        $this->assertNull(container::runtime_from_cgroup(['0::/']));
    }
}
