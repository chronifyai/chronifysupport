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
 * Tests for the privacy provider.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport\privacy;

use core_privacy\local\metadata\collection;

/**
 * Tests for the privacy provider.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The two external locations are declared and every privacy string exists.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_chronifysupport'));
        $items = $collection->get_collection();

        $names = array_map(function ($item) {
            return $item->get_name();
        }, $items);
        $this->assertContains('local_chronifysupport_external', $names);
        $this->assertContains('local_chronifysupport_tracking', $names);

        $stringmanager = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($stringmanager->string_exists($item->get_summary(), 'local_chronifysupport'));
            foreach ($item->get_privacy_fields() as $field) {
                $this->assertTrue($stringmanager->string_exists($field, 'local_chronifysupport'));
            }
        }
    }

    /**
     * The plugin holds no personal data in Moodle, so no contexts are reported.
     */
    public function test_no_contexts_for_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertEmpty($contextlist->get_contextids());
    }
}
