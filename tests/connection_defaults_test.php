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
 * Tests for the default connection values.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Tests for the default connection values.
 *
 * @covers \local_chronifysupport\connection_defaults
 */
#[\PHPUnit\Framework\Attributes\CoversClass(connection_defaults::class)]
final class connection_defaults_test extends \advanced_testcase {
    /**
     * A new installation comes with the connection settings filled in.
     */
    public function test_settings_are_filled_in_after_install(): void {
        $this->assertSame(
            connection_defaults::HELPDESK_BASE_URL,
            get_config('local_chronifysupport', 'helpdesk_base_url')
        );
        $this->assertSame(connection_defaults::SUPABASE_URL, get_config('local_chronifysupport', 'supabase_url'));
        $this->assertSame(
            connection_defaults::SUPABASE_ANON_KEY,
            get_config('local_chronifysupport', 'supabase_anon_key')
        );
    }

    /**
     * Empty settings are filled in, and settings that already have a value are left alone.
     */
    public function test_apply_missing_never_overwrites(): void {
        $this->resetAfterTest();
        set_config('supabase_url', 'https://custom.example.com', 'local_chronifysupport');
        set_config('helpdesk_base_url', '', 'local_chronifysupport');
        unset_config('supabase_anon_key', 'local_chronifysupport');

        connection_defaults::apply_missing();

        $this->assertSame('https://custom.example.com', get_config('local_chronifysupport', 'supabase_url'));
        $this->assertSame(
            connection_defaults::HELPDESK_BASE_URL,
            get_config('local_chronifysupport', 'helpdesk_base_url')
        );
        $this->assertSame(
            connection_defaults::SUPABASE_ANON_KEY,
            get_config('local_chronifysupport', 'supabase_anon_key')
        );
    }
}
