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

#[\PHPUnit\Framework\Attributes\CoversClass(anonymous_endpoint::class)]
/**
 * Tests for the helpers of the public endpoints.
 *
 * @covers \local_chronifysupport\anonymous_endpoint
 */
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
     * The signing secret is created once, kept, and not created when only reading.
     */
    public function test_identity_key_is_created_once(): void {
        $this->resetAfterTest();
        unset_config(anonymous_endpoint::IDENTITY_KEY_SETTING, 'local_chronifysupport');
        $this->assertSame('', anonymous_endpoint::identity_key(false));

        $key = anonymous_endpoint::identity_key();
        $this->assertGreaterThanOrEqual(32, strlen($key));
        $this->assertSame($key, anonymous_endpoint::identity_key());
        $this->assertSame($key, anonymous_endpoint::identity_key(false));
    }

    /**
     * The identity depends on the e-mail address and on the browser key.
     */
    public function test_identity_is_bound_to_email_and_browser(): void {
        $this->resetAfterTest();
        $browser = 'a1b2c3d4-e5f6-4789-a1b2-c3d4e5f60718';
        $other = 'ffffffff-e5f6-4789-a1b2-c3d4e5f60718';

        $identity = anonymous_endpoint::derive_identity('Ann@Example.com', $browser);
        $this->assertMatchesRegularExpression('/^anon:[0-9a-f]{40}$/', $identity);

        // Same visitor, same browser: same identity (also if the e-mail case or spaces differ).
        $this->assertSame($identity, anonymous_endpoint::derive_identity(' ann@example.com ', $browser));
        // Same e-mail address in another browser: a different identity.
        $this->assertNotSame($identity, anonymous_endpoint::derive_identity('ann@example.com', $other));
        // Another e-mail address in the same browser: a different identity.
        $this->assertNotSame($identity, anonymous_endpoint::derive_identity('bob@example.com', $browser));
    }

    /**
     * The browser key is validated for length and characters.
     */
    public function test_visitor_key_is_validated(): void {
        $_POST['visitor_key'] = 'a1b2c3d4-e5f6-4789-a1b2-c3d4e5f60718';
        try {
            $this->assertSame('a1b2c3d4-e5f6-4789-a1b2-c3d4e5f60718', anonymous_endpoint::visitor_key());
            $_POST['visitor_key'] = 'short';
            $this->assertSame('', anonymous_endpoint::visitor_key());
            unset($_POST['visitor_key']);
            $this->assertSame('', anonymous_endpoint::visitor_key());
        } finally {
            unset($_POST['visitor_key']);
        }
    }

    /**
     * Only a cookie signed by this site is accepted.
     */
    public function test_pending_cookie_must_be_signed_by_this_site(): void {
        $this->resetAfterTest();
        $identity = 'anon:' . str_repeat('a', 40);

        $value = anonymous_endpoint::pending_cookie_value($identity);
        $read = anonymous_endpoint::read_pending_cookie($value);
        $this->assertSame($identity, $read['external_user_id']);
        $this->assertEqualsWithDelta(time(), $read['ts'], 5);

        // A different identity with the old signature is rejected.
        $data = json_decode($value, true);
        $data['external_user_id'] = 'anon:' . str_repeat('b', 40);
        $this->assertNull(anonymous_endpoint::read_pending_cookie(json_encode($data)));

        // An older issue time with the old signature is rejected.
        $data = json_decode($value, true);
        $data['ts'] = $data['ts'] - 60;
        $this->assertNull(anonymous_endpoint::read_pending_cookie(json_encode($data)));

        // Unsigned (old format), empty and malformed values are rejected.
        $unsigned = json_encode(['external_user_id' => $identity, 'ts' => time()]);
        $this->assertNull(anonymous_endpoint::read_pending_cookie($unsigned));
        $this->assertNull(anonymous_endpoint::read_pending_cookie(''));
        $this->assertNull(anonymous_endpoint::read_pending_cookie('not json'));
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
