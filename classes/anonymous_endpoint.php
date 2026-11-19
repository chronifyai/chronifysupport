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
 * Shared helpers for the public (not logged in) JSON endpoints.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Shared helpers for the public (not logged in) JSON endpoints.
 *
 * The endpoints (get_help_anonymous.php, refresh_token_anonymous.php and
 * accept_invite.php) are called by the widget script with a JSON body, so
 * values are read with optional_param() first and from the JSON body second.
 */
class anonymous_endpoint {
    /** @var string Name of the plugin setting that holds the signing secret. */
    const IDENTITY_KEY_SETTING = 'anon_identity_key';

    /** @var array|null Decoded JSON request body, read once. */
    protected static $jsonbody = null;

    /**
     * Sends a JSON error response and stops.
     *
     * @param int $httpcode HTTP status code.
     * @param string $error Short error code for the caller.
     */
    public static function fail(int $httpcode, string $error): void {
        http_response_code($httpcode);
        header('Content-Type: application/json');
        echo json_encode(['error' => $error]);
        die();
    }

    /**
     * Reads a text parameter from the request (form value or JSON body) and cleans it.
     *
     * @param string $name Parameter name.
     * @param string $type A PARAM_* type.
     * @return string The cleaned value, or an empty string if missing.
     */
    public static function param(string $name, string $type): string {
        $value = optional_param($name, '', $type);
        if ($value !== '') {
            return $value;
        }

        $body = self::json_body();
        if (isset($body[$name]) && is_scalar($body[$name])) {
            return clean_param((string) $body[$name], $type);
        }
        return '';
    }

    /**
     * Reads the plugin settings the public endpoints need.
     *
     * @return array{helpdesk_base_url: string, client_id: string, client_secret: string}|null Null if not configured.
     */
    public static function config(): ?array {
        $config = [
            'helpdesk_base_url' => trim((string) get_config('local_chronifysupport', 'helpdesk_base_url')),
            'client_id' => trim((string) get_config('local_chronifysupport', 'client_id')),
            'client_secret' => trim((string) get_config('local_chronifysupport', 'client_secret')),
        ];
        foreach ($config as $value) {
            if ($value === '') {
                return null;
            }
        }
        return $config;
    }

    /**
     * Returns the secret used to sign anonymous identities and the merge cookie.
     *
     * It is a random value of this plugin, stored in its own settings, and it is
     * never sent to the browser. It is created on first use when it is missing.
     *
     * @param bool $create Create the secret when it does not exist yet.
     * @return string The secret, or an empty string if it does not exist and $create is false.
     */
    public static function identity_key(bool $create = true): string {
        $key = (string) get_config('local_chronifysupport', self::IDENTITY_KEY_SETTING);
        if (strlen($key) >= 32 || !$create) {
            return strlen($key) >= 32 ? $key : '';
        }

        set_config(self::IDENTITY_KEY_SETTING, bin2hex(random_bytes(32)), 'local_chronifysupport');
        $key = (string) get_config('local_chronifysupport', self::IDENTITY_KEY_SETTING);
        return strlen($key) >= 32 ? $key : '';
    }

    /**
     * Reads the visitor key of the browser from the request.
     *
     * @return string The cleaned key, or an empty string if it is missing or malformed.
     */
    public static function visitor_key(): string {
        $key = self::param('visitor_key', PARAM_ALPHANUMEXT);
        $length = strlen($key);
        return ($length >= 16 && $length <= 64) ? $key : '';
    }

    /**
     * Builds the helpdesk identity of an anonymous visitor.
     *
     * The identity depends on the e-mail address AND on the visitor key of the
     * browser, signed with the secret of this site. Knowing an e-mail address is
     * therefore not enough to reach the conversation of that person.
     *
     * @param string $email E-mail address entered in the form.
     * @param string $visitorkey Visitor key of the browser.
     * @return string Identity in the form "anon:<40 hex characters>".
     */
    public static function derive_identity(string $email, string $visitorkey): string {
        $key = self::identity_key();
        if ($key === '') {
            throw new \coding_exception('The signing secret of local_chronifysupport could not be created.');
        }
        $mac = hash_hmac('sha256', strtolower(trim($email)) . '|' . $visitorkey, $key);
        return 'anon:' . substr($mac, 0, 40);
    }

    /**
     * Builds the value of the cookie that remembers the anonymous identity
     * until the visitor logs in (used to merge the conversation).
     *
     * @param string $externaluserid Anonymous identity.
     * @return string JSON value signed with the secret of this site.
     */
    public static function pending_cookie_value(string $externaluserid): string {
        $key = self::identity_key();
        if ($key === '') {
            throw new \coding_exception('The signing secret of local_chronifysupport could not be created.');
        }
        $issued = time();
        return json_encode([
            'external_user_id' => $externaluserid,
            'ts' => $issued,
            'sig' => hash_hmac('sha256', $externaluserid . '|' . $issued, $key),
        ]);
    }

    /**
     * Checks the cookie of pending_cookie_value() and returns its content.
     *
     * @param string $raw Cookie value.
     * @return array|null Array with external_user_id and ts, or null if the cookie is
     *                    missing, malformed or not signed by this site.
     */
    public static function read_pending_cookie(string $raw): ?array {
        $key = self::identity_key(false);
        if ($raw === '' || $key === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (
            !is_array($data)
            || empty($data['external_user_id']) || !is_string($data['external_user_id'])
            || !isset($data['ts']) || !is_numeric($data['ts'])
            || empty($data['sig']) || !is_string($data['sig'])
        ) {
            return null;
        }

        $expected = hash_hmac('sha256', $data['external_user_id'] . '|' . (int) $data['ts'], $key);
        if (!hash_equals($expected, $data['sig'])) {
            return null;
        }
        return ['external_user_id' => $data['external_user_id'], 'ts' => (int) $data['ts']];
    }

    /**
     * Returns the decoded JSON request body (empty array if there is none).
     *
     * @return array
     */
    protected static function json_body(): array {
        if (self::$jsonbody === null) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            self::$jsonbody = is_array($decoded) ? $decoded : [];
        }
        return self::$jsonbody;
    }
}
