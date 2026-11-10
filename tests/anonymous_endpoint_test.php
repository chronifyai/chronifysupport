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
 * Tests for the helpers of the public endpoints.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Tests for the helpers of the public endpoints.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(anonymous_endpoint::class)]
final class anonymous_endpoint_test extends \advanced_testcase {
    /**
     * Without a complete configuration the endpoints report "not configured".
     */
    public function test_config_requires_all_settings(): void {
        $this->resetAfterTest();
        set_config('helpdesk_base_url', 'https://helpdesk.example.com', 'local_chronifysupport');
        set_config('client_id', 'client-1', 'local_chronifysupport');
        unset_config('client_secret', 'local_chronifysupport');
        $this->assertNull(anonymous_endpoint::config());

        set_config('client_secret', 'secret-1', 'local_chronifysupport');
        $config = anonymous_endpoint::config();
        $this->assertSame('https://helpdesk.example.com', $config['helpdesk_base_url']);
        $this->assertSame('client-1', $config['client_id']);
        $this->assertSame('secret-1', $config['client_secret']);
    }

    /**
     * Form values are read and cleaned with the requested parameter type.
     */
    public function test_param_reads_and_cleans_form_values(): void {
        $_POST['name'] = '<b>Ann</b> Lee';
        try {
            $this->assertSame('Ann Lee', anonymous_endpoint::param('name', PARAM_TEXT));
            $this->assertSame('', anonymous_endpoint::param('missing', PARAM_TEXT));
        } finally {
            unset($_POST['name']);
        }
    }
}
