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
 * Same-origin refresh endpoint (contract §4.3).
 *
 * The widget's browser-side code calls this endpoint (same-origin to
 * Moodle, so no CORS issues) at ~80% of the 20-minute Tier-2 token TTL.
 * It simply re-runs a fresh Tier-1 + Tier-2 exchange and returns a new
 * session token as JSON. The old token remains valid until its own
 * natural expiry (contract §2.5 Refresh flow).
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login(null, false);
require_sesskey();

header('Content-Type: application/json');

$courseid = optional_param('courseid', 0, PARAM_INT);
$course = $courseid ? get_course($courseid) : null;

try {
    $session = \local_chronifysupport\token_client::exchange_for_session($USER, $course, null);
    echo json_encode([
        'access_token' => $session['access_token'],
        'expires_at' => $session['expires_at'],
    ]);
} catch (\moodle_exception $e) {
    http_response_code(401);
    echo json_encode(['error' => $e->getMessage()]);
}
