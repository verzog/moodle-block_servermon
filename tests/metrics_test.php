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
 * Unit tests for the deterministic metric and isolation helpers.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_servermon;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the pure helper logic in the block_servermon class.
 *
 * The block class holds its parsing and scoring logic in private methods;
 * these are exercised directly via reflection so the calculations can be
 * verified without a rendered page or live /proc access.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\block_servermon::class)]
final class metrics_test extends \advanced_testcase {
    /** @var \block_servermon Block instance used for reflection-based calls. */
    protected $block;

    /**
     * Load the block class and build an instance without invoking the constructor.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
        require_once($CFG->dirroot . '/blocks/servermon/block_servermon.php');
        $this->block = (new \ReflectionClass(\block_servermon::class))->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a private or protected method on the block instance.
     *
     * @param string $method Method name.
     * @param array $args Positional arguments.
     * @return mixed The method return value.
     */
    protected function call(string $method, array $args = []) {
        $rm = new \ReflectionMethod(\block_servermon::class, $method);
        $rm->setAccessible(true);
        return $rm->invokeArgs($this->block, $args);
    }

    /**
     * Build a fully-populated PHP-FPM pool record for the issue helpers.
     *
     * @param array $overrides Field overrides.
     * @return array Pool record with every tracked key present.
     */
    protected function pool(array $overrides = []): array {
        return $overrides + [
            'name'              => 'www',
            'user'              => 'www-data',
            'group'             => '',
            'listen'            => '',
            'listen_mode'       => '',
            'listen_owner'      => '',
            'listen_group'      => '',
            'chroot'            => '',
            'open_basedir'      => '',
            'disable_functions' => '',
            'limit_extensions'  => '',
            'issues'            => [],
            'ok'                => true,
        ];
    }

    /**
     * Aggregate CPU percentage is derived from the busy/idle tick delta.
     *
     * @return void
     */
    public function test_calc_cpu_pct(): void {
        // Ticks: user, nice, system, idle, iowait, irq, softirq, steal.
        $s1 = [0, 0, 0, 0, 0, 0, 0, 0];
        $s2 = [300, 0, 0, 100, 0, 0, 0, 0];
        // Delta total 400, delta idle 100 -> 300/400 = 75% busy.
        $this->assertSame(75.0, $this->call('calc_cpu_pct', [$s1, $s2]));

        // No movement between snapshots yields null rather than a divide-by-zero.
        $this->assertNull($this->call('calc_cpu_pct', [$s2, $s2]));
    }

    /**
     * Durations render compactly and drop to the two most significant units.
     *
     * @return void
     */
    public function test_format_duration(): void {
        $this->assertSame('45s', $this->call('format_duration', [45]));
        $this->assertSame('12m', $this->call('format_duration', [720]));
        $this->assertSame('3h 20m', $this->call('format_duration', [12000]));
        $this->assertSame('2d 4h', $this->call('format_duration', [187200]));
    }

    /**
     * Byte counts render in the largest whole unit up to megabytes.
     *
     * @return void
     */
    public function test_format_bytes(): void {
        $this->assertSame('512 B', $this->call('format_bytes', [512]));
        $this->assertSame('2 KB', $this->call('format_bytes', [2048]));
        $this->assertSame('3 MB', $this->call('format_bytes', [3145728]));
    }

    /**
     * Status colour thresholds map percentages to the right band.
     *
     * @return void
     */
    public function test_status_colour(): void {
        $this->assertSame('unknown', $this->call('status_colour', [null]));
        $this->assertSame('ok', $this->call('status_colour', [59.9]));
        $this->assertSame('moderate', $this->call('status_colour', [60.0]));
        $this->assertSame('high', $this->call('status_colour', [80.0]));
    }

    /**
     * Loopback addresses and unix sockets count as a local Redis host.
     *
     * @return void
     */
    public function test_redis_host_is_local(): void {
        foreach (['127.0.0.1', '::1', 'localhost', '/var/run/redis.sock', ''] as $host) {
            $this->assertTrue($this->call('redis_host_is_local', [$host]), $host);
        }
        foreach (['redis.example.com', '10.0.0.5'] as $host) {
            $this->assertFalse($this->call('redis_host_is_local', [$host]), $host);
        }
    }

    /**
     * Redis config findings flag a missing prefix, a remote host, and no auth.
     *
     * @return void
     */
    public function test_redis_config_findings(): void {
        $local = ['prefix' => '', 'host' => '127.0.0.1', 'auth' => true];
        $this->assertSame(['noprefix'], $this->call('redis_config_findings', [$local]));

        $remotenoauth = ['prefix' => 'site1', 'host' => 'redis.example.com', 'auth' => false];
        $this->assertSame(['remote', 'noauth'], $this->call('redis_config_findings', [$remotenoauth]));

        $remoteauth = ['prefix' => 'site1', 'host' => 'redis.example.com', 'auth' => true];
        $this->assertSame(['remote'], $this->call('redis_config_findings', [$remoteauth]));

        $clean = ['prefix' => 'site1', 'host' => '127.0.0.1', 'auth' => false];
        $this->assertSame([], $this->call('redis_config_findings', [$clean]));
    }

    /**
     * The hidepid mount level is read in both numeric and symbolic forms.
     *
     * @return void
     */
    public function test_parse_hidepid_from_mounts(): void {
        $this->assertSame(2, $this->call('parse_hidepid_from_mounts', [
            ['proc /proc proc rw,relatime,hidepid=2 0 0'],
        ]));
        $this->assertSame(2, $this->call('parse_hidepid_from_mounts', [
            ['proc /proc proc rw,hidepid=invisible 0 0'],
        ]));
        $this->assertSame(1, $this->call('parse_hidepid_from_mounts', [
            ['proc /proc proc rw,hidepid=noaccess 0 0'],
        ]));
        $this->assertSame(0, $this->call('parse_hidepid_from_mounts', [
            ['proc /proc proc rw,relatime 0 0'],
        ]));
        // No /proc mount line at all -> undetermined.
        $this->assertNull($this->call('parse_hidepid_from_mounts', [
            ['sysfs /sys sysfs rw 0 0'],
        ]));
    }

    /**
     * FPM directive values have comments stripped and $pool interpolated.
     *
     * @return void
     */
    public function test_clean_fpm_value(): void {
        $this->assertSame('www-data', $this->call('clean_fpm_value', ['www-data ; a comment', 'site1']));
        $this->assertSame('/run/php/site1.sock', $this->call('clean_fpm_value', ['/run/php/$pool.sock', 'site1']));
        $this->assertSame('value', $this->call('clean_fpm_value', ['value # trailing', 'p']));
        // A '#' with no preceding whitespace is part of the value, not a comment.
        $this->assertSame('/path#frag', $this->call('clean_fpm_value', ['/path#frag', 'p']));
    }

    /**
     * A listen socket is world-writable only when the mode's world bit is set.
     *
     * @return void
     */
    public function test_socket_world_writable(): void {
        $this->assertTrue($this->call('socket_world_writable', [
            $this->pool(['listen' => '/run/php/fpm.sock', 'listen_mode' => '0666']),
        ]));
        $this->assertFalse($this->call('socket_world_writable', [
            $this->pool(['listen' => '/run/php/fpm.sock', 'listen_mode' => '0660']),
        ]));
        // No mode set -> FPM default 0660, private.
        $this->assertFalse($this->call('socket_world_writable', [
            $this->pool(['listen' => '/run/php/fpm.sock', 'listen_mode' => '']),
        ]));
        // TCP listeners carry no filesystem permissions.
        $this->assertFalse($this->call('socket_world_writable', [
            $this->pool(['listen' => '127.0.0.1:9000', 'listen_mode' => '0666']),
        ]));
    }

    /**
     * Issue severities classify hard, soft and undetermined codes correctly.
     *
     * @return void
     */
    public function test_issue_severity(): void {
        foreach (['generic', 'root', 'nouser', 'shareduser', 'opensocket'] as $code) {
            $this->assertSame('hard', $this->call('issue_severity', [$code]), $code);
        }
        foreach (['systemuser', 'sharedhome', 'noopenbasedir'] as $code) {
            $this->assertSame('soft', $this->call('issue_severity', [$code]), $code);
        }
        foreach (['undetermined', 'unresolved'] as $code) {
            $this->assertSame('undetermined', $this->call('issue_severity', [$code]), $code);
        }
    }

    /**
     * Per-pool issues are raised for root, generic, system and blank users.
     *
     * @return void
     */
    public function test_pool_issues(): void {
        // A root pool with no fence flags both problems.
        $root = $this->pool(['user' => 'root']);
        $this->assertSame(['root', 'noopenbasedir'], $this->call('pool_issues', [$root, null, [], []]));

        // A generic web user with no fence.
        $generic = $this->pool(['user' => 'www-data']);
        $this->assertSame(['generic', 'noopenbasedir'], $this->call('pool_issues', [$generic, null, [], []]));

        // A blank user is undetermined regardless of anything else.
        $blank = $this->pool(['user' => '']);
        $this->assertSame(['undetermined'], $this->call('pool_issues', [$blank, null, [], []]));

        // A dedicated, fenced, uniquely-used account is clean.
        $good = $this->pool(['user' => 'site1', 'open_basedir' => '/home/site1']);
        $info = ['uid' => 1200, 'home' => '/home/site1'];
        $this->assertSame([], $this->call('pool_issues', [$good, $info, ['site1' => 1], ['/home/site1' => 1]]));

        // A low-UID (system) account, otherwise fenced, is a soft systemuser issue.
        $system = $this->pool(['user' => 'appuser', 'open_basedir' => '/srv/app']);
        $sysinfo = ['uid' => 500, 'home' => '/srv/app'];
        $this->assertSame(['systemuser'], $this->call('pool_issues', [$system, $sysinfo, ['appuser' => 1], ['/srv/app' => 1]]));
    }

    /**
     * The opcache.jit directive is only active for non-disabled values.
     *
     * @return void
     */
    public function test_opcache_jit_active(): void {
        foreach (['tracing', '1255', 'function'] as $on) {
            $this->assertTrue($this->call('opcache_jit_active', [$on]), $on);
        }
        foreach (['disable', 'off', '0', '', null, false] as $off) {
            $this->assertFalse($this->call('opcache_jit_active', [$off]), var_export($off, true));
        }
    }

    /**
     * Store names resolve to the correct backend key.
     *
     * @return void
     */
    public function test_get_store_type_key(): void {
        $this->assertSame('redis', $this->call('get_store_type_key', ['cachestore_redis']));
        $this->assertSame('memcached', $this->call('get_store_type_key', ['Memcached']));
        $this->assertSame('apcu', $this->call('get_store_type_key', ['cachestore_apcu']));
        $this->assertSame('file', $this->call('get_store_type_key', ['cachestore_file']));
        $this->assertSame('file', $this->call('get_store_type_key', ['something_else']));
    }

    /**
     * The isolation verdict reflects the current user and per-pool issues.
     *
     * @return void
     */
    public function test_assess_isolation(): void {
        $noleak = ['checked' => false, 'count' => 0];

        // This request running as a generic account is Weak regardless of pools.
        $weak = $this->call('assess_isolation', [
            ['pools' => [], 'unreadable' => 0, 'currentuser' => 'www-data'],
            $noleak,
        ]);
        $this->assertSame('weak', $weak['level']);

        // Two clean dedicated pools with no leak is Good.
        $good = $this->call('assess_isolation', [
            [
                'pools' => [
                    ['user' => 'site1', 'issues' => []],
                    ['user' => 'site2', 'issues' => []],
                ],
                'unreadable' => 0,
                'currentuser' => null,
            ],
            $noleak,
        ]);
        $this->assertSame('good', $good['level']);

        // A single clean dedicated pool is Single.
        $single = $this->call('assess_isolation', [
            [
                'pools' => [['user' => 'site1', 'issues' => []]],
                'unreadable' => 0,
                'currentuser' => null,
            ],
            $noleak,
        ]);
        $this->assertSame('single', $single['level']);

        // A soft-only issue downgrades to Partial.
        $partial = $this->call('assess_isolation', [
            [
                'pools' => [['user' => 'site1', 'issues' => ['systemuser']]],
                'unreadable' => 0,
                'currentuser' => null,
            ],
            $noleak,
        ]);
        $this->assertSame('partial', $partial['level']);
    }
}
