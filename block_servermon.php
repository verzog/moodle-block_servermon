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
 * Server Monitor block for Moodle 5.0+.
 *
 * Displays CPU load, RAM usage, disk space, uptime and server info
 * on the admin Dashboard. Visible to site administrators only.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block class for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_servermon extends block_base {
    // Moodle block lifecycle methods.

    /**
     * Initialise the block title.
     *
     * @return void
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_servermon');
    }

    /**
     * Only one instance of this block is allowed per page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * This block has a site-wide configuration form (settings.php).
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * This block is only applicable on the My Dashboard page.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'my'     => true,
            'site'   => false,
            'course' => false,
        ];
    }

    /**
     * Build and return the block content.
     *
     * Returns empty content silently when the viewer is not a site administrator.
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->footer = '';
        $this->content->text   = '';

        if (!is_siteadmin()) {
            return $this->content;
        }

        $metrics = $this->collect_metrics();
        $this->content->text = $this->render_block($metrics);

        return $this->content;
    }

    // Data collection.

    /**
     * Collect all server metrics and return as an associative array.
     *
     * @return array
     */
    private function collect_metrics(): array {
        $islinux = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN');

        return [
            'cpu'       => $this->get_cpu($islinux),
            'ram'       => $this->get_ram($islinux),
            'disk'      => $this->get_disk($islinux),
            'uptime'    => $this->get_uptime($islinux),
            'php'       => PHP_VERSION,
            'os'        => PHP_OS,
            'hostname'  => gethostname() ?: 'unknown',
            'webserver' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'time'      => userdate(time()),
            'hosting'   => $this->get_hosting_type($islinux),
            'isolation' => $this->get_isolation_info($islinux),
        ];
    }

    /**
     * Read CPU usage, preferring the latest scheduled-task snapshot.
     *
     * Uses the most recent block_servermon_log row (max 10 minutes old)
     * so page render is not blocked by a live two-sample /proc/stat read.
     * Falls back to live sampling when no fresh snapshot exists, and
     * degrades gracefully if /proc/stat is unreadable.
     * Load averages are always collected live as supplementary context.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: pct, load1, load5, load15, cores, percore.
     */
    private function get_cpu(bool $islinux): array {
        $result = [
            'pct'     => null,
            'load1'   => null,
            'load5'   => null,
            'load15'  => null,
            'cores'   => null,
            'percore' => [],
        ];

        if (!$islinux) {
            return $result;
        }

        $result = array_merge($result, $this->get_load_averages());

        $logged = $this->get_cpu_from_log();
        if ($logged !== null) {
            return array_merge($result, $logged);
        }

        if (!is_readable('/proc/stat')) {
            return $result;
        }

        return array_merge($result, $this->get_cpu_percore_stats());
    }

    /**
     * Read the most recent CPU snapshot recorded by the scheduled task.
     *
     * @return array|null Keys: pct, cores, percore — or null when no
     *                    snapshot newer than 10 minutes is available.
     */
    private function get_cpu_from_log(): ?array {
        global $DB;

        if (!$DB->get_manager()->table_exists('block_servermon_log')) {
            return null;
        }

        $rows = $DB->get_records_select(
            'block_servermon_log',
            'timecreated >= :cutoff',
            ['cutoff' => time() - 600],
            'timecreated DESC',
            '*',
            0,
            1
        );
        $row = reset($rows);
        if (!$row || empty($row->cpu_percore)) {
            return null;
        }

        $values = json_decode($row->cpu_percore, true);
        if (!is_array($values) || empty($values)) {
            return null;
        }

        $percore = [];
        foreach (array_values($values) as $i => $pct) {
            $percore[] = ['core' => $i, 'pct' => (float) $pct];
        }

        return [
            'pct'     => round(array_sum($values) / count($values), 1),
            'cores'   => count($values),
            'percore' => $percore,
        ];
    }

    /**
     * Read load averages from sys_getloadavg().
     *
     * @return array Keys: load1, load5, load15.
     */
    private function get_load_averages(): array {
        if (!function_exists('sys_getloadavg')) {
            return ['load1' => null, 'load5' => null, 'load15' => null];
        }
        $load = @sys_getloadavg();
        if (!$load) {
            return ['load1' => null, 'load5' => null, 'load15' => null];
        }
        return [
            'load1'  => round($load[0], 2),
            'load5'  => round($load[1], 2),
            'load15' => round($load[2], 2),
        ];
    }

    /**
     * Sample /proc/stat twice (0.5 s apart) and return aggregate and per-core CPU percentages.
     *
     * @return array Keys: pct, cores, percore.
     */
    private function get_cpu_percore_stats(): array {
        $result = ['pct' => null, 'cores' => null, 'percore' => []];

        $snap1 = $this->read_proc_stat();
        usleep(500000); // 0.5 second sample window.
        $snap2 = $this->read_proc_stat();

        if (empty($snap1) || empty($snap2)) {
            return $result;
        }

        if (isset($snap1['cpu'], $snap2['cpu'])) {
            $result['pct'] = $this->calc_cpu_pct($snap1['cpu'], $snap2['cpu']);
        }

        $core = 0;
        while (isset($snap1['cpu' . $core], $snap2['cpu' . $core])) {
            $result['percore'][] = [
                'core' => $core,
                'pct'  => $this->calc_cpu_pct($snap1['cpu' . $core], $snap2['cpu' . $core]),
            ];
            $core++;
        }

        $result['cores'] = $core > 0 ? $core : null;
        return $result;
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
        // Tick positions: user, nice, system, idle, iowait, irq, softirq, steal.
        // Guest/guest_nice (fields 8-9) are already counted in user/nice,
        // so only the first eight fields are summed to avoid double counting.
        $idle1  = ($s1[3] ?? 0) + ($s1[4] ?? 0); // Idle + iowait.
        $idle2  = ($s2[3] ?? 0) + ($s2[4] ?? 0);
        $total1 = array_sum(array_slice($s1, 0, 8));
        $total2 = array_sum(array_slice($s2, 0, 8));

        $dtotal = $total2 - $total1;
        $didle = $idle2 - $idle1;

        if ($dtotal <= 0) {
            return null;
        }

        return round((($dtotal - $didle) / $dtotal) * 100, 1);
    }

    /**
     * Read RAM usage from /proc/meminfo.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: total, used, free, pct (all in GB).
     */
    private function get_ram(bool $islinux): array {
        $result = ['total' => null, 'used' => null, 'free' => null, 'pct' => null];

        if (!$islinux || !is_readable('/proc/meminfo')) {
            return $result;
        }

        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $mtotal);
        preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $mavail);

        if (!$mtotal || !$mavail) {
            return $result;
        }

        $totalkb = (int) $mtotal[1];
        $freekb  = (int) $mavail[1];
        $usedkb  = $totalkb - $freekb;

        $result['total'] = round($totalkb / 1048576, 2);
        $result['free'] = round($freekb / 1048576, 2);
        $result['used'] = round($usedkb / 1048576, 2);
        $result['pct']   = $totalkb > 0 ? round(($usedkb / $totalkb) * 100, 1) : null;

        return $result;
    }

    /**
     * Read disk usage via PHP disk_total_space / disk_free_space.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: total, used, free, pct (all in GB).
     */
    private function get_disk(bool $islinux): array {
        $result = ['total' => null, 'used' => null, 'free' => null, 'pct' => null];

        if (!function_exists('disk_total_space')) {
            return $result;
        }

        $configured = get_config('block_servermon', 'disk_path');
        $default    = $islinux ? '/' : 'C:\\';
        $path       = (!empty($configured) && is_readable($configured)) ? $configured : $default;
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);

        if (!$total || !$free) {
            return $result;
        }

        $totalgb = round($total / 1073741824, 2);
        $freegb = round($free / 1073741824, 2);
        $usedgb  = round($totalgb - $freegb, 2);

        $result['total'] = $totalgb;
        $result['free']  = $freegb;
        $result['used']  = $usedgb;
        $result['pct']   = $totalgb > 0 ? round(($usedgb / $totalgb) * 100, 1) : null;

        return $result;
    }

    /**
     * Read server uptime from /proc/uptime.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return string|null Human-readable uptime string, or null if unavailable.
     */
    private function get_uptime(bool $islinux): ?string {
        if (!$islinux || !is_readable('/proc/uptime')) {
            return null;
        }

        $secs = (int) floatval(file_get_contents('/proc/uptime'));
        $d    = (int) floor($secs / 86400);
        $h    = (int) floor(($secs % 86400) / 3600);
        $m    = (int) floor(($secs % 3600) / 60);

        return "{$d}d {$h}h {$m}m";
    }

    /**
     * Guess whether the server is shared hosting, VPS, or dedicated.
     *
     * Uses heuristic signals — the label is always marked as unconfirmed.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: label (string), reasons (array of strings).
     */
    private function get_hosting_type(bool $islinux): array {
        if (!$islinux) {
            return ['label' => get_string('hosting_windows', 'block_servermon'), 'reasons' => []];
        }

        $score   = 0;
        $reasons = [];

        [$cpuscore, $cpureason] = $this->hosting_score_cpu();
        $score += $cpuscore;
        if ($cpureason) {
            $reasons[] = $cpureason;
        }

        [$ramscore, $ramreason] = $this->hosting_score_ram();
        $score += $ramscore;
        if ($ramreason) {
            $reasons[] = $ramreason;
        }

        if (is_readable('/proc/net/dev')) {
            $score++;
            $reasons[] = get_string('hosting_reason_netdev', 'block_servermon');
        }

        if (file_exists('/etc/hostname')) {
            $score++;
            $reasons[] = get_string('hosting_reason_hostname', 'block_servermon');
        }

        [$userscore, $userreason] = $this->hosting_score_user();
        $score += $userscore;
        if ($userreason) {
            $reasons[] = $userreason;
        }

        return ['label' => $this->hosting_label_from_score($score), 'reasons' => $reasons];
    }

    /**
     * Score hosting environment based on CPU core count.
     *
     * @return array Two-element array: [int score, string|null reason].
     */
    private function hosting_score_cpu(): array {
        if (!is_readable('/proc/cpuinfo')) {
            return [0, null];
        }
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
        $cores = max(1, count($matches[0]));
        if ($cores >= 2) {
            return [1, get_string('hosting_reason_cores', 'block_servermon', $cores)];
        }
        return [0, null];
    }

    /**
     * Score hosting environment based on available RAM.
     *
     * @return array Two-element array: [int score, string|null reason].
     */
    private function hosting_score_ram(): array {
        if (!is_readable('/proc/meminfo')) {
            return [0, null];
        }
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $m);
        if (!$m) {
            return [0, null];
        }
        $rammb = (int) $m[1] / 1024;
        if ($rammb >= 900) {
            return [1, get_string('hosting_reason_ram', 'block_servermon', round($rammb / 1024, 1))];
        }
        return [0, null];
    }

    /**
     * Score hosting environment based on the process owner username.
     *
     * @return array Two-element array: [int score, string|null reason].
     */
    private function hosting_score_user(): array {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return [0, null];
        }
        $user = posix_getpwuid(posix_geteuid());
        if ($user && !in_array($user['name'], ['nobody', 'www-data', 'apache', 'nginx'])) {
            return [1, get_string('hosting_reason_user', 'block_servermon', $user['name'])];
        }
        return [0, null];
    }

    /**
     * Return a hosting environment label based on heuristic score.
     *
     * @param int $score Accumulated signal score.
     * @return string Human-readable label.
     */
    private function hosting_label_from_score(int $score): string {
        if ($score >= 3) {
            return get_string('hosting_vps', 'block_servermon');
        }
        if ($score >= 1) {
            return get_string('hosting_shared_small', 'block_servermon');
        }
        return get_string('hosting_shared', 'block_servermon');
    }

    // Shared-server isolation: OS users and PHP-FPM pools.

    /**
     * Collect operating-system users and PHP-FPM pool layout, plus a verdict
     * on whether a multi-tenant server appears to isolate sites correctly.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: users, pools, procvis, verdict.
     */
    private function get_isolation_info(bool $islinux): array {
        $users    = $this->get_os_users($islinux);
        $poolinfo = $this->get_fpm_pools($islinux);
        $procvis  = $this->get_proc_visibility($islinux);

        $poolinfo['pools'] = $this->evaluate_pools($poolinfo['pools'], $users);
        $users             = $this->add_known_site_users($users, $poolinfo);

        return [
            'users'   => $users,
            'pools'   => $poolinfo,
            'procvis' => $procvis,
            'verdict' => $this->assess_isolation($poolinfo, $procvis),
        ];
    }

    /**
     * Add the accounts known to serve sites (the current request's user and any
     * FPM-pool owners) to the user list, regardless of UID.
     *
     * Some platforms (notably YunoHost) create per-app users in the system UID
     * range (< 1000), so a plain UID >= 1000 filter misses them even though they
     * are the isolation mechanism. This pulls them back in via the passwd map.
     *
     * @param array $users OS user info from get_os_users().
     * @param array $poolinfo Pool info from get_fpm_pools().
     * @return array Updated OS user info.
     */
    private function add_known_site_users(array $users, array $poolinfo): array {
        if (!$users['readable']) {
            return $users;
        }

        $known = [];
        if (!empty($poolinfo['currentuser'])) {
            $known[$poolinfo['currentuser']] = true;
        }
        foreach ($poolinfo['pools'] as $pool) {
            if ($pool['user'] !== '' && strpos($pool['user'], '$') === false) {
                $known[$pool['user']] = true;
            }
        }

        $listed = [];
        foreach ($users['users'] as $u) {
            $listed[$u['name']] = true;
        }

        foreach (array_keys($known) as $name) {
            // Already shown, generic web account, or unknown to passwd — skip.
            if (isset($listed[$name]) || $this->is_generic_user($name) || empty($users['byname'][$name])) {
                continue;
            }
            $info = $users['byname'][$name];
            $users['users'][] = [
                'name'  => $name,
                'uid'   => $info['uid'],
                'home'  => $info['home'],
                'shell' => $info['shell'] ?? '',
            ];
            // It was previously tallied as a low-level system account.
            if ($info['uid'] < 1000 || $info['uid'] >= 65534) {
                $users['systemcount'] = max(0, $users['systemcount'] - 1);
            }
        }

        usort($users['users'], static fn($a, $b) => $a['uid'] <=> $b['uid']);
        return $users;
    }

    /**
     * The set of OS usernames treated as generic/shared web-server accounts.
     *
     * A PHP-FPM pool or PHP process running as one of these is not isolated
     * from other sites that run as the same account.
     *
     * @return array List of lowercase usernames.
     */
    private function generic_users(): array {
        return ['www-data', 'www', 'apache', 'apache2', 'nginx', 'httpd', 'nobody', 'daemon'];
    }

    /**
     * Whether a username is a generic/shared web-server account.
     *
     * @param string $name Username to test.
     * @return bool
     */
    private function is_generic_user(string $name): bool {
        return in_array(strtolower($name), $this->generic_users(), true);
    }

    /**
     * Read per-site operating-system accounts from /etc/passwd.
     *
     * Accounts with UID 1000–65533 are returned individually — including
     * shell-less service accounts, because isolation setups (YunoHost, Plesk,
     * cPanel, …) deliberately run each app as its own nologin user. Lower-UID
     * system accounts are only counted.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: readable (bool), users (array), systemcount (int), byname (array).
     */
    private function get_os_users(bool $islinux): array {
        $result = ['readable' => false, 'users' => [], 'systemcount' => 0, 'byname' => []];

        if (!$islinux || !is_readable('/etc/passwd')) {
            return $result;
        }

        $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }
        $result['readable'] = true;

        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode(':', $line);
            if (count($parts) < 7) {
                continue;
            }
            $uid   = (int) $parts[2];
            $home  = $parts[5];
            $shell = trim($parts[6]);

            // Keep every account in a name lookup so pool users can be cross-referenced.
            $result['byname'][$parts[0]] = ['uid' => $uid, 'home' => $home, 'shell' => $shell];

            // Per-site accounts: UID 1000-65533, regardless of login shell. 65534 is "nobody".
            if ($uid >= 1000 && $uid < 65534) {
                $result['users'][] = [
                    'name'  => $parts[0],
                    'uid'   => $uid,
                    'home'  => $home,
                    'shell' => $shell,
                ];
            } else {
                $result['systemcount']++;
            }
        }

        usort($result['users'], static fn($a, $b) => $a['uid'] <=> $b['uid']);

        return $result;
    }

    /**
     * Discover and parse PHP-FPM pool definitions from the standard locations.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: found (bool, any pool file existed), pools (array),
     *               sapi (string), currentuser (string|null).
     */
    private function get_fpm_pools(bool $islinux): array {
        $result = [
            'found'       => false,
            'unreadable'  => 0,
            'pools'       => [],
            'sapi'        => php_sapi_name(),
            'currentuser' => $this->current_process_user(),
        ];

        if (!$islinux) {
            return $result;
        }

        $patterns = [
            '/etc/php/*/fpm/pool.d/*.conf',
            '/etc/php-fpm.d/*.conf',
            '/etc/php*/php-fpm.d/*.conf',
            '/usr/local/etc/php-fpm.d/*.conf',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            $matches = @glob($pattern);
            if ($matches) {
                $files = array_merge($files, $matches);
            }
        }
        $files = array_unique($files);
        $result['found'] = !empty($files);

        foreach ($files as $file) {
            if (!is_readable($file)) {
                $result['unreadable']++;
                continue;
            }
            foreach ($this->parse_fpm_pool_file($file) as $pool) {
                $result['pools'][] = $pool;
            }
        }

        usort($result['pools'], static fn($a, $b) => strcmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * Parse a single PHP-FPM configuration file into pool definitions.
     *
     * A file may contain several [pool] sections; the [global] section is ignored.
     *
     * @param string $file Absolute path to a readable .conf file.
     * @return array List of pools (see new_pool() for the shape).
     */
    private function parse_fpm_pool_file(string $file): array {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $pools = [];
        $name  = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) {
                $name = ($m[1] === 'global') ? null : $m[1];
                if ($name !== null && !isset($pools[$name])) {
                    $pools[$name] = $this->new_pool($name);
                }
                continue;
            }
            if ($name === null) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key   = strtolower(trim(substr($line, 0, $eq)));
            $value = $this->clean_fpm_value(substr($line, $eq + 1), $name);
            $pools[$name] = $this->apply_pool_directive($pools[$name], $key, $value);
        }

        return array_values($pools);
    }

    /**
     * Build an empty pool record with all tracked fields defaulted.
     *
     * @param string $name Pool (section) name.
     * @return array
     */
    private function new_pool(string $name): array {
        return [
            'name'              => $name,
            'user'              => '',
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
     * Store a known PHP-FPM directive into a pool record; ignore the rest.
     *
     * @param array $pool Pool record to update.
     * @param string $key Lower-cased directive name.
     * @param string $value Cleaned directive value.
     * @return array Updated pool record.
     */
    private function apply_pool_directive(array $pool, string $key, string $value): array {
        $map = [
            'user'                               => 'user',
            'group'                              => 'group',
            'listen'                             => 'listen',
            'listen.mode'                        => 'listen_mode',
            'listen.owner'                       => 'listen_owner',
            'listen.group'                       => 'listen_group',
            'chroot'                             => 'chroot',
            'security.limit_extensions'          => 'limit_extensions',
            'php_admin_value[open_basedir]'      => 'open_basedir',
            'php_value[open_basedir]'            => 'open_basedir',
            'php_admin_value[disable_functions]' => 'disable_functions',
        ];
        if (isset($map[$key])) {
            $pool[$map[$key]] = $value;
        }
        return $pool;
    }

    /**
     * Normalise a raw PHP-FPM directive value.
     *
     * Strips php.ini-style inline comments (';' always starts one; '#' after
     * whitespace) and interpolates the '$pool' variable with the pool name,
     * exactly as PHP-FPM does at runtime.
     *
     * @param string $value Raw value captured from the config line.
     * @param string $poolname The enclosing pool (section) name.
     * @return string Cleaned value.
     */
    private function clean_fpm_value(string $value, string $poolname): string {
        $value = preg_replace('/;.*$/', '', $value);
        $value = preg_replace('/\s+#.*$/', '', $value);
        $value = trim($value);
        return str_replace('$pool', $poolname, $value);
    }

    /**
     * Return the username the current PHP process is running as.
     *
     * @return string|null Username, or null if it cannot be determined.
     */
    private function current_process_user(): ?string {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = @posix_getpwuid(posix_geteuid());
            if ($user && !empty($user['name'])) {
                return $user['name'];
            }
        }
        return null;
    }

    /**
     * Resolve an OS user by name from the parsed passwd map, falling back to
     * posix_getpwnam() (which also consults NSS) when not found locally.
     *
     * @param string $name Username.
     * @param array $users OS user info from get_os_users().
     * @return array|null Keys: uid (int), home (string) — or null if unknown.
     */
    private function lookup_user(string $name, array $users): ?array {
        if (!empty($users['byname'][$name])) {
            return $users['byname'][$name];
        }
        if (function_exists('posix_getpwnam')) {
            $info = @posix_getpwnam($name);
            if ($info) {
                return ['uid' => (int) $info['uid'], 'home' => $info['dir'] ?? ''];
            }
        }
        return null;
    }

    /**
     * Evaluate every pool, annotating each with an 'issues' list and 'ok' flag.
     *
     * @param array $pools Raw pools from parse_fpm_pool_file().
     * @param array $users OS user info from get_os_users().
     * @return array Pools with 'issues' and 'ok' populated.
     */
    private function evaluate_pools(array $pools, array $users): array {
        $usercounts = [];
        $homecounts = [];
        $infos      = [];

        foreach ($pools as $i => $pool) {
            $user = $pool['user'];
            if ($user === '' || strpos($user, '$') !== false) {
                continue;
            }
            $usercounts[$user] = ($usercounts[$user] ?? 0) + 1;
            $info = $this->lookup_user($user, $users);
            $infos[$i] = $info;
            if ($info !== null && $info['home'] !== '') {
                $homecounts[$info['home']] = ($homecounts[$info['home']] ?? 0) + 1;
            }
        }

        foreach ($pools as $i => $pool) {
            $pool['issues'] = $this->pool_issues($pool, $infos[$i] ?? null, $usercounts, $homecounts);
            $pool['ok']     = empty($pool['issues']);
            $pools[$i]      = $pool;
        }

        return $pools;
    }

    /**
     * Determine the isolation issues for a single pool.
     *
     * @param array $pool Pool record.
     * @param array|null $userinfo Resolved OS user (uid, home) or null.
     * @param array $usercounts Map of pool user => number of pools using it.
     * @param array $homecounts Map of home dir => number of pool users sharing it.
     * @return array List of issue codes (see issue_severity()).
     */
    private function pool_issues(array $pool, ?array $userinfo, array $usercounts, array $homecounts): array {
        $issues = [];
        $user   = $pool['user'];

        if ($user === '' || strpos($user, '$') !== false) {
            return ['undetermined'];
        }

        if (strtolower($user) === 'root') {
            $issues[] = 'root';
        } else if ($this->is_generic_user($user)) {
            $issues[] = 'generic';
        } else if ($userinfo === null) {
            $issues[] = 'nouser';
        } else {
            if ($userinfo['uid'] < 1000) {
                $issues[] = 'systemuser';
            }
            if (($usercounts[$user] ?? 0) > 1) {
                $issues[] = 'shareduser';
            }
            if ($userinfo['home'] !== '' && ($homecounts[$userinfo['home']] ?? 0) > 1) {
                $issues[] = 'sharedhome';
            }
        }

        if ($this->socket_world_writable($pool)) {
            $issues[] = 'opensocket';
        }
        if ($pool['open_basedir'] === '' && $pool['chroot'] === '') {
            $issues[] = 'noopenbasedir';
        }

        return $issues;
    }

    /**
     * Classify an issue code by how badly it breaks isolation.
     *
     * @param string $code Issue code from pool_issues().
     * @return string One of: hard, soft, undetermined.
     */
    private function issue_severity(string $code): string {
        $hard = ['generic', 'root', 'nouser', 'shareduser', 'opensocket'];
        if (in_array($code, $hard, true)) {
            return 'hard';
        }
        if ($code === 'undetermined') {
            return 'undetermined';
        }
        return 'soft';
    }

    /**
     * Whether a pool's Unix listen socket is world-writable (any local user
     * could connect to the pool). TCP listeners are not assessed here.
     *
     * @param array $pool Pool record.
     * @return bool
     */
    private function socket_world_writable(array $pool): bool {
        $listen = $pool['listen'];
        if ($listen === '') {
            return false;
        }
        // Only Unix sockets carry filesystem permissions.
        if ($listen[0] !== '/' && stripos($listen, '.sock') === false) {
            return false;
        }
        $mode = $pool['listen_mode'];
        if ($mode === '') {
            return false; // FPM default 0660 is private to owner/group.
        }
        $digits = preg_replace('/\D/', '', $mode);
        if ($digits === '') {
            return false;
        }
        // World-write bit set means any local account can connect to the pool.
        return ((int) substr($digits, -1) & 2) === 2;
    }

    /**
     * Detect whether this process can see other users' processes via /proc.
     *
     * On a hardened multi-tenant server /proc is mounted with hidepid, so a
     * non-root account only sees its own processes. If we can read the stat of
     * processes owned by other (non-root) users, their command lines — which
     * often carry secrets — are exposed across tenants.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: checked (bool), count (int), foreign (array of names).
     */
    private function get_proc_visibility(bool $islinux): array {
        $result = ['checked' => false, 'count' => 0, 'foreign' => []];

        if (!$islinux) {
            return $result;
        }

        $myuid = $this->current_euid();
        if ($myuid < 0) {
            return $result; // Cannot reliably compare ownership.
        }

        $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
        if (!$dirs) {
            return $result;
        }
        $result['checked'] = true;

        $foreign = [];
        foreach ($dirs as $dir) {
            $owner = @fileowner($dir);
            if ($owner === false || $owner === $myuid || $owner === 0) {
                continue;
            }
            // Only count it as a leak if the process detail is actually readable.
            if (@is_readable($dir . '/stat')) {
                $foreign[$this->uid_name($owner)] = true;
            }
        }

        $result['foreign'] = array_keys($foreign);
        $result['count']   = count($foreign);
        return $result;
    }

    /**
     * Return the effective UID of the current process, or -1 if unknown.
     *
     * @return int
     */
    private function current_euid(): int {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        if (function_exists('getmyuid')) {
            $uid = getmyuid();
            return $uid !== false ? $uid : -1;
        }
        return -1;
    }

    /**
     * Resolve a numeric UID to a username, falling back to the number.
     *
     * @param int $uid User ID.
     * @return string
     */
    private function uid_name(int $uid): string {
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if ($info && !empty($info['name'])) {
                return $info['name'];
            }
        }
        return (string) $uid;
    }

    /**
     * Whether /proc visibility indicates a cross-tenant process leak.
     *
     * @param array $procvis Result from get_proc_visibility().
     * @return bool
     */
    private function procvis_leaks(array $procvis): bool {
        return !empty($procvis['checked']) && $procvis['count'] > 0;
    }

    /**
     * Build a verdict array, appending an optional trailing caveat sentence.
     *
     * @param string $level Verdict level.
     * @param string $key Language string key for the label.
     * @param mixed $a Optional placeholder data for the language string.
     * @param string $caveat Optional already-built caveat to append.
     * @return array Keys: level, label.
     */
    private function verdict(string $level, string $key, $a = null, string $caveat = ''): array {
        return ['level' => $level, 'label' => get_string($key, 'block_servermon', $a) . $caveat];
    }

    /**
     * Assess whether the server appears to isolate sites from one another.
     *
     * Combines the user this request runs as, per-pool issues, unreadable
     * config, and /proc process visibility. Best-effort — always unconfirmed.
     *
     * When this request runs on a confirmed dedicated user, serious problems
     * confined to other pools downgrade the headline to Partial rather than
     * Weak: this site is isolated even though the server has shared pools.
     *
     * @param array $poolinfo Pool info from get_fpm_pools() with evaluated pools.
     * @param array $procvis Result from get_proc_visibility().
     * @return array Keys: level, label.
     */
    private function assess_isolation(array $poolinfo, array $procvis): array {
        $pools      = $poolinfo['pools'];
        $unreadable = (int) ($poolinfo['unreadable'] ?? 0);
        $current    = (string) ($poolinfo['currentuser'] ?? '');
        $caveat     = $unreadable > 0 ? ' ' . get_string('iso_caveat_unreadable', 'block_servermon', $unreadable) : '';

        $currentbad       = $current !== '' && ($this->is_generic_user($current) || strtolower($current) === 'root');
        $currentdedicated = $current !== '' && !$currentbad;

        // The account THIS request runs as is the most direct signal.
        if ($currentbad) {
            return $this->verdict('weak', 'iso_verdict_current', $current);
        }

        if (empty($pools)) {
            if ($this->procvis_leaks($procvis)) {
                return $this->verdict('partial', 'iso_verdict_proconly', $procvis['count']);
            }
            if ($unreadable > 0) {
                return $this->verdict('incomplete', 'iso_verdict_incomplete');
            }
            return $this->verdict('unknown', 'iso_verdict_unknown');
        }

        $hard = false;
        $soft = false;
        $undetermined = false;
        $hardhitscurrent = false;
        foreach ($pools as $pool) {
            $iscurrentpool = $current !== '' && $pool['user'] === $current;
            foreach ($pool['issues'] as $code) {
                $sev = $this->issue_severity($code);
                if ($sev === 'hard') {
                    $hard = true;
                    $hardhitscurrent = $hardhitscurrent || $iscurrentpool;
                } else if ($sev === 'soft') {
                    $soft = true;
                } else {
                    $undetermined = true;
                }
            }
        }

        if ($hard) {
            // Option A: a dedicated current user whose own pool is clean stays
            // isolated even when other pools are misconfigured.
            if ($currentdedicated && !$hardhitscurrent) {
                return $this->verdict('partial', 'iso_verdict_otherweak', null, $caveat);
            }
            return $this->verdict('weak', 'iso_verdict_weak', null, $caveat);
        }
        if ($undetermined || $unreadable > 0) {
            return $this->verdict('incomplete', 'iso_verdict_incomplete');
        }
        if ($soft || $this->procvis_leaks($procvis)) {
            return $this->verdict('partial', 'iso_verdict_partial');
        }
        if (count($pools) >= 2) {
            return $this->verdict('good', 'iso_verdict_good');
        }
        return $this->verdict('single', 'iso_verdict_single');
    }

    // Rendering.

    /**
     * Render the full block HTML from collected metrics.
     *
     * @param array $m Metrics array from collect_metrics().
     * @return string HTML output.
     */
    private function render_block(array $m): string {
        $togglelabel = get_string('info_toggle', 'block_servermon');

        $html  = '<div class="block-servermon">';
        $html .= $this->render_print_header($m);
        $html .= $this->render_metric_row('cpu', $m['cpu']);
        $html .= $this->render_metric_row('ram', $m['ram']);
        $html .= $this->render_metric_row('disk', $m['disk']);
        $html .= $this->render_process_section();
        $html .= '<details class="bsm-details">';
        $html .= '<summary class="bsm-summary">' . $togglelabel . '</summary>';
        $html .= $this->render_info_table($m);
        $html .= '</details>';
        $html .= $this->render_isolation_section($m['isolation']);
        $html .= $this->render_debug_footer();
        $html .= $this->render_csv_link();
        $html .= $this->render_print_button();
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a print-only document header (hidden on screen).
     *
     * @param array $m Metrics array from collect_metrics().
     * @return string HTML output.
     */
    private function render_print_header(array $m): string {
        $text = get_string('print_header', 'block_servermon', (object) [
            'name' => get_string('pluginname', 'block_servermon'),
            'host' => $m['hostname'],
            'date' => $m['time'],
        ]);
        return '<div class="bsm-print-header">' . htmlspecialchars($text) . '</div>';
    }

    /**
     * Render the "Print / Save as PDF" button and its print helper script.
     *
     * The button triggers window.print(); a print stylesheet isolates the
     * block. Collapsed static sections are expanded for the print and then
     * restored, but the live process panel is left as-is.
     *
     * @return string HTML output.
     */
    private function render_print_button(): string {
        $instanceid = (int) $this->instance->id;
        $btnid      = 'bsm-print-' . $instanceid;

        // Inline JS is intentionally narrow: expand-for-print then window.print().
        // phpcs:disable moodle.Files.InlineJavaScript.Found
        $js = <<<JSEOF
(function() {
    var btn = document.getElementById('{$btnid}');
    if (!btn) { return; }
    var root = btn.closest('.block-servermon');
    if (!root) { return; }
    var opened = [];
    var hidden = [];
    function prepare() {
        // Expand static detail sections (leave the live process panel as-is).
        var items = root.querySelectorAll('details.bsm-details');
        for (var i = 0; i < items.length; i++) {
            var d = items[i];
            if (d.id && d.id.indexOf('bsm-proc-details') === 0) { continue; }
            if (!d.open) { d.open = true; opened.push(d); }
        }
        // Collapse everything except the block by hiding siblings up the tree,
        // so the rest of the dashboard does not print blank pages.
        var el = root;
        while (el && el.parentNode && el !== document.body) {
            var sibs = el.parentNode.children;
            for (var j = 0; j < sibs.length; j++) {
                var sib = sibs[j];
                if (sib !== el && sib.style.display !== 'none') {
                    hidden.push([sib, sib.style.display]);
                    sib.style.display = 'none';
                }
            }
            el = el.parentNode;
        }
    }
    function restore() {
        for (var i = 0; i < hidden.length; i++) { hidden[i][0].style.display = hidden[i][1]; }
        hidden = [];
        for (var k = 0; k < opened.length; k++) { opened[k].open = false; }
        opened = [];
    }
    window.addEventListener('beforeprint', prepare);
    window.addEventListener('afterprint', restore);
    btn.addEventListener('click', function(e) { e.preventDefault(); window.print(); });
})();
JSEOF;
        // phpcs:enable
        $this->page->requires->js_init_code($js, true);

        return '<div class="bsm-print-wrap">'
            . '<button type="button" id="' . $btnid . '" class="bsm-print-btn">'
            . get_string('print_button', 'block_servermon')
            . '</button>'
            . '</div>';
    }

    /**
     * Render a single metric row with label, value, progress bar and badge.
     *
     * For CPU, also renders per-core breakdown bars below the main row.
     *
     * @param string $type One of: cpu, ram, disk.
     * @param array $data Metric data array.
     * @return string HTML output.
     */
    private function render_metric_row(string $type, array $data): string {
        $pct    = $data['pct'];
        $label  = get_string("{$type}_label", 'block_servermon');
        $colour = $this->status_colour($pct);
        $badge  = $this->status_badge($pct);

        $valuedisplay = $pct !== null ? '<span class="bsm-pct">' . $pct . '%</span>' : '';
        $unavailmsg   = $pct === null
            ? '<div class="bsm-unavail">' . get_string('unavailable', 'block_servermon') . '</div>'
            : '';

        $html  = '<div class="bsm-row">';
        $html .= '<div class="bsm-row-header">';
        $html .= '<span class="bsm-label">' . $label . '</span>';
        $html .= '<span class="bsm-right">' . $valuedisplay . $badge . '</span>';
        $html .= '</div>';
        $html .= $this->render_bar($pct, $colour);
        $html .= $this->render_metric_detail($type, $data);
        $html .= $unavailmsg;
        $html .= $this->render_percore_bars($type, $data);
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the progress bar for a metric row, or empty string if unavailable.
     *
     * @param float|null $pct Percentage value.
     * @param string $colour CSS colour class suffix.
     * @return string HTML output.
     */
    private function render_bar(?float $pct, string $colour): string {
        if ($pct === null) {
            return '';
        }
        $barpct = min($pct, 100);
        return '<div class="bsm-bar-track">'
            . '<div class="bsm-bar-fill bsm-' . $colour . '" style="width:' . $barpct . '%"></div>'
            . '</div>';
    }

    /**
     * Render the detail line beneath a metric bar (load averages or GB breakdown).
     *
     * @param string $type Metric type: cpu, ram, or disk.
     * @param array $data Metric data array.
     * @return string HTML output.
     */
    private function render_metric_detail(string $type, array $data): string {
        if ($type === 'cpu' && $data['pct'] !== null && $data['load1'] !== null) {
            $detail = get_string('load_averages', 'block_servermon', (object)[
                'one'     => $data['load1'],
                'five'    => $data['load5'],
                'fifteen' => $data['load15'],
            ]);
            return '<div class="bsm-detail">' . $detail . '</div>';
        }
        if (in_array($type, ['ram', 'disk']) && $data['total'] !== null) {
            $detail = get_string($type . '_detail', 'block_servermon', (object)[
                'used'  => $data['used'],
                'total' => $data['total'],
                'free'  => $data['free'],
            ]);
            return '<div class="bsm-detail">' . $detail . '</div>';
        }
        return '';
    }

    /**
     * Render per-core CPU breakdown bars (CPU metric type only).
     *
     * @param string $type Metric type.
     * @param array $data Metric data array.
     * @return string HTML output.
     */
    private function render_percore_bars(string $type, array $data): string {
        if ($type !== 'cpu' || empty($data['percore'])) {
            return '';
        }
        $html = '<div class="bsm-percore">';
        foreach ($data['percore'] as $c) {
            $ccolour = $this->status_colour($c['pct']);
            $cbarpct = min($c['pct'], 100);
            $html .= '<div class="bsm-percore-row">';
            $html .= '<span class="bsm-percore-label">'
                . get_string('cpu_core', 'block_servermon', $c['core'])
                . '</span>';
            $html .= '<div class="bsm-bar-track bsm-percore-track">'
                . '<div class="bsm-bar-fill bsm-' . $ccolour
                . '" style="width:' . $cbarpct . '%"></div>'
                . '</div>';
            $html .= '<span class="bsm-percore-pct">' . $c['pct'] . '%</span>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render the collapsible top-processes section with live AJAX polling.
     *
     * Uses JavaScript fetch() + setInterval() to refresh every 5 seconds
     * while the <details> panel is open. Polling stops when the panel closes.
     *
     * @return string HTML output.
     */
    private function render_process_section(): string {
        $instanceid = $this->instance->id;
        $url        = (new \moodle_url('/blocks/servermon/process.php'))->out(false);
        $label      = get_string('proc_toggle', 'block_servermon');
        $loadingmsg = get_string('proc_loading', 'block_servermon');
        $emptymsg   = get_string('proc_empty', 'block_servermon');
        $errormsg   = get_string('proc_error', 'block_servermon');
        $hpid       = get_string('proc_pid', 'block_servermon');
        $hname      = get_string('proc_name', 'block_servermon');
        $hcpu       = get_string('proc_cpu', 'block_servermon');
        $hmem       = get_string('proc_mem', 'block_servermon');
        $hcore      = get_string('proc_core', 'block_servermon');

        // Inline JS is intentionally narrow: read-only AJAX poll, no user input.
        // phpcs:disable moodle.Files.InlineJavaScript.Found
        $js = <<<JSEOF
(function() {
    var url = {$this->json_encode_url($url)};
    var elId = 'bsm-proctable-{$instanceid}';
    var detId = 'bsm-proc-details-{$instanceid}';
    var emptyMsg = {$this->js_string($emptymsg)};
    var errorMsg = {$this->js_string($errormsg)};
    var hPid    = {$this->js_string($hpid)};
    var hName   = {$this->js_string($hname)};
    var hCpu    = {$this->js_string($hcpu)};
    var hMem    = {$this->js_string($hmem)};
    var hCore   = {$this->js_string($hcore)};
    var el, details, timer;

    function clearEl() {
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
    }

    function showMessage(text) {
        clearEl();
        var div = document.createElement('div');
        div.className = 'bsm-proc-empty';
        div.textContent = text;
        el.appendChild(div);
    }

    function makeCell(tag, cls, text) {
        var cell = document.createElement(tag);
        cell.className = cls;
        cell.textContent = text;
        return cell;
    }

    function render(data) {
        if (!data || !Array.isArray(data.processes) || data.processes.length === 0) {
            showMessage(emptyMsg);
            return;
        }
        var table = document.createElement('table');
        table.className = 'bsm-proc-table';
        var thead = document.createElement('thead');
        var headrow = document.createElement('tr');
        headrow.appendChild(makeCell('th', 'bsm-proc-pid', hPid));
        headrow.appendChild(makeCell('th', 'bsm-proc-name', hName));
        headrow.appendChild(makeCell('th', 'bsm-proc-num', hCpu));
        headrow.appendChild(makeCell('th', 'bsm-proc-num', hMem));
        headrow.appendChild(makeCell('th', 'bsm-proc-core', hCore));
        thead.appendChild(headrow);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        data.processes.forEach(function(p) {
            var core = (p.cpu_core !== null && p.cpu_core !== undefined)
                ? String(p.cpu_core) : '\u2014';
            var row = document.createElement('tr');
            row.appendChild(makeCell('td', 'bsm-proc-pid', String(p.pid)));
            row.appendChild(makeCell('td', 'bsm-proc-name', String(p.name)));
            row.appendChild(makeCell('td', 'bsm-proc-num', p.cpu_pct + '%'));
            row.appendChild(makeCell('td', 'bsm-proc-num', p.mem_pct + '%'));
            row.appendChild(makeCell('td', 'bsm-proc-core', core));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        clearEl();
        el.appendChild(table);
    }

    function poll() {
        fetch(url, {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(render)
            .catch(function() {
                showMessage(errorMsg);
            });
    }

    function init() {
        el      = document.getElementById(elId);
        details = document.getElementById(detId);
        if (!el || !details) { return; }

        details.addEventListener('toggle', function() {
            if (details.open) {
                poll();
                timer = setInterval(poll, 5000);
            } else {
                clearInterval(timer);
                timer = null;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JSEOF;
        // phpcs:enable
        $this->page->requires->js_init_code($js, true);

        $containerid = 'bsm-proctable-' . $instanceid;
        $detailsid   = 'bsm-proc-details-' . $instanceid;

        return '<details class="bsm-details" id="' . $detailsid . '">'
            . '<summary class="bsm-summary">' . $label . '</summary>'
            . '<div id="' . $containerid . '" class="bsm-proc-container">'
            . '<div class="bsm-proc-loading">' . $loadingmsg . '</div>'
            . '</div>'
            . '</details>';
    }

    /**
     * JSON-encode a URL string for safe embedding in JavaScript.
     *
     * @param string $url The URL to encode.
     * @return string A JSON-encoded string literal (including surrounding quotes).
     */
    private function json_encode_url(string $url): string {
        return json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }

    /**
     * Encode a plain string as a safe JavaScript string literal.
     *
     * @param string $s The string to encode.
     * @return string A JSON-encoded string literal (including surrounding quotes).
     */
    private function js_string(string $s): string {
        return json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }

    /**
     * Render the server info table section.
     *
     * @param array $m Metrics array from collect_metrics().
     * @return string HTML output.
     */
    private function render_info_table(array $m): string {
        $rows = [
            get_string('uptime_label', 'block_servermon')    => $m['uptime'] ?? get_string('unavailable', 'block_servermon'),
            get_string('php_label', 'block_servermon')       => htmlspecialchars($m['php']),
            get_string('os_label', 'block_servermon')        => htmlspecialchars($m['os']),
            get_string('hostname_label', 'block_servermon')  => htmlspecialchars($m['hostname']),
            get_string('webserver_label', 'block_servermon') => htmlspecialchars($m['webserver']),
            get_string('hosting_label', 'block_servermon')   => htmlspecialchars($m['hosting']['label'])
                . ($m['hosting']['reasons']
                    ? '<br><span class="bsm-hosting-reasons">'
                        . implode(', ', array_map('htmlspecialchars', $m['hosting']['reasons']))
                        . '</span>'
                    : ''),
            get_string('timestamp_label', 'block_servermon') => htmlspecialchars($m['time']),
        ];

        $html = '<table class="bsm-info-table">';
        foreach ($rows as $key => $val) {
            $html .= '<tr>';
            $html .= '<td class="bsm-info-key">' . $key . '</td>';
            $html .= '<td class="bsm-info-val">' . $val . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    /**
     * Render the collapsible OS-users and PHP-FPM-pools section.
     *
     * @param array $iso Isolation info from get_isolation_info().
     * @return string HTML output.
     */
    private function render_isolation_section(array $iso): string {
        $label = get_string('iso_toggle', 'block_servermon');

        $poolusers = [];
        foreach ($iso['pools']['pools'] as $pool) {
            if ($pool['user'] !== '' && strpos($pool['user'], '$') === false) {
                $poolusers[$pool['user']] = true;
            }
        }
        $context = ['current' => $iso['pools']['currentuser'], 'poolusers' => $poolusers];

        $body  = $this->render_isolation_current($iso['pools']);
        $body .= $this->render_isolation_users($iso['users'], $context);
        $body .= $this->render_isolation_pools($iso['pools']);
        $body .= $this->render_isolation_procvis($iso['procvis']);
        $body .= $this->render_isolation_verdict($iso['verdict']);

        return '<details class="bsm-details">'
            . '<summary class="bsm-summary">' . $label . '</summary>'
            . '<div class="bsm-iso-body">' . $body . '</div>'
            . '</details>';
    }

    /**
     * Render the banner stating which OS user this request runs as.
     *
     * @param array $p Pool info from get_fpm_pools().
     * @return string HTML output.
     */
    private function render_isolation_current(array $p): string {
        if ($p['currentuser'] === null) {
            return '';
        }
        $bad   = $this->is_generic_user($p['currentuser']) || strtolower($p['currentuser']) === 'root';
        $alert = $bad ? 'bsm-alert-warn' : 'bsm-alert-info';
        $key   = $bad ? 'iso_current_generic' : 'iso_current_dedicated';

        return '<div class="bsm-debug-alert ' . $alert . '">'
            . get_string($key, 'block_servermon', (object) [
                'user' => htmlspecialchars($p['currentuser']),
                'sapi' => htmlspecialchars($p['sapi']),
            ])
            . '</div>';
    }

    /**
     * Render the operating-system users sub-section.
     *
     * @param array $u OS user info from get_os_users().
     * @param array $context Keys: current (string|null), poolusers (name => true).
     * @return string HTML output.
     */
    private function render_isolation_users(array $u, array $context): string {
        $html = '<h6 class="bsm-debug-section-title">' . get_string('iso_users_title', 'block_servermon') . '</h6>';

        if (!$u['readable']) {
            return $html . '<div class="bsm-iso-note">' . get_string('iso_users_unavailable', 'block_servermon') . '</div>';
        }

        $html .= '<div class="bsm-iso-intro">' . get_string('iso_users_intro', 'block_servermon') . '</div>';

        if (empty($u['users'])) {
            $html .= '<div class="bsm-iso-note">' . get_string('iso_users_none', 'block_servermon') . '</div>';
        } else {
            $html .= '<table class="bsm-info-table bsm-iso-table">';
            $html .= '<tr>'
                . '<td class="bsm-info-key">' . get_string('iso_user_name', 'block_servermon') . '</td>'
                . '<td class="bsm-info-key">' . get_string('iso_user_uid', 'block_servermon') . '</td>'
                . '<td class="bsm-info-key">' . get_string('iso_user_shell', 'block_servermon') . '</td>'
                . '</tr>';
            foreach ($u['users'] as $row) {
                $namecell = htmlspecialchars($row['name']) . $this->user_tags($row['name'], $context);
                $html .= '<tr>'
                    . '<td class="bsm-info-val">' . $namecell . '</td>'
                    . '<td class="bsm-info-val">' . (int) $row['uid'] . '</td>'
                    . '<td class="bsm-info-val">' . htmlspecialchars($row['shell']) . '</td>'
                    . '</tr>';
            }
            $html .= '</table>';
        }

        if ($u['systemcount'] > 0) {
            $html .= '<div class="bsm-iso-note">'
                . get_string('iso_systemcount', 'block_servermon', $u['systemcount'])
                . '</div>';
        }

        return $html;
    }

    /**
     * Build the small tags shown next to a user (current request / FPM pool owner).
     *
     * @param string $name Username.
     * @param array $context Keys: current (string|null), poolusers (name => true).
     * @return string HTML output (may be empty).
     */
    private function user_tags(string $name, array $context): string {
        $tags = '';
        if ($name === $context['current']) {
            $tags .= ' <span class="bsm-iso-tag">' . get_string('iso_tag_current', 'block_servermon') . '</span>';
        }
        if (!empty($context['poolusers'][$name])) {
            $tags .= ' <span class="bsm-iso-tag">' . get_string('iso_tag_pool', 'block_servermon') . '</span>';
        }
        return $tags;
    }

    /**
     * Render the PHP-FPM pools sub-section.
     *
     * @param array $p Pool info from get_fpm_pools().
     * @return string HTML output.
     */
    private function render_isolation_pools(array $p): string {
        $html = '<h6 class="bsm-debug-section-title">' . get_string('iso_pools_title', 'block_servermon') . '</h6>';

        if (empty($p['pools'])) {
            $key = $p['found'] ? 'iso_pools_unreadable' : 'iso_pools_none';
            return $html . '<div class="bsm-iso-note">' . get_string($key, 'block_servermon') . '</div>';
        }

        $html .= '<div class="bsm-iso-intro">' . get_string('iso_pools_intro', 'block_servermon') . '</div>';
        foreach ($p['pools'] as $pool) {
            $html .= $this->render_pool_card($pool);
        }

        if ($p['unreadable'] > 0) {
            $html .= '<div class="bsm-iso-note">'
                . get_string('iso_pools_some_unreadable', 'block_servermon', $p['unreadable'])
                . '</div>';
        }

        return $html;
    }

    /**
     * Render a single PHP-FPM pool as a card with its hardening status.
     *
     * @param array $pool Evaluated pool record.
     * @return string HTML output.
     */
    private function render_pool_card(array $pool): string {
        $user = $pool['user'] !== '' ? $pool['user'] : '—';
        if ($pool['group'] !== '' && $pool['group'] !== $pool['user']) {
            $user .= ':' . $pool['group'];
        }
        $listen = $pool['listen'] !== '' ? $pool['listen'] : '—';

        $badge = $pool['ok']
            ? '<span class="bsm-badge bsm-ok">' . get_string('iso_flag_ok', 'block_servermon') . '</span>'
            : '<span class="bsm-badge bsm-high">' . get_string('iso_pool_issues', 'block_servermon') . '</span>';

        $html  = '<div class="bsm-iso-pool">';
        $html .= '<div class="bsm-iso-pool-head">'
            . '<span class="bsm-iso-pool-name">' . htmlspecialchars($pool['name']) . '</span>'
            . $badge
            . '</div>';
        $html .= '<div class="bsm-iso-pool-meta">'
            . get_string('iso_pool_meta', 'block_servermon', (object) [
                'user'   => htmlspecialchars($user),
                'listen' => htmlspecialchars($listen),
            ])
            . '</div>';
        $html .= '<div class="bsm-iso-pool-meta">' . $this->render_pool_hardening($pool) . '</div>';

        if (!empty($pool['issues'])) {
            $html .= '<div class="bsm-iso-flags">';
            foreach ($pool['issues'] as $code) {
                $html .= '<span class="bsm-iso-flag">'
                    . htmlspecialchars(get_string('iso_flag_' . $code, 'block_servermon'))
                    . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render the per-pool hardening summary line (open_basedir, chroot, socket mode).
     *
     * @param array $pool Evaluated pool record.
     * @return string HTML output.
     */
    private function render_pool_hardening(array $pool): string {
        $dash  = '—';
        $parts = [];
        $parts[] = get_string('iso_hard_openbasedir', 'block_servermon') . ': '
            . ($pool['open_basedir'] !== '' ? htmlspecialchars($pool['open_basedir']) : $dash);
        if ($pool['chroot'] !== '') {
            $parts[] = get_string('iso_hard_chroot', 'block_servermon') . ': ' . htmlspecialchars($pool['chroot']);
        }
        if ($pool['listen_mode'] !== '') {
            $parts[] = get_string('iso_hard_mode', 'block_servermon') . ': ' . htmlspecialchars($pool['listen_mode']);
        }
        return implode(' · ', $parts);
    }

    /**
     * Render the /proc process-visibility (hidepid) sub-section.
     *
     * @param array $pv Result from get_proc_visibility().
     * @return string HTML output.
     */
    private function render_isolation_procvis(array $pv): string {
        if (empty($pv['checked'])) {
            return '';
        }

        $html = '<h6 class="bsm-debug-section-title">' . get_string('iso_proc_title', 'block_servermon') . '</h6>';

        if ($pv['count'] === 0) {
            return $html . '<div class="bsm-debug-alert bsm-alert-info">'
                . get_string('iso_proc_ok', 'block_servermon') . '</div>';
        }

        $shown = array_slice($pv['foreign'], 0, 8);
        $names = implode(', ', array_map('htmlspecialchars', $shown));
        if (count($pv['foreign']) > count($shown)) {
            $names .= ', …';
        }
        return $html . '<div class="bsm-debug-alert bsm-alert-warn">'
            . get_string('iso_proc_leak', 'block_servermon', (object) [
                'count' => $pv['count'],
                'users' => $names,
            ])
            . '</div>';
    }

    /**
     * Render the isolation verdict alert box.
     *
     * @param array $verdict Verdict from assess_isolation().
     * @return string HTML output.
     */
    private function render_isolation_verdict(array $verdict): string {
        $alert = in_array($verdict['level'], ['weak', 'incomplete', 'partial'], true) ? 'bsm-alert-warn' : 'bsm-alert-info';

        return '<h6 class="bsm-debug-section-title">' . get_string('iso_verdict_title', 'block_servermon') . '</h6>'
            . '<div class="bsm-debug-alert ' . $alert . '">' . htmlspecialchars($verdict['label']) . '</div>';
    }

    /**
     * Render the Moodle debug-footer dropdown with page performance metrics.
     *
     * @return string HTML output.
     */
    private function render_debug_footer(): string {
        $d     = $this->collect_debug_metrics();
        $label = get_string('debug_toggle', 'block_servermon');

        $html  = $this->render_debug_metric_cards($d);
        $html .= $this->render_debug_session($d['session']);

        if (!empty($d['cachestats'])) {
            $html .= $this->render_cache_section($d['cachestats']);
        }

        $html .= $this->render_opcache_section($d['opcache']);
        $html .= $this->render_prod_section($d['prod']);
        $html .= $this->render_health_section($d['health']);

        if ($d['observation'] !== '') {
            $html .= '<h6 class="bsm-debug-section-title">' . get_string('debug_obs', 'block_servermon') . '</h6>';
            $html .= '<div class="bsm-debug-alert bsm-alert-warn">'
                . htmlspecialchars($d['observation'])
                . '</div>';
        }

        return '<details class="bsm-details">'
            . '<summary class="bsm-summary">' . $label . '</summary>'
            . '<div class="bsm-debug-body">' . $html . '</div>'
            . '</details>';
    }

    /**
     * Render the four summary metric cards (page time, memory, DB reads/writes, DB time).
     *
     * @param array $d Debug metrics array from collect_debug_metrics().
     * @return string HTML output.
     */
    private function render_debug_metric_cards(array $d): string {
        $unavail = get_string('unavailable', 'block_servermon');
        $html  = '<div class="bsm-debug-grid">';
        $html .= $this->metric_card(
            get_string('debug_pagetime', 'block_servermon'),
            $d['pagetime'] !== null ? $d['pagetime'] . ' s' : $unavail
        );
        $html .= $this->metric_card(
            get_string('debug_memory', 'block_servermon'),
            $d['memory'] !== null ? $d['memory'] . ' MB' : $unavail
        );
        $html .= $this->metric_card(
            get_string('debug_dbrw', 'block_servermon'),
            $d['dbreads'] !== null ? $d['dbreads'] . ' / ' . $d['dbwrites'] : $unavail
        );
        $html .= $this->metric_card(
            get_string('debug_dbtime', 'block_servermon'),
            $d['dbtime'] !== null ? $d['dbtime'] . ' s' : $unavail
        );
        $html .= '</div>';
        return $html;
    }

    /**
     * Render the session handler info section.
     *
     * @param array $sess Session info array from get_session_info().
     * @return string HTML output.
     */
    private function render_debug_session(array $sess): string {
        $unavail    = get_string('unavailable', 'block_servermon');
        $sessdetail = get_string('debug_session_detail', 'block_servermon', (object)[
            'type' => htmlspecialchars($sess['type']),
            'size' => $sess['size'] ?? $unavail,
        ]);

        // A file handler with Redis settings present is a misconfiguration, not just
        // a missing optimisation — flag it as a warning rather than a passive notice.
        $misconfigured = $sess['type'] === 'file' && !empty($sess['redisconfigured']);
        $sessalert = ($sess['type'] === 'file') ? 'bsm-alert-warn' : 'bsm-alert-info';
        $html  = '<h6 class="bsm-debug-section-title">' . get_string('debug_session', 'block_servermon') . '</h6>';
        $html .= '<div class="bsm-debug-alert ' . $sessalert . '">' . $sessdetail;

        // Always show the configured handler class so the active backend is verifiable.
        $handler = $sess['handlerclass'] !== ''
            ? $sess['handlerclass']
            : get_string('debug_session_handler_unset', 'block_servermon');
        $html .= '<br>' . get_string('debug_session_handler', 'block_servermon', htmlspecialchars($handler));

        if ($sess['type'] === 'redis' && $sess['redis'] !== null) {
            $r     = $sess['redis'];
            $html .= '<br>' . get_string('debug_session_redis', 'block_servermon', (object)[
                'host'         => htmlspecialchars($r['host']),
                'port'         => $r['port'],
                'db'           => $r['db'],
                'prefix'       => htmlspecialchars($r['prefix']),
                'lock_timeout' => $r['lock_timeout'],
                'lock_expire'  => $r['lock_expire'],
            ]);
        } else if ($misconfigured) {
            // Redis connection details exist but the handler is still file-based.
            $r     = $sess['redis'];
            $html .= '<br>' . get_string('debug_session_redis_inactive', 'block_servermon', (object)[
                'host' => htmlspecialchars($r['host']),
                'port' => $r['port'],
            ]);
        } else if ($sess['type'] === 'file') {
            $html .= '<br>' . get_string('debug_session_warn', 'block_servermon');
        }

        // Surface a missing PHP extension whenever Redis is intended (active or configured).
        if (($sess['type'] === 'redis' || !empty($sess['redisconfigured'])) && empty($sess['redisext'])) {
            $html .= '<br>' . get_string('debug_session_redis_noext', 'block_servermon');
        }

        // Surface config-level Redis sharing/security findings.
        if ($sess['redis'] !== null && !empty($sess['redis']['findings'])) {
            $host = htmlspecialchars($sess['redis']['host']);
            foreach ($sess['redis']['findings'] as $code) {
                $html .= '<br>' . get_string('redis_finding_' . $code, 'block_servermon', $host);
            }
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render a single metric stat card.
     *
     * @param string $label Card label.
     * @param string $value Card value.
     * @return string HTML output.
     */
    private function metric_card(string $label, string $value): string {
        return '<div class="bsm-metric-card">'
            . '<div class="bsm-metric-label">' . $label . '</div>'
            . '<div class="bsm-metric-value">' . $value . '</div>'
            . '</div>';
    }

    /**
     * Render the cache store performance section.
     *
     * @param array $cachestats Aggregated cache stats from get_cache_stats().
     * @return string HTML output.
     */
    private function render_cache_section(array $cachestats): string {
        $html  = '<h6 class="bsm-debug-section-title">' . get_string('debug_cache_title', 'block_servermon') . '</h6>';
        $html .= '<div class="bsm-cache-header">'
            . '<span class="bsm-cache-name">' . get_string('debug_cache_store', 'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_hits', 'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_misses', 'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_io', 'block_servermon') . '</span>'
            . '</div>';

        $static = $cachestats['static'];
        if ($static['hits'] > 0 || $static['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_static', 'block_servermon'),
                $static['hits'],
                $static['misses'],
                0,
                true
            );
        }

        $app   = $cachestats['app'];
        $html .= $this->cache_div_row(
            get_string('debug_cache_app', 'block_servermon', $this->get_store_type_label($app['store'])),
            $app['hits'],
            $app['misses'],
            $app['bytes'],
            false
        );

        $req = $cachestats['request'];
        if ($req['hits'] > 0 || $req['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_request', 'block_servermon'),
                $req['hits'],
                $req['misses'],
                0,
                false
            );
        }

        $sess = $cachestats['session'];
        if ($sess['hits'] > 0 || $sess['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_session', 'block_servermon'),
                $sess['hits'],
                $sess['misses'],
                0,
                false
            );
        }

        return $html;
    }

    /**
     * Render a single cache store row as a styled div.
     *
     * @param string $label Store label.
     * @param int $hits Cache hits.
     * @param int $misses Cache misses.
     * @param int $bytes Bytes read/written (0 = show dash).
     * @param bool $highlight True for the green top-store highlight style.
     * @return string HTML output.
     */
    private function cache_div_row(string $label, int $hits, int $misses, int $bytes, bool $highlight): string {
        $cls = 'bsm-cache-row' . ($highlight ? ' bsm-cache-highlight' : '');
        $io  = $bytes > 0 ? $this->format_bytes($bytes) : '—';

        return '<div class="' . $cls . '">'
            . '<span class="bsm-cache-name">' . $label  . '</span>'
            . '<span class="bsm-cache-stat">' . $hits   . '</span>'
            . '<span class="bsm-cache-stat">' . $misses . '</span>'
            . '<span class="bsm-cache-stat">' . $io     . '</span>'
            . '</div>';
    }

    /**
     * Collect Moodle page-performance metrics for the debug footer.
     *
     * @return array Keys: pagetime, memory, dbreads, dbwrites, dbtime, session, cachestats, observation.
     */
    private function collect_debug_metrics(): array {
        global $DB;

        $result = [
            'pagetime'    => null,
            'memory'      => null,
            'dbreads'     => null,
            'dbwrites'    => null,
            'dbtime'      => null,
            'session'     => ['type' => 'file', 'size' => null],
            'cachestats'  => [],
            'opcache'     => [],
            'prod'        => [],
            'health'      => [],
            'observation' => '',
        ];

        // Page load time.
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $result['pagetime'] = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2);
        }

        // Peak memory in MB.
        $result['memory'] = round(memory_get_peak_usage(false) / 1048576, 1);

        // DB reads/writes/query-time.
        if (isset($DB) && method_exists($DB, 'perf_get_reads')) {
            $result['dbreads']  = $DB->perf_get_reads();
            $result['dbwrites'] = $DB->perf_get_writes();
            if (method_exists($DB, 'perf_get_queries_time')) {
                $result['dbtime'] = round($DB->perf_get_queries_time(), 3);
            }
        }

        // Session info.
        $result['session'] = $this->get_session_info();

        // MUC cache stats.
        $result['cachestats'] = $this->get_cache_stats();

        // PHP OPcache health.
        $result['opcache'] = $this->get_opcache_info();

        // Moodle production-readiness configuration flags.
        $result['prod'] = $this->get_prod_readiness();

        // Server health: swap usage and cron freshness.
        $result['health'] = $this->get_health_info();

        // Advisory observation.
        $result['observation'] = $this->build_observation($result);

        return $result;
    }

    /**
     * Determine the current Moodle session type and size.
     *
     * @return array Keys: type, size, redis.
     */
    private function get_session_info(): array {
        global $CFG;

        $handlerclass = isset($CFG->session_handler_class) ? (string)$CFG->session_handler_class : '';

        $type = 'file';
        if ($handlerclass !== '') {
            $cls = strtolower($handlerclass);
            if (strpos($cls, 'redis') !== false) {
                $type = 'redis';
            } else if (strpos($cls, 'memcached') !== false) {
                $type = 'memcached';
            } else if (strpos($cls, 'database') !== false || strpos($cls, '_db') !== false) {
                $type = 'database';
            }
        }

        $size = null;
        if (isset($_SESSION)) {
            $bytes = strlen(serialize($_SESSION));
            $size  = $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
        }

        // Redis session connection settings may be present in config.php even when the
        // session handler is not actually pointed at Redis. Detecting this independently
        // surfaces the common "Redis was configured but sessions are still file-based"
        // misconfiguration instead of silently reporting "file".
        $redisconfigured = !empty($CFG->session_redis_host);

        $info = [
            'type'            => $type,
            'size'            => $size,
            'redis'           => null,
            'handlerclass'    => $handlerclass,
            'redisconfigured' => $redisconfigured,
            'redisext'        => extension_loaded('redis'),
        ];

        if ($type === 'redis' || $redisconfigured) {
            $redis = [
                'host' => $CFG->session_redis_host ?? '127.0.0.1',
                'port' => $CFG->session_redis_port ?? 6379,
                'db' => $CFG->session_redis_database ?? 0,
                'prefix' => $CFG->session_redis_prefix ?? '',
                'lock_timeout' => $CFG->session_redis_acquire_lock_timeout ?? 120,
                'lock_expire' => $CFG->session_redis_lock_expire ?? 7200,
                'auth' => !empty($CFG->session_redis_auth),
            ];
            $redis['findings'] = $this->redis_config_findings($redis);
            $info['redis'] = $redis;
        }

        return $info;
    }

    /**
     * Identify configuration-level risks with the Redis session setup.
     *
     * Best-effort signals from config.php alone — no live connection. An empty
     * key prefix means a Redis instance shared with another Moodle site would
     * let the two collide with or evict each other's session keys; a
     * non-loopback host implies a shared Redis server rather than a per-site
     * instance; and no password on such a host is a security exposure.
     *
     * @param array $r Redis connection settings (host, prefix, auth, …).
     * @return array List of finding codes: noprefix, remote, noauth.
     */
    private function redis_config_findings(array $r): array {
        $findings = [];
        if (trim((string) $r['prefix']) === '') {
            $findings[] = 'noprefix';
        }
        if (!$this->redis_host_is_local((string) $r['host'])) {
            $findings[] = 'remote';
            if (empty($r['auth'])) {
                $findings[] = 'noauth';
            }
        }
        return $findings;
    }

    /**
     * Whether a Redis host string refers to the local machine.
     *
     * @param string $host Configured Redis host, or a unix socket path.
     * @return bool
     */
    private function redis_host_is_local(string $host): bool {
        $host = strtolower(trim($host));
        if ($host === '' || $host[0] === '/') {
            return true; // Default/empty, or a unix socket path.
        }
        return in_array($host, ['127.0.0.1', '::1', 'localhost', 'ip6-localhost'], true);
    }

    /**
     * Collect and aggregate MUC cache statistics by cache mode.
     *
     * Returns an empty array when cache_helper is unavailable or returns no data.
     *
     * @return array Keys: static, app, session, request — each with hits, misses, bytes, store.
     */
    private function get_cache_stats(): array {
        if (!class_exists('cache_helper') || !method_exists('cache_helper', 'get_stats')) {
            return [];
        }

        try {
            $raw = cache_helper::get_stats();
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $modeapp = class_exists('cache_store') ? cache_store::MODE_APPLICATION : 1;
        $modesession = class_exists('cache_store') ? cache_store::MODE_SESSION : 2;
        $moderequest = class_exists('cache_store') ? cache_store::MODE_REQUEST : 4;

        $agg = [
            'static'  => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'app'     => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'session' => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'request' => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
        ];

        foreach ($raw as $data) {
            if (!is_array($data) || empty($data['stores'])) {
                continue;
            }
            $mode = (int)($data['mode'] ?? $modeapp);
            foreach ($data['stores'] as $storename => $entry) {
                $agg = $this->aggregate_cache_store_entry(
                    $agg,
                    $storename,
                    $entry,
                    $mode,
                    $modeapp,
                    $modesession,
                    $moderequest
                );
            }
        }

        return $agg;
    }

    /**
     * Aggregate a single cache store entry into the running totals array.
     *
     * @param array $agg Running aggregates (static, app, session, request).
     * @param string $storename Store instance name.
     * @param mixed $entry Raw entry data from cache_helper::get_stats().
     * @param int $mode Cache mode for this definition.
     * @param int $modeapp Application cache mode constant.
     * @param int $modesession Session cache mode constant.
     * @param int $moderequest Request cache mode constant.
     * @return array Updated aggregates.
     */
    private function aggregate_cache_store_entry(
        array $agg,
        string $storename,
        $entry,
        int $mode,
        int $modeapp,
        int $modesession,
        int $moderequest
    ): array {
        if (!is_array($entry)) {
            return $agg;
        }

        $hits       = (int)($entry['hits'] ?? 0);
        $misses     = (int)($entry['misses'] ?? 0);
        $iobytes    = (int)($entry['iobytes'] ?? -1);
        $bytes      = $iobytes > 0 ? $iobytes : 0;
        $storeclass = strtolower($entry['class'] ?? $storename);

        if (stripos($storename, 'static') !== false || strpos($storeclass, 'static') !== false) {
            $agg['static']['hits']   += $hits;
            $agg['static']['misses'] += $misses;
            return $agg;
        }

        if ($mode === $modeapp) {
            $agg['app']['hits']   += $hits;
            $agg['app']['misses'] += $misses;
            $agg['app']['bytes']  += $bytes;
            if (!$agg['app']['store']) {
                $agg['app']['store'] = $storeclass;
            }
        } else if ($mode === $modesession) {
            $agg['session']['hits']   += $hits;
            $agg['session']['misses'] += $misses;
            $agg['session']['bytes']  += $bytes;
            if (!$agg['session']['store']) {
                $agg['session']['store'] = $storeclass;
            }
        } else if ($mode === $moderequest) {
            $agg['request']['hits']   += $hits;
            $agg['request']['misses'] += $misses;
            $agg['request']['bytes']  += $bytes;
        }

        return $agg;
    }

    /**
     * Build an advisory observation string based on collected debug metrics.
     *
     * @param array $d Debug metrics from collect_debug_metrics().
     * @return string Observation text, or empty string if nothing notable.
     */
    private function build_observation(array $d): string {
        if (empty($d['cachestats'])) {
            return '';
        }

        $app   = $d['cachestats']['app'];
        $total = $app['hits'] + $app['misses'];

        if ($total > 0) {
            $missrate = round(($app['misses'] / $total) * 100);
            $storekey = $this->get_store_type_key($app['store']);

            if ($missrate >= 50 && $storekey === 'file') {
                return get_string('obs_cache_file', 'block_servermon', $missrate);
            }

            if ($missrate >= 50 && $storekey === 'redis') {
                return get_string('obs_cache_redis', 'block_servermon', $missrate);
            }
        }

        return '';
    }

    /**
     * Derive a store type key from a raw store name.
     *
     * @param string $store Raw store name from cache stats.
     * @return string One of: redis, memcached, apcu, file.
     */
    private function get_store_type_key(string $store): string {
        $lower = strtolower($store);
        if (strpos($lower, 'redis') !== false) {
            return 'redis';
        }
        if (strpos($lower, 'memcach') !== false) {
            return 'memcached';
        }
        if (strpos($lower, 'apcu') !== false || strpos($lower, 'apc') !== false) {
            return 'apcu';
        }
        return 'file';
    }

    /**
     * Derive a human-readable store type label from a raw store name.
     *
     * @param string $store Raw store name from cache stats.
     * @return string Translated label, e.g. "file store", "redis store".
     */
    private function get_store_type_label(string $store): string {
        return get_string('store_' . $this->get_store_type_key($store), 'block_servermon');
    }

    // OPcache health.

    /**
     * Read PHP OPcache status and key configuration directives.
     *
     * Degrades gracefully when the extension is missing or its API is
     * restricted (opcache.restrict_api).
     *
     * @return array Keys: available, enabled, hitrate, memused, keysused,
     *               keysmax, oomrestarts, cachedscripts, validate, jit.
     */
    private function get_opcache_info(): array {
        $result = [
            'available'     => false,
            'enabled'       => false,
            'hitrate'       => null,
            'memused'       => null,
            'keysused'      => null,
            'keysmax'       => null,
            'oomrestarts'   => null,
            'cachedscripts' => null,
            'validate'      => null,
            'jit'           => null,
        ];

        if (!function_exists('opcache_get_status')) {
            return $result;
        }
        $result['available'] = true;

        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return $result; // API restricted or OPcache disabled.
        }
        $result['enabled'] = !empty($status['opcache_enabled']);

        if (isset($status['opcache_statistics']) && is_array($status['opcache_statistics'])) {
            $s = $status['opcache_statistics'];
            $result['hitrate']       = isset($s['opcache_hit_rate']) ? round((float) $s['opcache_hit_rate'], 1) : null;
            $result['oomrestarts']   = isset($s['oom_restarts']) ? (int) $s['oom_restarts'] : null;
            $result['cachedscripts'] = isset($s['num_cached_scripts']) ? (int) $s['num_cached_scripts'] : null;
            $result['keysused']      = isset($s['num_cached_keys']) ? (int) $s['num_cached_keys'] : null;
            $result['keysmax']       = isset($s['max_cached_keys']) ? (int) $s['max_cached_keys'] : null;
        }

        if (isset($status['memory_usage']) && is_array($status['memory_usage'])) {
            $mu     = $status['memory_usage'];
            $used   = (float) ($mu['used_memory'] ?? 0);
            $free   = (float) ($mu['free_memory'] ?? 0);
            $wasted = (float) ($mu['wasted_memory'] ?? 0);
            $total  = $used + $free + $wasted;
            $result['memused'] = $total > 0 ? round(($used / $total) * 100, 1) : null;
        }

        $config = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : false;
        if (is_array($config) && isset($config['directives']) && is_array($config['directives'])) {
            $d = $config['directives'];
            $result['validate'] = !empty($d['opcache.validate_timestamps']);
            $jit = $d['opcache.jit'] ?? null;
            $result['jit'] = $this->opcache_jit_active($jit) ? (string) $jit : null;
        }

        return $result;
    }

    /**
     * Whether an opcache.jit directive value represents an active JIT.
     *
     * @param mixed $jit Raw directive value (string, bool, or null).
     * @return bool
     */
    private function opcache_jit_active($jit): bool {
        if ($jit === null || $jit === false || $jit === '') {
            return false;
        }
        $val = strtolower((string) $jit);
        return !in_array($val, ['disable', 'off', '0'], true);
    }

    /**
     * Render the OPcache health section.
     *
     * @param array $o OPcache info from get_opcache_info().
     * @return string HTML output.
     */
    private function render_opcache_section(array $o): string {
        $html = '<h6 class="bsm-debug-section-title">' . get_string('opcache_title', 'block_servermon') . '</h6>';

        if (empty($o['available'])) {
            return $html . '<div class="bsm-debug-alert bsm-alert-info">'
                . get_string('opcache_unavailable', 'block_servermon') . '</div>';
        }
        if (empty($o['enabled'])) {
            return $html . '<div class="bsm-debug-alert bsm-alert-warn">'
                . get_string('opcache_disabled', 'block_servermon') . '</div>';
        }

        $unavail = get_string('unavailable', 'block_servermon');
        $html .= '<div class="bsm-debug-grid">';
        $html .= $this->metric_card(
            get_string('opcache_hitrate', 'block_servermon'),
            $o['hitrate'] !== null ? $o['hitrate'] . '%' : $unavail
        );
        $html .= $this->metric_card(
            get_string('opcache_memory', 'block_servermon'),
            $o['memused'] !== null ? $o['memused'] . '%' : $unavail
        );
        $html .= $this->metric_card(
            get_string('opcache_scripts', 'block_servermon'),
            $o['cachedscripts'] !== null ? (string) $o['cachedscripts'] : $unavail
        );
        $html .= $this->metric_card(
            get_string('opcache_jit', 'block_servermon'),
            $o['jit'] !== null
                ? get_string('opcache_jit_on', 'block_servermon')
                : get_string('opcache_jit_off', 'block_servermon')
        );
        $html .= '</div>';

        foreach ($this->opcache_advisories($o) as $adv) {
            $html .= '<div class="bsm-debug-alert ' . $adv['alert'] . '">'
                . htmlspecialchars($adv['text']) . '</div>';
        }

        return $html;
    }

    /**
     * Build advisory messages for the OPcache section.
     *
     * @param array $o OPcache info from get_opcache_info().
     * @return array List of ['alert' => css class, 'text' => message].
     */
    private function opcache_advisories(array $o): array {
        $out = [];

        if (!empty($o['oomrestarts'])) {
            $out[] = [
                'alert' => 'bsm-alert-warn',
                'text'  => get_string('opcache_oom', 'block_servermon', $o['oomrestarts']),
            ];
        }

        $keysfull = !empty($o['keysmax']) && $o['keysused'] !== null
            && ($o['keysused'] / $o['keysmax']) >= 0.9;
        if ($keysfull) {
            $out[] = [
                'alert' => 'bsm-alert-warn',
                'text'  => get_string('opcache_keysfull', 'block_servermon', (object) [
                    'used' => $o['keysused'],
                    'max'  => $o['keysmax'],
                ]),
            ];
        }

        if (!empty($o['validate'])) {
            $out[] = [
                'alert' => 'bsm-alert-info',
                'text'  => get_string('opcache_validate', 'block_servermon'),
            ];
        }

        return $out;
    }

    // Production-readiness configuration flags.

    /**
     * Evaluate Moodle configuration flags that matter for a production site.
     *
     * @return array List of ['key' => string, 'ok' => bool].
     */
    private function get_prod_readiness(): array {
        global $CFG;

        $checks = [];

        // Theme designer mode disables CSS/JS caching — must be off in production.
        $checks[] = ['key' => 'themedesigner', 'ok' => empty($CFG->themedesignermode)];

        // Debug display leaks paths, SQL and stack traces to users — off in production.
        $checks[] = ['key' => 'debugdisplay', 'ok' => empty($CFG->debugdisplay)];

        // A developer-level debug setting is costly and verbose for a live site.
        $debug    = isset($CFG->debug) ? (int) $CFG->debug : 0;
        $devlevel = defined('DEBUG_ALL') ? DEBUG_ALL : 6143;
        $checks[] = ['key' => 'debug', 'ok' => $debug < $devlevel];

        // Asset and language caches should be enabled in production.
        $checks[] = ['key' => 'cachejs', 'ok' => !empty($CFG->cachejs)];
        $checks[] = ['key' => 'cachetemplates', 'ok' => !empty($CFG->cachetemplates)];
        $checks[] = ['key' => 'langstringcache', 'ok' => !isset($CFG->langstringcache) || !empty($CFG->langstringcache)];

        return $checks;
    }

    /**
     * Render the production-readiness configuration section.
     *
     * @param array $checks Checks from get_prod_readiness().
     * @return string HTML output.
     */
    private function render_prod_section(array $checks): string {
        $html  = '<h6 class="bsm-debug-section-title">' . get_string('prod_title', 'block_servermon') . '</h6>';
        $html .= '<div class="bsm-iso-intro">' . get_string('prod_intro', 'block_servermon') . '</div>';
        $html .= '<table class="bsm-info-table">';

        foreach ($checks as $c) {
            $badge = $c['ok']
                ? '<span class="bsm-badge bsm-ok">' . get_string('prod_ok', 'block_servermon') . '</span>'
                : '<span class="bsm-badge bsm-high">' . get_string('prod_review', 'block_servermon') . '</span>';
            $label = get_string('prod_' . $c['key'], 'block_servermon');
            $note  = $c['ok']
                ? ''
                : '<br><span class="bsm-hosting-reasons">'
                    . get_string('prod_' . $c['key'] . '_warn', 'block_servermon') . '</span>';
            $html .= '<tr>'
                . '<td class="bsm-info-key">' . $label . $note . '</td>'
                . '<td class="bsm-info-val">' . $badge . '</td>'
                . '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    // Server health: swap and cron freshness.

    /**
     * Collect server-health signals: swap usage and cron freshness.
     *
     * @return array Keys: swap, cron.
     */
    private function get_health_info(): array {
        return [
            'swap' => $this->get_swap(),
            'cron' => $this->get_cron_health(),
        ];
    }

    /**
     * Read swap usage from /proc/meminfo.
     *
     * @return array Keys: total, used, pct (GB / percent). A total of 0.0
     *               means swap is disabled; nulls mean it is unavailable.
     */
    private function get_swap(): array {
        $result = ['total' => null, 'used' => null, 'pct' => null];

        if (!is_readable('/proc/meminfo')) {
            return $result;
        }

        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/SwapTotal:\s+(\d+)/i', $meminfo, $mt);
        preg_match('/SwapFree:\s+(\d+)/i', $meminfo, $mf);
        if (!$mt || !$mf) {
            return $result;
        }

        $totalkb = (int) $mt[1];
        if ($totalkb <= 0) {
            $result['total'] = 0.0; // Swap is disabled.
            return $result;
        }

        $usedkb          = $totalkb - (int) $mf[1];
        $result['total'] = round($totalkb / 1048576, 2);
        $result['used']  = round($usedkb / 1048576, 2);
        $result['pct']   = round(($usedkb / $totalkb) * 100, 1);
        return $result;
    }

    /**
     * Read cron freshness: when scheduled tasks last ran and how many are failing.
     *
     * @return array Keys: checked (bool, query succeeded), age (seconds since
     *               last run, or null when cron has never run), failing (int|null).
     */
    private function get_cron_health(): array {
        global $DB;

        $result = ['checked' => false, 'age' => null, 'failing' => null];

        if (!$DB->get_manager()->table_exists('task_scheduled')) {
            return $result;
        }

        try {
            $lastrun = $DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
            $failing = $DB->count_records_select('task_scheduled', 'faildelay > 0');
            if ($DB->get_manager()->table_exists('task_adhoc')) {
                $failing += $DB->count_records_select('task_adhoc', 'faildelay > 0');
            }
        } catch (\Throwable $e) {
            return $result;
        }

        $result['checked'] = true;
        $result['failing'] = (int) $failing;
        // A falsy MAX (0/null) means no scheduled task has ever run — leave age
        // null so render_cron_health() can report the never-run state explicitly.
        if ($lastrun) {
            $result['age'] = max(0, time() - (int) $lastrun);
        }
        return $result;
    }

    /**
     * Render the server-health section (swap usage and cron freshness).
     *
     * @param array $h Health info from get_health_info().
     * @return string HTML output.
     */
    private function render_health_section(array $h): string {
        return $this->render_swap_health($h['swap']) . $this->render_cron_health($h['cron']);
    }

    /**
     * Render the swap-usage health block.
     *
     * @param array $swap Swap info from get_swap().
     * @return string HTML output.
     */
    private function render_swap_health(array $swap): string {
        if ($swap['pct'] === null && $swap['total'] !== 0.0) {
            return ''; // Swap info unavailable (e.g. non-Linux).
        }

        $html = '<h6 class="bsm-debug-section-title">' . get_string('health_swap_title', 'block_servermon') . '</h6>';

        if ($swap['pct'] === null) {
            return $html . '<div class="bsm-debug-alert bsm-alert-info">'
                . get_string('health_swap_none', 'block_servermon') . '</div>';
        }

        $alert  = $swap['pct'] >= 25 ? 'bsm-alert-warn' : 'bsm-alert-info';
        $detail = get_string('health_swap_detail', 'block_servermon', (object) [
            'used'  => $swap['used'],
            'total' => $swap['total'],
            'pct'   => $swap['pct'],
        ]);
        return $html . '<div class="bsm-debug-alert ' . $alert . '">' . htmlspecialchars($detail) . '</div>';
    }

    /**
     * Render the cron-freshness health block.
     *
     * @param array $cron Cron info from get_cron_health().
     * @return string HTML output.
     */
    private function render_cron_health(array $cron): string {
        if (empty($cron['checked'])) {
            return ''; // Task tables unavailable — cannot assess cron.
        }

        $html = '<h6 class="bsm-debug-section-title">' . get_string('health_cron_title', 'block_servermon') . '</h6>';

        if ($cron['age'] === null) {
            // No scheduled task has ever run: cron is almost certainly not set up.
            $alert  = 'bsm-alert-warn';
            $detail = get_string('health_cron_never', 'block_servermon');
        } else {
            $stale  = $cron['age'] > 1800; // Healthy cron runs at least every few minutes.
            $alert  = ($stale || !empty($cron['failing'])) ? 'bsm-alert-warn' : 'bsm-alert-info';
            $detail = get_string('health_cron_detail', 'block_servermon', $this->format_duration($cron['age']));
        }

        if (!empty($cron['failing'])) {
            $detail .= ' ' . get_string('health_cron_failing', 'block_servermon', $cron['failing']);
        }

        return $html . '<div class="bsm-debug-alert ' . $alert . '">' . htmlspecialchars($detail) . '</div>';
    }

    /**
     * Format a duration in seconds as a compact human-readable string.
     *
     * @param int $secs Duration in seconds.
     * @return string e.g. "45s", "12m", "3h 20m", "2d 4h".
     */
    private function format_duration(int $secs): string {
        if ($secs < 60) {
            return $secs . 's';
        }
        if ($secs < 3600) {
            return (int) floor($secs / 60) . 'm';
        }
        if ($secs < 86400) {
            return (int) floor($secs / 3600) . 'h ' . (int) floor(($secs % 3600) / 60) . 'm';
        }
        return (int) floor($secs / 86400) . 'd ' . (int) floor(($secs % 86400) / 3600) . 'h';
    }

    /**
     * Format a byte count into a human-readable string (B / KB / MB).
     *
     * @param int $bytes Byte count.
     * @return string Formatted string.
     */
    private function format_bytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    // Helpers.

    /**
     * Return a CSS colour class name based on the percentage value.
     *
     * @param float|null $pct Percentage value.
     * @return string CSS class suffix: ok, moderate, high, or unknown.
     */
    private function status_colour(?float $pct): string {
        if ($pct === null) {
            return 'unknown';
        }
        if ($pct >= 80) {
            return 'high';
        }
        if ($pct >= 60) {
            return 'moderate';
        }
        return 'ok';
    }

    /**
     * Return a status badge HTML string based on the percentage value.
     *
     * @param float|null $pct Percentage value.
     * @return string HTML badge element.
     */
    private function status_badge(?float $pct): string {
        $colour = $this->status_colour($pct);
        $label  = get_string('status_' . $colour, 'block_servermon');
        return '<span class="bsm-badge bsm-' . $colour . '">' . $label . '</span>';
    }

    /**
     * Render the CSV export download link shown at the bottom of the block.
     *
     * @return string HTML output.
     */
    private function render_csv_link(): string {
        global $DB;

        // Guard against the table not yet existing (e.g. upgrade pending).
        if (!$DB->get_manager()->table_exists('block_servermon_log')) {
            return '';
        }

        $count = $DB->count_records('block_servermon_log');
        if ($count === 0) {
            return '';
        }

        $url = new \moodle_url('/blocks/servermon/export.php');
        return '<div class="bsm-csv-link">'
            . '<a href="' . $url->out() . '">'
            . get_string('csv_export', 'block_servermon')
            . '</a>'
            . '</div>';
    }
}
