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
