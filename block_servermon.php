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

defined('MOODLE_INTERNAL') || die();

/**
 * Block class for block_servermon.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_servermon extends block_base {

    // ---------------------------------------------------------------
    // Moodle block lifecycle methods.
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Data collection.
    // ---------------------------------------------------------------

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
     * Read CPU load average from the OS.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: pct, load1, load5, load15.
     */
    private function get_cpu(bool $islinux): array {
        $result = ['pct' => null, 'load1' => null, 'load5' => null, 'load15' => null];

        if (!$islinux || !function_exists('sys_getloadavg')) {
            return $result;
        }

        $load = @sys_getloadavg();
        if (!$load) {
            return $result;
        }

        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
            $cores = max(1, count($matches[0]));
        }

        $result['load1']  = round($load[0], 2);
        $result['load5']  = round($load[1], 2);
        $result['load15'] = round($load[2], 2);
        $result['pct']    = round(($load[0] / $cores) * 100, 1);

        return $result;
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
        preg_match('/MemTotal:\s+(\d+)/i',     $meminfo, $mtotal);
        preg_match('/MemAvailable:\s+(\d+)/i', $meminfo, $mavail);

        if (!$mtotal || !$mavail) {
            return $result;
        }

        $totalkb = (int) $mtotal[1];
        $freekb  = (int) $mavail[1];
        $usedkb  = $totalkb - $freekb;

        $result['total'] = round($totalkb / 1048576, 2);
        $result['free']  = round($freekb  / 1048576, 2);
        $result['used']  = round($usedkb  / 1048576, 2);
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
        $freegb  = round($free  / 1073741824, 2);
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
        $score   = 0;
        $reasons = [];

        if (!$islinux) {
            return ['label' => 'Windows Server (unconfirmed)', 'reasons' => []];
        }

        // CPU core count.
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
            $cores = max(1, count($matches[0]));
        }
        if ($cores >= 2) {
            $score++;
            $reasons[] = "{$cores} CPU cores visible";
        }

        // RAM threshold.
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/i', $meminfo, $m);
            if ($m) {
                $rammb = (int) $m[1] / 1024;
                if ($rammb >= 900) {
                    $score++;
                    $reasons[] = round($rammb / 1024, 1) . ' GB RAM';
                }
            }
        }

        // Network interfaces.
        if (is_readable('/proc/net/dev')) {
            $score++;
            $reasons[] = '/proc/net/dev readable';
        }

        // Hostname file.
        if (file_exists('/etc/hostname')) {
            $score++;
            $reasons[] = '/etc/hostname present';
        }

        // Process user.
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if ($user && !in_array($user['name'], ['nobody', 'www-data', 'apache', 'nginx'])) {
                $score++;
                $reasons[] = 'Running as ' . $user['name'];
            }
        }

        if ($score >= 3) {
            $label = 'Likely VPS or Dedicated (unconfirmed)';
        } else if ($score >= 1) {
            $label = 'Likely Shared Hosting or small VPS (unconfirmed)';
        } else {
            $label = 'Likely Shared Hosting (unconfirmed)';
        }

        return ['label' => $label, 'reasons' => $reasons];
    }

    // ---------------------------------------------------------------
    // Rendering.
    // ---------------------------------------------------------------

    /**
     * Render the full block HTML from collected metrics.
     *
     * @param array $m Metrics array from collect_metrics().
     * @return string HTML output.
     */
    private function render_block(array $m): string {
        $togglelabel = get_string('info_toggle', 'block_servermon');
        $html  = '<div class="block-servermon">';
        $html .= $this->render_metric_row('cpu',  $m['cpu']);
        $html .= $this->render_metric_row('ram',  $m['ram']);
        $html .= $this->render_metric_row('disk', $m['disk']);
        $html .= '<details class="bsm-details">';
        $html .= '<summary class="bsm-summary">' . $togglelabel . '</summary>';
        $html .= $this->render_info_table($m);
        $html .= '</details>';
        $html .= $this->render_debug_footer();
        $html .= '</div>';
        return $html;
    }

    /**
     * Render a single metric row with label, value, progress bar and badge.
     *
     * @param string $type One of: cpu, ram, disk.
     * @param array  $data Metric data array.
     * @return string HTML output.
     */
    private function render_metric_row(string $type, array $data): string {
        $pct    = $data['pct'];
        $label  = get_string("{$type}_label", 'block_servermon');
        $colour = $this->status_colour($pct);
        $badge  = $this->status_badge($pct);
        $barpct = $pct !== null ? min($pct, 100) : 0;

        // Build detail line.
        $detail = '';
        if ($type === 'cpu' && $pct !== null) {
            $detail = get_string('load_averages', 'block_servermon', (object)[
                'one'     => $data['load1'],
                'five'    => $data['load5'],
                'fifteen' => $data['load15'],
            ]);
        } else if (in_array($type, ['ram', 'disk']) && $data['total'] !== null) {
            $detail = get_string('ram_detail', 'block_servermon', (object)[
                'used'  => $data['used'],
                'total' => $data['total'],
                'free'  => $data['free'],
            ]);
        }

        $unavailmsg = $pct === null
            ? '<div class="bsm-unavail">' . get_string('unavailable', 'block_servermon') . '</div>'
            : '';

        $valuedisplay = $pct !== null
            ? '<span class="bsm-pct">' . $pct . '%</span>'
            : '';

        $html  = '<div class="bsm-row">';
        $html .= '<div class="bsm-row-header">';
        $html .= '<span class="bsm-label">' . $label . '</span>';
        $html .= '<span class="bsm-right">' . $valuedisplay . $badge . '</span>';
        $html .= '</div>';

        if ($pct !== null) {
            $html .= '<div class="bsm-bar-track">';
            $html .= '<div class="bsm-bar-fill bsm-' . $colour . '" style="width:' . $barpct . '%"></div>';
            $html .= '</div>';
        }

        if ($detail) {
            $html .= '<div class="bsm-detail">' . $detail . '</div>';
        }

        $html .= $unavailmsg;
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
            get_string('uptime_label',    'block_servermon') => $m['uptime'] ?? get_string('unavailable', 'block_servermon'),
            get_string('php_label',       'block_servermon') => htmlspecialchars($m['php']),
            get_string('os_label',        'block_servermon') => htmlspecialchars($m['os']),
            get_string('hostname_label',  'block_servermon') => htmlspecialchars($m['hostname']),
            get_string('webserver_label', 'block_servermon') => htmlspecialchars($m['webserver']),
            get_string('hosting_label',   'block_servermon') => htmlspecialchars($m['hosting']['label'])
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
        $d       = $this->collect_debug_metrics();
        $label   = get_string('debug_toggle', 'block_servermon');
        $unavail = get_string('unavailable', 'block_servermon');

        // 1. Four metric cards.
        $html  = '<div class="bsm-debug-grid">';
        $html .= $this->metric_card(get_string('debug_pagetime', 'block_servermon'),
            $d['pagetime'] !== null ? $d['pagetime'] . ' s' : $unavail);
        $html .= $this->metric_card(get_string('debug_memory', 'block_servermon'),
            $d['memory'] !== null ? $d['memory'] . ' MB' : $unavail);
        $html .= $this->metric_card(get_string('debug_dbrw', 'block_servermon'),
            $d['dbreads'] !== null ? $d['dbreads'] . ' / ' . $d['dbwrites'] : $unavail);
        $html .= $this->metric_card(get_string('debug_dbtime', 'block_servermon'),
            $d['dbtime'] !== null ? $d['dbtime'] . ' s' : $unavail);
        $html .= '</div>';

        // 2. Session handler section.
        $sess = $d['session'];
        $sessdetail = get_string('debug_session_detail', 'block_servermon', (object)[
            'type' => htmlspecialchars($sess['type']),
            'size' => $sess['size'] ?? $unavail,
            'wait' => $sess['wait'],
        ]);
        $html .= '<h6 class="bsm-debug-section-title">' . get_string('debug_session', 'block_servermon') . '</h6>';
        $sessalert = $sess['type'] === 'file' ? 'bsm-alert-warn' : 'bsm-alert-info';
        $html .= '<div class="bsm-debug-alert ' . $sessalert . '">' . $sessdetail;
        if ($sess['type'] === 'file') {
            $html .= '<br>' . get_string('debug_session_warn', 'block_servermon');
        } else if ($sess['type'] === 'redis' && $sess['redis'] !== null) {
            $r = $sess['redis'];
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

        // 3. Cache store performance.
        if (!empty($d['cachestats'])) {
            $html .= $this->render_cache_section($d['cachestats']);
        }

        // 4. Observation.
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

        // Column header row.
        $html .= '<div class="bsm-cache-header">'
            . '<span class="bsm-cache-name">' . get_string('debug_cache_store',  'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_hits',   'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_misses', 'block_servermon') . '</span>'
            . '<span class="bsm-cache-stat">' . get_string('debug_cache_io',     'block_servermon') . '</span>'
            . '</div>';

        $static = $cachestats['static'];
        if ($static['hits'] > 0 || $static['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_static', 'block_servermon'),
                $static['hits'], $static['misses'], 0, true
            );
        }

        $app = $cachestats['app'];
        $html .= $this->cache_div_row(
            get_string('debug_cache_app', 'block_servermon', $this->get_store_type_label($app['store'])),
            $app['hits'], $app['misses'], $app['bytes'], false
        );

        $req = $cachestats['request'];
        if ($req['hits'] > 0 || $req['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_request', 'block_servermon'),
                $req['hits'], $req['misses'], 0, false
            );
        }

        $sess = $cachestats['session'];
        if ($sess['hits'] > 0 || $sess['misses'] > 0) {
            $html .= $this->cache_div_row(
                get_string('debug_cache_session', 'block_servermon'),
                $sess['hits'], $sess['misses'], 0, false
            );
        }

        return $html;
    }

    /**
     * Render a single cache store row as a styled div.
     *
     * @param string $label     Store label.
     * @param int    $hits      Cache hits.
     * @param int    $misses    Cache misses.
     * @param int    $bytes     Bytes read/written (0 = show dash).
     * @param bool   $highlight True for the green "top store" highlight style.
     * @return string HTML output.
     */
    private function cache_div_row(string $label, int $hits, int $misses, int $bytes, bool $highlight): string {
        $cls = 'bsm-cache-row' . ($highlight ? ' bsm-cache-highlight' : '');
        $io  = $bytes > 0 ? $this->format_bytes($bytes) : '—';
        return '<div class="' . $cls . '">'
            . '<span class="bsm-cache-name">' . $label . '</span>'
            . '<span class="bsm-cache-stat">' . $hits . '</span>'
            . '<span class="bsm-cache-stat">' . $misses . '</span>'
            . '<span class="bsm-cache-stat">' . $io . '</span>'
            . '</div>';
    }

    /**
     * Collect Moodle page-performance metrics for the debug footer.
     *
     * @return array Keys: pagetime, memory, dbreads, dbwrites, dbtime, session, cachestats, observation.
     */
    private function collect_debug_metrics(): array {
        global $DB, $CFG;

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

        // Page load time from PHP superglobal set at request start.
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $result['pagetime'] = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2);
        }

        // Peak memory in MB (real_usage=false gives application-level usage).
        $result['memory'] = round(memory_get_peak_usage(false) / 1048576, 1);

        // DB reads/writes/query-time via Moodle's moodle_database perf API.
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

        // Build advisory observation after all data is collected.
        $result['observation'] = $this->build_observation($result);

        return $result;
    }

    /**
     * Determine the current Moodle session type, size, and wait string.
     *
     * @return array Keys: type, size, wait.
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
                'host'         => $CFG->session_redis_host         ?? '127.0.0.1',
                'port'         => $CFG->session_redis_port         ?? 6379,
                'db'           => $CFG->session_redis_database      ?? 0,
                'prefix'       => $CFG->session_redis_prefix        ?? '',
                'lock_timeout' => $CFG->session_redis_acquire_lock_timeout ?? 120,
                'lock_expire'  => $CFG->session_redis_lock_expire   ?? 7200,
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

        $modeapp     = class_exists('cache_store') ? cache_store::MODE_APPLICATION : 1;
        $modesession = class_exists('cache_store') ? cache_store::MODE_SESSION     : 2;
        $moderequest = class_exists('cache_store') ? cache_store::MODE_REQUEST     : 4;

        // Build a definition-key => mode map.
        // cache_helper::get_stats() does NOT embed mode in its output; we must look it up.
        $modemap = [];
        if (class_exists('cache_config') && method_exists('cache_config', 'instance')) {
            try {
                foreach (cache_config::instance()->get_definitions() as $defkey => $def) {
                    $modemap[$defkey] = (int)($def['mode'] ?? $modeapp);
                }
            } catch (\Throwable $e) {
                // Fall through — modemap stays empty; all non-static entries default to app.
            }
        }

        $agg = [
            'static'  => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'app'     => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'session' => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
            'request' => ['hits' => 0, 'misses' => 0, 'bytes' => 0, 'store' => ''],
        ];

        foreach ($raw as $defkey => $data) {
            if (!is_array($data)) {
                continue;
            }

            // Support both flat (one entry per definition) and nested (array of store entries).
            $entries = isset($data['hits']) || isset($data['misses']) ? [$data] : array_values($data);

            // Resolve mode: prefer definition map, fall back to APPLICATION.
            $mode = $modemap[$defkey] ?? $modeapp;

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $store  = $entry['store'] ?? '';
                $hits   = (int)($entry['hits']   ?? 0);
                $misses = (int)($entry['misses'] ?? 0);
                $bytes  = (int)($entry['bytes']  ?? 0);

                // Entries backed by the static PHP-array store go into the static-accel bucket.
                if (stripos($store, 'static') !== false || $store === 'disabled') {
                    $agg['static']['hits']   += $hits;
                    $agg['static']['misses'] += $misses;
                    continue;
                }

                if ($mode === $modeapp) {
                    $agg['app']['hits']   += $hits;
                    $agg['app']['misses'] += $misses;
                    $agg['app']['bytes']  += $bytes;
                    if (!$agg['app']['store']) {
                        $agg['app']['store'] = $store;
                    }
                } else if ($mode === $modesession) {
                    $agg['session']['hits']   += $hits;
                    $agg['session']['misses'] += $misses;
                    $agg['session']['bytes']  += $bytes;
                    if (!$agg['session']['store']) {
                        $agg['session']['store'] = $store;
                    }
                } else if ($mode === $moderequest) {
                    $agg['request']['hits']   += $hits;
                    $agg['request']['misses'] += $misses;
                    $agg['request']['bytes']  += $bytes;
                }
            }
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

    // ---------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------

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
}
