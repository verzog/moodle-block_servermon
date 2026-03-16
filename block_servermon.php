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
