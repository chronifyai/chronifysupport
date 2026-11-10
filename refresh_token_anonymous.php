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
 * JSON endpoint that silently refreshes the session of the login page "Get Help" form.
 *
 * Companion of get_help_anonymous.php. The widget script resends the same name
 * and email the visitor originally submitted, the external user id is derived
 * from the email exactly as there, and a fresh session token is returned. It
 * validates and rate limits independently of get_help_anonymous.php, because it
 * is a separate public endpoint.
 *
 * It is an AJAX script that starts no Moodle session, so themes that force
 * visitors to log in skip it.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

$name = trim(\local_chronifysupport\anonymous_endpoint::param('name', PARAM_TEXT));
$email = trim(\local_chronifysupport\anonymous_endpoint::param('email', PARAM_EMAIL));

if ($name === '' || mb_strlen($name) > 200) {
    \local_chronifysupport\anonymous_endpoint::fail(400, 'invalid_name');
}
if ($email === '' || mb_strlen($email) > 320) {
    \local_chronifysupport\anonymous_endpoint::fail(400, 'invalid_email');
}

// A looser limit than the first request: an open panel refreshes repeatedly.
if (!\local_chronifysupport\rate_limiter::allow('refresh', getremoteaddr(), 40)) {
    \local_chronifysupport\anonymous_endpoint::fail(429, 'too_many_requests');
}

$config = \local_chronifysupport\anonymous_endpoint::config();
if ($config === null) {
    \local_chronifysupport\anonymous_endpoint::fail(503, 'not_configured_yet');
}

if (!get_config('local_chronifysupport', 'enable_anonymous_button')) {
    \local_chronifysupport\anonymous_endpoint::fail(403, 'anonymous_button_disabled');
}

// Same derivation as get_help_anonymous.php. It must stay identical, otherwise
// a refresh would resolve to a different account than the one the visitor started with.
$externaluserid = 'anon:' . substr(hash('sha256', strtolower($email)), 0, 40);

try {
    $session = \local_chronifysupport\token_client::exchange_for_identity(
        $externaluserid,
        $email,
        $name,
        'login_page_form'
    );
} catch (\Throwable $e) {
    debugging('local_chronifysupport refresh_token_anonymous.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
    \local_chronifysupport\anonymous_endpoint::fail(502, 'exchange_failed');
}

echo json_encode([
    'access_token' => $session['access_token'],
    'expires_at' => $session['expires_at'],
]);
