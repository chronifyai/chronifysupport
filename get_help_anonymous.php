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
 * JSON endpoint behind the "Get Help" form on the Moodle login page.
 *
 * Used by visitors who are not logged in (for example someone who forgot
 * their password). Accepts a name and email address from the form, derives a
 * stable external user id from the email (so a repeat visitor using the same
 * email resumes the same conversation), runs the Tier-1 / Tier-2 token
 * exchange (see classes/token_client.php) and returns the resulting session.
 *
 * It is an AJAX script that starts no Moodle session. Themes that force
 * visitors to log in skip AJAX scripts, so the endpoint stays reachable.
 * Abuse protection: a hidden honeypot field and a per-address rate limit.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

// Honeypot: a hidden field real visitors never fill in. Pretend success so
// scripted submitters do not learn it is a trap.
if (\local_chronifysupport\anonymous_endpoint::param('website', PARAM_TEXT) !== '') {
    echo json_encode(['error' => 'submission_rejected']);
    die();
}

$name = trim(\local_chronifysupport\anonymous_endpoint::param('name', PARAM_TEXT));
$email = trim(\local_chronifysupport\anonymous_endpoint::param('email', PARAM_EMAIL));

if ($name === '' || mb_strlen($name) > 200) {
    \local_chronifysupport\anonymous_endpoint::fail(400, 'invalid_name');
}
if ($email === '' || mb_strlen($email) > 320) {
    \local_chronifysupport\anonymous_endpoint::fail(400, 'invalid_email');
}

if (!\local_chronifysupport\rate_limiter::allow('anon', getremoteaddr(), 8)) {
    \local_chronifysupport\anonymous_endpoint::fail(429, 'too_many_requests');
}

$config = \local_chronifysupport\anonymous_endpoint::config();
if ($config === null) {
    \local_chronifysupport\anonymous_endpoint::fail(503, 'not_configured_yet');
}

// Defence in depth: do not run the exchange if the setting is off.
if (!get_config('local_chronifysupport', 'enable_anonymous_button')) {
    \local_chronifysupport\anonymous_endpoint::fail(403, 'anonymous_button_disabled');
}

// Deterministic per-email identity: repeat contacts using the same email
// resolve to the same helpdesk account and conversation, without any cookie
// or session on this side.
$externaluserid = 'anon:' . substr(hash('sha256', strtolower($email)), 0, 40);

try {
    $session = \local_chronifysupport\token_client::exchange_for_identity(
        $externaluserid,
        $email,
        $name,
        'login_page_form'
    );
} catch (\Throwable $e) {
    debugging('local_chronifysupport get_help_anonymous.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
    \local_chronifysupport\anonymous_endpoint::fail(502, 'exchange_failed');
}

// Best-effort "same browser" merge signal: if this visitor later logs in to
// Moodle in this browser, hook_callbacks::before_footer() picks this cookie up
// and merges the anonymous helpdesk identity into the real account. It is
// httponly and a session cookie (dropped when the browser is closed), and it
// carries its own issue time so the merge only happens within a short window
// (see hook_callbacks::PENDING_ANON_MAX_AGE). This prevents a different person
// who logs in later on a shared computer from inheriting this conversation.
setcookie('local_chronifysupport_pending_anon', json_encode([
    'external_user_id' => $externaluserid,
    'email' => $email,
    'ts' => time(),
]), [
    'expires' => 0,
    'path' => '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);

echo json_encode([
    'access_token' => $session['access_token'],
    'expires_at' => $session['expires_at'],
    'user_id' => $session['user_id'],
    'helpdesk_base_url' => rtrim($config['helpdesk_base_url'], '/'),
    'full_name' => $name,
]);
