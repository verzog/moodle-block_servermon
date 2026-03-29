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
 * Server Monitor block for Moodle 5.x.
 *
 * Displays CPU load, RAM usage, disk space, uptime and server info
 * on the admin Dashboard. Visible to site administrators only.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block class for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * This block has no site-wide configuration form.
     *
     * @return bool
     */
    public function has_config(): bool {
        return false;
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
        ];
    }

    /**
     * Read CPU usage via /proc/stat two-sample delta (0.5 s interval).
     *
     * Returns aggregate usage percentage plus per-core breakdown.
     * Load averages are collected as supplementary context.
     * Falls back gracefully if /proc/stat is unreadable.
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

        if (!is_readable('/proc/stat')) {
            return $result;
        }

        return array_merge($result, $this->get_cpu_percore_stats());
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
        $idle1  = ($s1[3] ?? 0) + ($s1[4] ?? 0); // Idle + iowait.
        $idle2  = ($s2[3] ?? 0) + ($s2[4] ?? 0);
        $total1 = array_sum($s1);
        $total2 = array_sum($s2);

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

        $path  = $islinux ? '/' : 'C:\\';
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
            return ['label' => 'Windows Server (unconfirmed)', 'reasons' => []];
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
            $reasons[] = '/proc/net/dev readable';
        }

        if (file_exists('/etc/hostname')) {
            $score++;
            $reasons[] = '/etc/hostname present';
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
            return [1, "{$cores} CPU cores visible"];
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
            return [1, round($rammb / 1024, 1) . ' GB RAM'];
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
            return [1, 'Running as ' . $user['name']];
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
            return 'Likely VPS or Dedicated (unconfirmed)';
        }
        if ($score >= 1) {
            return 'Likely Shared Hosting or small VPS (unconfirmed)';
        }
        return 'Likely Shared Hosting (unconfirmed)';
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
        $html .= $this->render_metric_row('cpu', $m['cpu']);
        $html .= $this->render_metric_row('ram', $m['ram']);
        $html .= $this->render_metric_row('disk', $m['disk']);
        $html .= '<details class="bsm-details">';
        $html .= '<summary class="bsm-summary">' . $togglelabel . '</summary>';
        $html .= $this->render_info_table($m);
        $html .= '</details>';
        $html .= $this->render_debug_footer();
        $html .= $this->render_csv_link();
        $html .= '</div>';

        return $html;
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
            $detail = get_string('ram_detail', 'block_servermon', (object)[
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

        if ($d['observation'] !== '') {
            $html .= '<h6 class="bsm-debug-section-title">' . get_string('debug_obs', 'block_servermon') . '</h6>';
            $html .= '<div class="bsm-debug-alert bsm-alert-warn">'
                . htmlspecialchars($d['observation'])
                . '</div>';
        }

        return '<details class="bsm-details">'
            . '<summary class="bsm-summary bsm-summary-debug">' . $label . '</summary>'
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
            'wait' => $sess['wait'],
        ]);

        $sessalert = $sess['type'] === 'file' ? 'bsm-alert-warn' : 'bsm-alert-info';
        $html  = '<h6 class="bsm-debug-section-title">' . get_string('debug_session', 'block_servermon') . '</h6>';
        $html .= '<div class="bsm-debug-alert ' . $sessalert . '">' . $sessdetail;

        if ($sess['type'] === 'file') {
            $html .= '<br>' . get_string('debug_session_warn', 'block_servermon');
        } else if ($sess['type'] === 'redis' && $sess['redis'] !== null) {
            $r     = $sess['redis'];
            $html .= '<br>' . get_string('debug_session_redis', 'block_servermon', (object)[
                'host'         => htmlspecialchars($r['host']),
                'port'         => $r['port'],
                'db'           => $r['db'],
                'prefix'       => htmlspecialchars($r['prefix']),
                'lock_timeout' => $r['lock_timeout'],
                'lock_expire'  => $r['lock_expire'],
            ]);
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
            'session'     => ['type' => 'file', 'size' => null, 'wait' => '0.000 s'],
            'cachestats'  => [],
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

        // Advisory observation.
        $result['observation'] = $this->build_observation($result);

        return $result;
    }

    /**
     * Determine the current Moodle session type, size, and wait string.
     *
     * @return array Keys: type, size, wait, redis.
     */
    private function get_session_info(): array {
        global $CFG;

        $type = 'file';
        if (isset($CFG->session_handler_class)) {
            $cls = strtolower($CFG->session_handler_class);
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

        $info = ['type' => $type, 'size' => $size, 'wait' => '0.000 s', 'redis' => null];

        if ($type === 'redis') {
            $info['redis'] = [
                'host' => $CFG->session_redis_host ?? '127.0.0.1',
                'port' => $CFG->session_redis_port ?? 6379,
                'db' => $CFG->session_redis_database ?? 0,
                'prefix' => $CFG->session_redis_prefix ?? '',
                'lock_timeout' => $CFG->session_redis_acquire_lock_timeout ?? 120,
                'lock_expire' => $CFG->session_redis_lock_expire ?? 7200,
            ];
        }

        return $info;
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
            $missrate  = round(($app['misses'] / $total) * 100);
            $storetype = $this->get_store_type_label($app['store']);

            if ($missrate >= 50 && strpos($storetype, 'file') !== false) {
                return "Application cache miss rate ~{$missrate}%"
                    . ' — adding Redis/APCu as the application store would cut file I/O.';
            }

            if ($missrate >= 50 && strpos($storetype, 'redis') !== false) {
                return "Application cache miss rate ~{$missrate}% on Redis"
                    . ' — consider increasing Redis maxmemory or review eviction policy.';
            }
        }

        return '';
    }

    /**
     * Derive a human-readable store type label from a raw store name.
     *
     * @param string $store Raw store name from cache stats.
     * @return string Human-readable label, e.g. "file store", "redis store".
     */
    private function get_store_type_label(string $store): string {
        $lower = strtolower($store);
        if (strpos($lower, 'redis') !== false) {
            return 'redis store';
        }
        if (strpos($lower, 'memcach') !== false) {
            return 'memcached store';
        }
        if (strpos($lower, 'apcu') !== false || strpos($lower, 'apc') !== false) {
            return 'APCu store';
        }
        return 'file store';
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
