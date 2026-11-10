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
 * Client for the Tier-1 / Tier-2 token exchange.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/filelib.php'); // Moodle's \curl class.

/**
 * Implements the Tier-1 / Tier-2 exchange described in
 * the Tier-1/Tier-2 token contract.
 *
 * The Chronify.Support API is the server side of this contract.
 * This class is the Moodle side.
 */
class token_client {
    /** @var int Tier-2 assertion lifetime in seconds. Kept short deliberately. */
    const ASSERTION_TTL_SECONDS = 60;

    /**
     * Runs the full Tier-1 -> Tier-2 exchange for the given user/course
     * context and returns a helpdesk session.
     *
     * @param \stdClass $user Moodle user record (usually $USER).
     * @param \stdClass|null $course Optional course record for context.
     * @param string|null $activitycontext Optional free-text activity/page description.
     * @return array{access_token: string, expires_at: string, user_id: string}
     * @throws \moodle_exception on any failure (missing config, network, rejected by server, etc).
     */
    public static function exchange_for_session(
        \stdClass $user,
        ?\stdClass $course = null,
        ?string $activitycontext = null
    ): array {
        [$baseurl, $clientid, $clientsecret] = self::get_config();

        $t0 = microtime(true);
        $installationtoken = self::request_tier1_token($baseurl, $clientid, $clientsecret);
        $t1 = microtime(true);
        $assertion = self::build_assertion($installationtoken, $user, $course, $activitycontext);
        $session = self::request_tier2_session($baseurl, $installationtoken, $assertion);
        $t2 = microtime(true);
        // Diagnostics only: per-step durations of the last exchange (ms).
        self::$lasttimings = [
            'tier1' => (int) round(($t1 - $t0) * 1000),
            'tier2' => (int) round(($t2 - $t1) * 1000),
        ];
        return $session;
    }

    /**
     * Runs the full Tier-1 -> Tier-2 exchange for a caller-supplied identity
     * (a visitor who is not logged in to Moodle) and returns a helpdesk session.
     *
     * @param string $externaluserid Stable identifier of the visitor, for example "anon:<hash>".
     * @param string|null $email Email address of the visitor, if known.
     * @param string $fullname Display name of the visitor.
     * @param string|null $activitycontext Optional free-text description of where the request came from.
     * @return array{access_token: string, expires_at: string, user_id: string}
     * @throws \moodle_exception on any failure (missing config, network, rejected by server, etc).
     */
    public static function exchange_for_identity(
        string $externaluserid,
        ?string $email,
        string $fullname,
        ?string $activitycontext
    ): array {
        [$baseurl, $clientid, $clientsecret] = self::get_config();

        $installationtoken = self::request_tier1_token($baseurl, $clientid, $clientsecret);
        $assertion = self::build_signed_assertion(
            $installationtoken,
            $externaluserid,
            $email,
            $fullname,
            null,
            null,
            $activitycontext
        );
        return self::request_tier2_session($baseurl, $installationtoken, $assertion);
    }

    /** @var array{tier1: int, tier2: int}|null Durations (ms) of the last exchange_for_session() call. */
    public static $lasttimings = null;

    /**
     * Best-effort call to the Chronify.Support merge endpoint, made when a visitor who
     * previously used the anonymous login-page "Get Help" form (see
     * get_help_anonymous.php) subsequently logs into Moodle for real in the
     * same browser (see hook_callbacks::maybe_merge_anonymous_identity(),
     * the only caller). Reuses the same Tier-1 exchange as
     * exchange_for_session() to obtain a fresh installation_token, and
     * trusts that token to authorize the merge exactly as it authorizes a
     * normal Tier-2 exchange -- no separate credential/contract needed.
     *
     * Deliberately void: the caller treats this as fire-and-forget and
     * decides for itself (based on whether an exception was thrown) whether
     * to clear the pending-merge cookie or leave it for a retry on the next
     * page view.
     *
     * @param string $anonexternaluserid External user id of the anonymous identity (anon:...).
     * @param string $realexternaluserid External user id of the real Moodle user.
     * @throws \moodle_exception on any failure (missing config, network, rejected by server, etc).
     */
    public static function merge_anonymous_identity(string $anonexternaluserid, string $realexternaluserid): void {
        [$baseurl, $clientid, $clientsecret] = self::get_config();
        $installationtoken = self::request_tier1_token($baseurl, $clientid, $clientsecret);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => false]); // Same rationale as Tier-1/Tier-2 above.
        $curl->setHeader('Content-Type: application/json');

        $response = $curl->post($baseurl . '/api/public/moodle/merge-anonymous-identity', json_encode([
            'installation_token' => $installationtoken,
            'anonymous_external_user_id' => $anonexternaluserid,
            'real_external_user_id' => $realexternaluserid,
        ]));

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);

        if ($httpcode !== 200) {
            $body = json_decode($response, true);
            $errorcode = (is_array($body) ? ($body['error'] ?? null) : null) ?? ('http_' . $httpcode);
            throw new \moodle_exception('error_exchange_failed', 'local_chronifysupport', '', $errorcode);
        }
    }

    /**
     * Reads and validates plugin configuration.
     *
     * @return array{0: string, 1: string, 2: string} [base_url, client_id, client_secret]
     * @throws \moodle_exception if not configured.
     */
    protected static function get_config(): array {
        $baseurl = get_config('local_chronifysupport', 'helpdesk_base_url');
        $clientid = get_config('local_chronifysupport', 'client_id');
        $clientsecret = get_config('local_chronifysupport', 'client_secret');

        if (empty($baseurl) || empty($clientid) || empty($clientsecret)) {
            throw new \moodle_exception('error_not_configured', 'local_chronifysupport');
        }

        return [rtrim($baseurl, '/'), $clientid, $clientsecret];
    }

    /**
     * Step 1 (contract §3): exchange client_id + client_secret for a
     * short-lived, single-use installation_token.
     *
     * @param string $baseurl Help desk base URL, without trailing slash.
     * @param string $clientid Client ID of this installation.
     * @param string $clientsecret Client secret of this installation.
     * @return string The installation token.
     */
    protected static function request_tier1_token(string $baseurl, string $clientid, string $clientsecret): string {
        $curl = new \curl();
        // Deliberately don't follow redirects: a 3xx here means something is
        // misconfigured (wrong base URL, auth-gated preview domain, etc.) and
        // we want a clear error rather than silently following it, since that
        // could otherwise leak client_secret to an unintended host.
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => false]);
        $curl->setHeader('Content-Type: application/json');

        $response = $curl->post($baseurl . '/api/public/moodle/tier1-token', json_encode([
            'client_id' => $clientid,
            'client_secret' => $clientsecret,
        ]));

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $body = json_decode($response, true);

        if ($httpcode !== 200 || empty($body['installation_token'])) {
            $errorcode = $body['error'] ?? ('http_' . $httpcode);
            throw new \moodle_exception('error_exchange_failed', 'local_chronifysupport', '', $errorcode);
        }

        return $body['installation_token'];
    }

    /**
     * Step 2 (contract §4.1): build and sign the user assertion JWT using
     * the installation_token itself as the HMAC-SHA256 key.
     *
     * @param string $installationtoken Installation token used as the signing key.
     * @param \stdClass $user The user the assertion identifies.
     * @param \stdClass|null $course The current course, if any.
     * @param string|null $activitycontext Short description of where the user is, if any.
     * @return string The signed JWT.
     */
    protected static function build_assertion(
        string $installationtoken,
        \stdClass $user,
        ?\stdClass $course,
        ?string $activitycontext
    ): string {
        return self::build_signed_assertion(
            $installationtoken,
            (string) $user->id,
            $user->email,
            fullname($user),
            $course ? (string) $course->id : null,
            $course ? format_string($course->fullname) : null,
            $activitycontext
        );
    }

    /**
     * Builds and signs the assertion JWT for the given identity and context.
     *
     * @param string $installationtoken The installation token, used as the HMAC key.
     * @param string $externaluserid Stable identifier of the person.
     * @param string|null $email Email address, if known.
     * @param string $fullname Display name.
     * @param string|null $courseid Course id, if any.
     * @param string|null $coursename Course name, if any.
     * @param string|null $activitycontext Free-text description of the page or activity.
     * @return string The signed JWT.
     */
    protected static function build_signed_assertion(
        string $installationtoken,
        string $externaluserid,
        ?string $email,
        string $fullname,
        ?string $courseid,
        ?string $coursename,
        ?string $activitycontext
    ): string {
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'external_user_id' => $externaluserid,
            'email' => $email,
            'full_name' => $fullname,
            'course_id' => $courseid,
            'course_name' => $coursename,
            'activity_context' => $activitycontext,
            'iat' => $now,
            'exp' => $now + self::ASSERTION_TTL_SECONDS,
            'nonce' => self::generate_uuid_v4(),
        ];

        $segments = [
            self::base64url_encode(json_encode($header)),
            self::base64url_encode(json_encode($payload)),
        ];

        $signinginput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signinginput, $installationtoken, true);
        $segments[] = self::base64url_encode($signature);

        return implode('.', $segments);
    }

    /**
     * Step 3 (contract §4.2): exchange installation_token + assertion for
     * a Supabase session token scoped to this end user.
     *
     * @param string $baseurl Help desk base URL, without trailing slash.
     * @param string $installationtoken Installation token from request_tier1_token().
     * @param string $assertion Signed assertion from build_assertion().
     * @return array The session (access_token, expires_at, user_id).
     */
    protected static function request_tier2_session(string $baseurl, string $installationtoken, string $assertion): array {
        $curl = new \curl();
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => false]); // Same rationale as Tier-1: no silent redirect-following.
        $curl->setHeader('Content-Type: application/json');

        $response = $curl->post($baseurl . '/api/public/moodle/tier2-token', json_encode([
            'installation_token' => $installationtoken,
            'assertion' => $assertion,
        ]));

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $body = json_decode($response, true);

        if ($httpcode !== 200 || empty($body['access_token'])) {
            $errorcode = $body['error'] ?? ('http_' . $httpcode);
            throw new \moodle_exception('error_exchange_failed', 'local_chronifysupport', '', $errorcode);
        }

        return [
            'access_token' => $body['access_token'],
            'expires_at' => $body['expires_at'],
            'user_id' => $body['user_id'],
        ];
    }

    /**
     * RFC 4648 base64url encoding (no padding), as required for JWT segments.
     *
     * @param string $data Raw bytes to encode.
     * @return string The encoded string.
     */
    protected static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Simple UUID v4 generator (no external dependency).
     */
    protected static function generate_uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
