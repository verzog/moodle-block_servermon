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
 * Tests for the collect_metrics scheduled task.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_servermon\task;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that the scheduled task logs a snapshot and prunes stale rows.
 *
 * @package   block_servermon
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collect_metrics::class)]
final class collect_metrics_test extends \advanced_testcase {
    /**
     * The task name resolves to a translated, non-empty string.
     *
     * @return void
     */
    public function test_get_name(): void {
        $this->resetAfterTest();
        $name = (new collect_metrics())->get_name();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    /**
     * Running the task inserts one snapshot row and prunes rows over 7 days old.
     *
     * @return void
     */
    public function test_execute_logs_and_prunes(): void {
        global $DB;
        $this->resetAfterTest();

        // A row older than the 7-day retention window: should be pruned.
        $oldid = $DB->insert_record('block_servermon_log', (object) [
            'timecreated' => time() - (8 * DAYSECS),
            'cpu_cores'   => 1,
            'cpu_percore' => null,
            'ram_pct'     => 10.0,
            'disk_pct'    => 20.0,
        ]);

        // A recent row: should survive.
        $recentid = $DB->insert_record('block_servermon_log', (object) [
            'timecreated' => time() - MINSECS,
            'cpu_cores'   => 1,
            'cpu_percore' => null,
            'ram_pct'     => 11.0,
            'disk_pct'    => 21.0,
        ]);

        (new collect_metrics())->execute();

        $this->assertFalse($DB->record_exists('block_servermon_log', ['id' => $oldid]));
        $this->assertTrue($DB->record_exists('block_servermon_log', ['id' => $recentid]));

        // The surviving recent row plus exactly one freshly inserted snapshot.
        $this->assertEquals(2, $DB->count_records('block_servermon_log'));

        // The new snapshot carries a current timestamp.
        $latest = $DB->get_records('block_servermon_log', null, 'timecreated DESC', '*', 0, 1);
        $latest = reset($latest);
        $this->assertGreaterThanOrEqual(time() - (2 * MINSECS), (int) $latest->timecreated);
    }
}
