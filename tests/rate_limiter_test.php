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
 * Tests for the request throttle of the public endpoints.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Tests for the request throttle of the public endpoints.
 *
 * @covers \local_chronifysupport\rate_limiter
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rate_limiter::class)]
final class rate_limiter_test extends \advanced_testcase {
    /**
     * Requests beyond the limit are refused, and other addresses and areas are not affected.
     */
    public function test_limit_is_enforced_per_area_and_address(): void {
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_chronifysupport', 'ratelimit');

        $this->assertTrue(rate_limiter::allow('test', '192.0.2.1', 2));
        $this->assertTrue(rate_limiter::allow('test', '192.0.2.1', 2));
        $this->assertFalse(rate_limiter::allow('test', '192.0.2.1', 2));

        // Another address has its own budget.
        $this->assertTrue(rate_limiter::allow('test', '192.0.2.2', 2));
        // Another area has its own budget.
        $this->assertTrue(rate_limiter::allow('other', '192.0.2.1', 2));
    }
}
