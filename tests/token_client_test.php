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
 * Tests for the Tier-1 / Tier-2 token client.
 *
 * @package    local_chronifysupport
 * @category   test
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

#[\PHPUnit\Framework\Attributes\CoversClass(token_client::class)]
/**
 * Tests for the Tier-1 / Tier-2 token client.
 *
 * @covers \local_chronifysupport\token_client
 */
final class token_client_test extends \advanced_testcase {
    /**
     * Decodes one base64url JWT segment.
     *
     * @param string $segment
     * @return string
     */
    private function decode_segment(string $segment): string {
        return base64_decode(strtr($segment, '-_', '+/'));
    }

    /**
     * Without configuration the exchange fails with the "not configured" error
     * before any network request is made.
     */
    public function test_exchange_requires_configuration(): void {
        $this->resetAfterTest();
        unset_config('helpdesk_base_url', 'local_chronifysupport');
        unset_config('client_id', 'local_chronifysupport');
        unset_config('client_secret', 'local_chronifysupport');
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_not_configured', 'local_chronifysupport'));
        token_client::exchange_for_session($user);
    }

    /**
     * The user assertion is an HS256 JWT signed with the installation token,
     * carrying the user details and a short lifetime.
     */
    public function test_build_assertion_is_signed_jwt(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'learner@example.com']);

        $method = new \ReflectionMethod(token_client::class, 'build_assertion');
        $jwt = $method->invoke(null, 'installation-token', $user, null, 'Test page');

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $header = json_decode($this->decode_segment($parts[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $payload = json_decode($this->decode_segment($parts[1]), true);
        $this->assertSame((string) $user->id, $payload['external_user_id']);
        $this->assertSame('learner@example.com', $payload['email']);
        $this->assertSame('Test page', $payload['activity_context']);
        $this->assertNull($payload['course_id']);
        $this->assertSame(token_client::ASSERTION_TTL_SECONDS, $payload['exp'] - $payload['iat']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['nonce']
        );

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $parts[0] . '.' . $parts[1], 'installation-token', true)
        ), '+/', '-_'), '=');
        $this->assertSame($expected, $parts[2]);
    }

    /**
     * Course details are included when a course is given.
     */
    public function test_build_assertion_includes_course(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Safety basics']);

        $method = new \ReflectionMethod(token_client::class, 'build_assertion');
        $jwt = $method->invoke(null, 'installation-token', $user, $course, null);

        $payload = json_decode($this->decode_segment(explode('.', $jwt)[1]), true);
        $this->assertSame((string) $course->id, $payload['course_id']);
        $this->assertSame('Safety basics', $payload['course_name']);
    }

    /**
     * The assertion for a visitor who is not logged in carries the given identity and no course.
     */
    public function test_build_signed_assertion_for_visitor_identity(): void {
        $method = new \ReflectionMethod(token_client::class, 'build_signed_assertion');
        $jwt = $method->invoke(
            null,
            'installation-token',
            'visitor:abc-123',
            null,
            'Website Visitor',
            null,
            null,
            'visitor_invite'
        );

        $payload = json_decode($this->decode_segment(explode('.', $jwt)[1]), true);
        $this->assertSame('visitor:abc-123', $payload['external_user_id']);
        $this->assertNull($payload['email']);
        $this->assertSame('Website Visitor', $payload['full_name']);
        $this->assertNull($payload['course_id']);
        $this->assertSame('visitor_invite', $payload['activity_context']);
    }
}
