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
 * Tests for the opt-in AI Agent web service setup.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

#[\PHPUnit\Framework\Attributes\CoversClass(ai_agent_provisioner::class)]
/**
 * Tests for the opt-in AI Agent web service setup.
 *
 * @covers \local_chronifysupport\ai_agent_provisioner
 */
final class ai_agent_provisioner_test extends \advanced_testcase {
    /**
     * A fresh site has no AI Agent setup and nothing creates it implicitly.
     */
    public function test_not_provisioned_by_default(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertFalse(ai_agent_provisioner::is_provisioned());
        $this->assertNull(ai_agent_provisioner::get_current_token());
        $this->assertFalse($DB->record_exists('user', ['username' => ai_agent_provisioner::SERVICE_USERNAME]));
    }

    /**
     * Refreshing never creates a setup that the administrator did not ask for.
     */
    public function test_refresh_does_not_create_setup(): void {
        global $DB;
        $this->resetAfterTest();

        ai_agent_provisioner::refresh_if_provisioned();

        $this->assertFalse(ai_agent_provisioner::is_provisioned());
        $this->assertFalse($DB->record_exists('user', ['username' => ai_agent_provisioner::SERVICE_USERNAME]));
        $this->assertFalse($DB->record_exists('external_services', ['shortname' => ai_agent_provisioner::SERVICE_SHORTNAME]));
    }

    /**
     * The explicit setup creates the service, the account and a token.
     */
    public function test_explicit_setup_creates_service_account_and_token(): void {
        $this->resetAfterTest();

        ai_agent_provisioner::ensure_provisioned();

        $this->assertEmpty(get_config('local_chronifysupport', 'ai_agent_provision_error'));
        $this->assertTrue(ai_agent_provisioner::is_provisioned());
        $this->assertNotEmpty(ai_agent_provisioner::get_current_token());
    }

    /**
     * Refreshing an existing setup keeps the same token.
     */
    public function test_refresh_keeps_existing_token(): void {
        $this->resetAfterTest();

        ai_agent_provisioner::ensure_provisioned();
        $token = ai_agent_provisioner::get_current_token();

        ai_agent_provisioner::refresh_if_provisioned();

        $this->assertSame($token, ai_agent_provisioner::get_current_token());
    }

    /**
     * Regenerating replaces the token.
     */
    public function test_regenerate_token_replaces_token(): void {
        $this->resetAfterTest();

        ai_agent_provisioner::ensure_provisioned();
        $token = ai_agent_provisioner::get_current_token();

        ai_agent_provisioner::regenerate_token();

        $this->assertNotEmpty(ai_agent_provisioner::get_current_token());
        $this->assertNotSame($token, ai_agent_provisioner::get_current_token());
    }
}
