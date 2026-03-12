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
 * Displays CPU load, RAM usage, and disk space on the admin Dashboard.
 * Visible to site administrators only.
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
     * This block is only applicable on My Dashboard.
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

    /**
     * Collect all server metrics and return as an associative array.
     *
     * @return array
     */
    private function collect_metrics(): array {
        global $DB;
        $islinux = (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN');
        return [
            'cpu'      => $this->get_cpu($islinux),
            'ram'      => $this->get_ram($islinux),
            'disk'     => $this->get_disk($islinux),
            'uptime'   => $this->get_uptime($islinux),
            'php'      => PHP_VERSION,
            'os'       => PHP_OS,
            'hostname' => gethostname() ?: 'unknown',
            'webserver' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'time'     => userdate(time()),
            'dbtype'   => $this->get_db_type($DB),
            'hosting'  => $this->get_hosting_type($islinux),
        ];
    }

    /**
     * Return a human-readable database type and version string.
     *
     * @param moodle_database $db The Moodle database object.
     * @return string Database type and version.
     */
    private function get_db_type(\moodle_database $db): string {
        $family  = $db->get_dbfamily();
        $info    = $db->get_server_info();
        $version = $info['version'] ?? '';

        $labels = [
            'mysql'    => 'MySQL / MariaDB',
            'postgres' => 'PostgreSQL',
            'mssql'    => 'Microsoft SQL Server',
            'oracle'   => 'Oracle',
            'sqlite'   => 'SQLite',
        ];

        $label = $labels[$family] ?? ucfirst($family);
        return $version ? "{$label} {$version}" : $label;
    }

    /**
     * Attempt to detect the server environment type.
     *
     * Uses a layered heuristic: first checks for known platform fingerprints
     * (YunoHost, Plesk, cPanel etc.), then detects virtualisation type
     * (KVM, Xen, LXC, container), then falls back to resource-based scoring.
     * Results are always marked unconfirmed as PHP cannot definitively
     * determine hosting type.
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: label, reasons.
     */
    private function get_hosting_type(bool $islinux): array {
        $signals = [];
        $label   = '';

        if (!$islinux) {
            return ['label' => 'Windows Server (unconfirmed)', 'reasons' => 'Non-Linux OS detected.'];
        }

        // ---- Layer 1: Known platform fingerprints -------------------------
        // These give us a high-confidence environment label immediately.
        // Multiple panels can coexist so we collect all matches.

        if (is_dir('/etc/yunohost') || is_dir('/usr/share/yunohost')) {
            $signals[] = 'YunoHost detected';
            $label     = 'YunoHost / Self-hosted VPS (unconfirmed)';
        }

        if (is_dir('/etc/plesk') || is_readable('/usr/local/psa/version')) {
            $signals[] = 'Plesk detected';
            if ($label === '') {
                $label = 'VPS or Dedicated with Plesk (unconfirmed)';
            }
        }

        if (is_dir('/usr/local/cpanel') || is_readable('/etc/cpanel/cpanel.config')) {
            $signals[] = 'cPanel detected';
            if ($label === '') {
                $label = 'Shared or VPS with cPanel (unconfirmed)';
            }
        }

        if (is_readable('/etc/directadmin/directadmin.conf')) {
            $signals[] = 'DirectAdmin detected';
            if ($label === '') {
                $label = 'Shared or VPS with DirectAdmin (unconfirmed)';
            }
        }

        if (is_dir('/etc/webmin') || is_readable('/usr/share/webmin/version')) {
            $signals[] = 'Webmin detected';
            if ($label === '') {
                $label = 'Self-managed Server with Webmin (unconfirmed)';
            }
        }

        if (is_dir('/usr/local/ispconfig') || is_readable('/usr/local/ispconfig/server/lib/config.inc.php')) {
            $signals[] = 'ISPConfig detected';
            if ($label === '') {
                $label = 'Shared or VPS with ISPConfig (unconfirmed)';
            }
        }

        if (is_dir('/usr/local/vesta') || is_dir('/usr/local/hestia')) {
            $panel     = is_dir('/usr/local/hestia') ? 'HestiaCP' : 'VestaCP';
            $signals[] = $panel . ' detected';
            if ($label === '') {
                $label = 'VPS with ' . $panel . ' (unconfirmed)';
            }
        }

        if (is_dir('/usr/local/cwpsrv') || is_readable('/usr/local/cwpsrv/conf/cwp.conf')) {
            $signals[] = 'CentOS Web Panel (CWP) detected';
            if ($label === '') {
                $label = 'VPS with CWP (unconfirmed)';
            }
        }

        if (is_dir('/opt/psa') || is_readable('/opt/psa/version')) {
            $signals[] = 'Parallels/Virtuozzo detected';
            if ($label === '') {
                $label = 'Shared or VPS with Parallels (unconfirmed)';
            }
        }

        // ---- Layer 2: Cloud provider detection ----------------------------
        // Check DMI vendor strings and cloud-specific metadata paths.

        $cloud = '';

        if (is_readable('/sys/class/dmi/id/sys_vendor')) {
            $vendor = strtolower(trim(file_get_contents('/sys/class/dmi/id/sys_vendor')));
            if (strpos($vendor, 'amazon') !== false) {
                $cloud = 'AWS';
            } else if (strpos($vendor, 'microsoft') !== false) {
                $cloud = 'Azure';
            } else if (strpos($vendor, 'google') !== false) {
                $cloud = 'Google Cloud';
            } else if (strpos($vendor, 'digitalocean') !== false) {
                $cloud = 'DigitalOcean';
            } else if (strpos($vendor, 'hetzner') !== false) {
                $cloud = 'Hetzner';
            } else if (strpos($vendor, 'contabo') !== false) {
                $cloud = 'Contabo';
            } else if (strpos($vendor, 'vultr') !== false) {
                $cloud = 'Vultr';
            } else if (strpos($vendor, 'linode') !== false) {
                $cloud = 'Linode / Akamai';
            } else if (strpos($vendor, 'ovh') !== false) {
                $cloud = 'OVH';
            }
        }

        if ($cloud !== '') {
            $signals[] = 'Cloud provider: ' . $cloud;
            if ($label === '') {
                $label = $cloud . ' VPS (unconfirmed)';
            }
        }

        // ---- Layer 3: Virtualisation / container type ---------------------
        // Check DMI product name and CPU flags for hypervisor hints.

        $virt = '';

        if (is_readable('/sys/class/dmi/id/product_name')) {
            $dmi = strtolower(trim(file_get_contents('/sys/class/dmi/id/product_name')));
            if (strpos($dmi, 'kvm') !== false) {
                $virt = 'KVM';
            } else if (strpos($dmi, 'vmware') !== false) {
                $virt = 'VMware';
            } else if (strpos($dmi, 'virtualbox') !== false) {
                $virt = 'VirtualBox';
            } else if (strpos($dmi, 'bochs') !== false || strpos($dmi, 'qemu') !== false) {
                $virt = 'QEMU/KVM';
            } else if (strpos($dmi, 'xen') !== false) {
                $virt = 'Xen';
            } else if (strpos($dmi, 'hyper-v') !== false || strpos($dmi, 'hyperv') !== false) {
                $virt = 'Hyper-V';
            }
        }

        if ($virt === '' && is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (strpos($cpuinfo, 'hypervisor') !== false) {
                $virt = 'Hypervisor (type unknown)';
            }
        }

        if ($virt === '' && is_readable('/proc/1/environ')) {
            $env = @file_get_contents('/proc/1/environ');
            if ($env !== false && strpos($env, 'container=') !== false) {
                $virt = 'Container (LXC/Docker)';
            }
        }

        if ($virt !== '') {
            $signals[] = 'Virtualisation: ' . $virt;
            if ($label === '') {
                $label = 'VPS / Virtual Machine (unconfirmed)';
            }
        }

        // ---- Layer 4: Bare-metal chassis detection ------------------------
        // DMI chassis type codes: 17=rack, 23=blade, 1=other (often bare metal).
        // Only set label here if nothing else matched — avoids overriding VPS detection.

        if ($label === '' && is_readable('/sys/class/dmi/id/chassis_type')) {
            $chassis = (int) trim(file_get_contents('/sys/class/dmi/id/chassis_type'));
            $chassislabels = [
                1  => 'Other',
                2  => 'Unknown',
                17 => 'Rack Mount',
                23 => 'Blade',
                24 => 'Blade Enclosure',
            ];
            if (isset($chassislabels[$chassis])) {
                $signals[] = 'Chassis type: ' . $chassislabels[$chassis];
            }
            if (in_array($chassis, [17, 23, 24])) {
                $label = 'Likely Dedicated / Bare Metal Server (unconfirmed)';
            }
        }

        // ---- Layer 5: OS fingerprint --------------------------------------
        // Adds OS detail to signals regardless of label.

        if (is_readable('/etc/os-release')) {
            preg_match('/PRETTY_NAME="([^"]+)"/', file_get_contents('/etc/os-release'), $osm);
            if (!empty($osm[1])) {
                $signals[] = $osm[1];
            }
        } else if (is_readable('/etc/debian_version')) {
            $signals[] = 'Debian ' . trim(file_get_contents('/etc/debian_version'));
        } else if (is_readable('/etc/redhat-release')) {
            $signals[] = trim(file_get_contents('/etc/redhat-release'));
        }

        // ---- Layer 6: Resource-based scoring (final fallback) -------------
        // Only sets the label if nothing above produced one.

        $vpsscore = 0;

        $cpucount = 1;
        if (is_readable('/proc/cpuinfo')) {
            preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $m);
            $cpucount = max(1, count($m[0]));
        }
        if ($cpucount >= 2) {
            $vpsscore++;
        }
        $signals[] = $cpucount . ' CPU core' . ($cpucount > 1 ? 's' : '');

        if (is_readable('/proc/meminfo')) {
            preg_match('/MemTotal:\s+(\d+)\s+kB/', file_get_contents('/proc/meminfo'), $mt);
            if (!empty($mt[1])) {
                $rammb = (int) $mt[1] / 1024;
                if ($rammb >= 900) {
                    $vpsscore++;
                    $signals[] = round($rammb / 1024, 1) . ' GB RAM';
                } else {
                    $signals[] = round($rammb) . ' MB RAM';
                }
            }
        }

        if (is_readable('/proc/net/dev')) {
            $vpsscore++;
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user     = posix_getpwuid(posix_geteuid());
            $username = $user['name'] ?? '';
            $sharedusers = ['nobody', 'apache', 'www-data', 'httpd'];
            if ($username !== '') {
                if (!in_array($username, $sharedusers)) {
                    $vpsscore++;
                }
                $signals[] = 'Running as: ' . $username;
            }
        }

        if ($label === '') {
            if ($vpsscore >= 3) {
                $label = 'Likely Dedicated Server (unconfirmed)';
            } else if ($vpsscore >= 1) {
                $label = 'Likely VPS or Shared Hosting (unconfirmed)';
            } else {
                $label = 'Likely Shared Hosting (unconfirmed)';
            }
        }

        return [
            'label'   => $label,
            'reasons' => implode(', ', $signals),
        ];
    }

    /**
     *
     * @param bool $islinux Whether the server is running Linux.
     * @return array Keys: pct, load1, load5, load15.
     */
    private function get_cpu(bool $islinux): array {
        $result = ['pct' => null, 'load1' => null, 'load5' => null, 'load15' => null];

        if (!$islinux || !function_exists('sys_getloadavg')) {
            return $result;
        }

        $load = sys_getloadavg();
        $cpus = 1;

        if (is_readable('/proc/cpuinfo')) {
            preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $m);
            $cpus = max(1, count($m[0]));
        }

        $result['load1']  = round($load[0], 2);
        $result['load5']  = round($load[1], 2);
        $result['load15'] = round($load[2], 2);
        $result['pct']    = min(100, round(($load[0] / $cpus) * 100, 1));

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

        $info = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)\s+kB/',     $info, $mt);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/', $info, $ma);

        if (!$mt || !$ma) {
            return $result;
        }

        $total = round($mt[1] / 1048576, 2);
        $free  = round($ma[1] / 1048576, 2);
        $used  = round($total - $free, 2);

        $result['total'] = $total;
        $result['free']  = $free;
        $result['used']  = $used;
        $result['pct']   = $total > 0 ? round(($used / $total) * 100, 1) : null;

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
        $freegb  = round($free / 1073741824, 2);
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
        $d = floor($secs / 86400);
        $h = floor(($secs % 86400) / 3600);
        $m = floor(($secs % 3600) / 60);
        return "{$d}d {$h}h {$m}m";
    }

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
        $barpct = $pct !== null ? $pct : 0;

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

        $valuedisplay = $pct !== null ? "{$pct}%" : '&mdash;';

        return <<<HTML
        <div class="bsm-metric">
            <div class="bsm-metric-header">
                <span class="bsm-metric-label">{$label}</span>
                <span class="bsm-metric-value" style="color:{$colour}">{$valuedisplay}</span>
            </div>
            <div class="bsm-bar-track">
                <div class="bsm-bar-fill" style="width:{$barpct}%;background:{$colour}"></div>
            </div>
            <div class="bsm-metric-footer">
                <span class="bsm-detail">{$detail}{$unavailmsg}</span>
                <span class="bsm-badge" style="color:{$colour};border-color:{$colour}">{$badge}</span>
            </div>
        </div>
HTML;
    }

    /**
     * Render the server info table below the metric rows.
     *
     * @param array $m Metrics array from collect_metrics().
     * @return string HTML output.
     */
    private function render_info_table(array $m): string {
        $hosting = $m['hosting'];
        $rows = [
            get_string('uptime_label',    'block_servermon') => $m['uptime'] ?? get_string('unavailable', 'block_servermon'),
            get_string('php_label',       'block_servermon') => htmlspecialchars($m['php']),
            get_string('db_label',        'block_servermon') => htmlspecialchars($m['dbtype']),
            get_string('hostname_label',  'block_servermon') => htmlspecialchars($m['hostname']),
            get_string('webserver_label', 'block_servermon') => htmlspecialchars($m['webserver']),
            get_string('hosting_label',   'block_servermon') => htmlspecialchars($hosting['label'])
                . '<br><span class="bsm-hosting-reason">' . htmlspecialchars($hosting['reasons']) . '</span>',
            get_string('timestamp_label', 'block_servermon') => htmlspecialchars($m['time']),
        ];

        $html = '<table class="bsm-info-table">';
        foreach ($rows as $k => $v) {
            $html .= "<tr><td class=\"bsm-info-key\">{$k}</td><td class=\"bsm-info-val\">{$v}</td></tr>";
        }
        $html .= '</table>';
        return $html;
    }

    /**
     * Return a hex colour string based on percentage used.
     *
     * @param float|null $pct Percentage value, or null if unknown.
     * @return string Hex colour code.
     */
    private function status_colour(?float $pct): string {
        if ($pct === null) {
            return '#94a3b8';
        }
        if ($pct < 60) {
            return '#22c55e';
        }
        if ($pct < 80) {
            return '#f59e0b';
        }
        return '#ef4444';
    }

    /**
     * Return a localised status badge string based on percentage used.
     *
     * @param float|null $pct Percentage value, or null if unknown.
     * @return string Localised status label.
     */
    private function status_badge(?float $pct): string {
        if ($pct === null) {
            return get_string('status_unknown', 'block_servermon');
        }
        if ($pct < 60) {
            return get_string('status_ok', 'block_servermon');
        }
        if ($pct < 80) {
            return get_string('status_moderate', 'block_servermon');
        }
        return get_string('status_high', 'block_servermon');
    }
}
