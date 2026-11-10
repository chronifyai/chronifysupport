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
 * JSON endpoint behind the site-wide visitor beacon, used when an agent starts a conversation.
 *
 * The beacon calls this endpoint when it learns (from its heartbeat) that an
 * agent has started a conversation with this anonymous browser. The visitor key
 * the beacon generated becomes a synthetic identity ("visitor:<key>"), a
 * Tier-1 / Tier-2 exchange is run for it (see classes/token_client.php) and the
 * session is returned. No name or email is collected.
 *
 * It is an AJAX script that starts no Moodle session, so themes that force
 * visitors to log in skip it. Abuse protection: a strict key format and a
 * per-address rate limit.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

$visitorkey = trim(\local_chronifysupport\anonymous_endpoint::param('visitor_key', PARAM_RAW_TRIMMED));

// The visitor key is generated in the browser and ends up in the assertion sent
// to the helpdesk, so only a conservative character set is accepted.
if ($visitorkey === '' || strlen($visitorkey) > 64 || !preg_match('/^[a-zA-Z0-9\-]+$/', $visitorkey)) {
    \local_chronifysupport\anonymous_endpoint::fail(400, 'invalid_visitor_key');
}

if (!\local_chronifysupport\rate_limiter::allow('invite', getremoteaddr(), 8)) {
    \local_chronifysupport\anonymous_endpoint::fail(429, 'too_many_requests');
}

$config = \local_chronifysupport\anonymous_endpoint::config();
if ($config === null) {
    \local_chronifysupport\anonymous_endpoint::fail(503, 'not_configured_yet');
}

// Defence in depth: do not run the exchange if visitor tracking is off.
if (!get_config('local_chronifysupport', 'enable_visitor_tracking')) {
    \local_chronifysupport\anonymous_endpoint::fail(403, 'visitor_tracking_disabled');
}

$externaluserid = 'visitor:' . $visitorkey;

try {
    $session = \local_chronifysupport\token_client::exchange_for_identity(
        $externaluserid,
        null,
        'Website Visitor',
        'visitor_invite'
    );
} catch (\Throwable $e) {
    debugging('local_chronifysupport accept_invite.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
    \local_chronifysupport\anonymous_endpoint::fail(502, 'exchange_failed');
}

echo json_encode([
    'access_token' => $session['access_token'],
    'expires_at' => $session['expires_at'],
    'user_id' => $session['user_id'],
    'helpdesk_base_url' => rtrim($config['helpdesk_base_url'], '/'),
]);
