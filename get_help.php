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
 * Entry point for the "Get Help" button.
 *
 * Runs the Tier-1 -> Tier-2 exchange (see classes/token_client.php and
 * the Tier-1/Tier-2 token contract) for the currently logged-in user,
 * then embeds the real end-user-facing widget in an iframe,
 * passing the session token via the URL fragment and arming a silent
 * refresh timer — see contract §10 for the full embed design and the
 * reasoning behind each choice below.
 *
 * This page is designed to fill whatever frame loads it edge-to-edge (no
 * positioning/sizing of its own) — the floating corner-panel look is set up
 * one level up, by classes/hook_callbacks.php, which loads this page inside
 * a small fixed-position iframe on the course page instead of navigating
 * the browser tab here directly (see contract §12).
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Send output as soon as it is produced (instead of holding the whole page
// until the end), so the browser can start working while the slow token
// exchange below is still running. Standard Moodle constant; must be defined
// before config.php.
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
// Visitor-context params forwarded from classes/hook_callbacks.php, captured
// there because that's the only place in this request with direct access to
// the actual course/activity page the requester was looking at — this page
// is just a same-origin passthrough loaded inside an iframe on that page.
$pagetitleparam = optional_param('page_title', '', PARAM_TEXT);
$pageurlparam = optional_param('page_url', '', PARAM_URL);
$activitynameparam = optional_param('activity_name', '', PARAM_TEXT);

// Client-generated visitor_key (see classes/hook_callbacks.php::getVisitorKey()).
// Read straight from the first-party cookie the site-wide visitor beacon
// already sets, rather than threading it through as another query param:
// the beacon (when enabled) always runs earlier in the very same page's
// footer than any "Get Help" click that could bring a browser here, so the
// cookie is reliably already present by the time this request happens. This
// lets the widget report ticket-created / tab-closed events straight to
// Supabase using the same visitor_key the "Visitors" page keys presence
// rows on, instead of waiting on the host page's own next heartbeat tick.
// Empty when the visitor-tracking feature is off or hasn't run yet for this
// browser -- the widget simply won't have a visitor_key to report with.
// The pre-rename cookie name (local_helpdesk_visitor_key) is accepted as a fallback.
$visitorkeyraw = $_COOKIE['local_chronifysupport_visitor_key'] ?? ($_COOKIE['local_helpdesk_visitor_key'] ?? null);
$visitorkeycookie = $visitorkeyraw !== null
    ? clean_param((string) $visitorkeyraw, PARAM_ALPHANUMEXT)
    : '';

// Same site-wide client_id already used by classes/hook_callbacks.php's own
// RPC calls (heartbeat, page-view tracking, etc.) -- the widget needs this
// in the launch URL itself (alongside visitor_key above) to know which
// Supabase RPC calls to make when it reports ticket-created / tab-closed
// events directly, straight from the fragment, without waiting on any
// Moodle-side round trip.
$clientid = trim((string) get_config('local_chronifysupport', 'client_id'));

require_login($courseid ?: null, false);
require_sesskey();

$context = $courseid ? \context_course::instance($courseid) : \context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/chronifysupport/get_help.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('get_help_button', 'local_chronifysupport'));
// The 'embedded' layout strips theme chrome (nav, blocks, footer links) — intended for
// exactly this use case: a page that only ever renders inside someone
// else's iframe, never as a standalone destination.
$PAGE->set_pagelayout('embedded');

$course = $courseid ? get_course($courseid) : null;
// Real activity/module name when hook_callbacks.php captured one (the page
// the "Get Help" button was clicked from belongs to a course module, e.g.
// an assignment or forum) — null on a plain course page or the site front page.
$activitycontext = $activitynameparam !== '' ? $activitynameparam : null;

echo $OUTPUT->header();

// Zero out whatever margin/padding the theme's embedded layout still adds,
// so the widget iframe below truly fills its container edge-to-edge.
//
// Note: the exact chain of wrapper elements between <body> and the widget
// iframe depends on the active theme's embedded.php layout. The Boost-based
// theme used here nests the main content as
// #page > section.embedded-main > div[role="main"] > iframe — neither
// `.embedded-main` nor `div[role="main"]` is a `<main>` element, so the CSS
// tag-selector `main` below does NOT match either of them. Without an
// explicit height on every element in that chain, `height: 100%` on the
// iframe itself resolves to `auto` (percentage heights only work against an
// ancestor with a definite height), and the iframe silently falls back to
// the browser's default replaced-element height of 150px — which is exactly
// the "large empty gray gap" bug reported when this widget is embedded in
// Moodle (confirmed via computed-height inspection: #page = 421px, the
// widget iframe itself = 150px). `.embedded-main` and `[role="main"]` are
// listed explicitly below to close that gap; if a different theme is used
// and this recurs, re-run the same computed-height inspection to find
// whichever wrapper class/attribute that theme uses instead.
//
// overflow:hidden on the document and on the wrapper chain: this page is only
// a passthrough frame around the widget iframe (the widget scrolls its own
// message list internally), so the page itself must never scroll. Without it,
// any few pixels of extra height from the theme's wrappers (borders, hidden
// blocks, sub-pixel rounding) produce a vertical scrollbar along the edge of
// the panel on Windows, where scrollbars take up space (macOS overlay
// scrollbars hide the problem). box-sizing keeps padding/borders inside the
// 100% height.
echo html_writer::tag('style', 'html, body { margin: 0 !important; padding: 0 !important; height: 100%;'
    . ' overflow: hidden !important; background: #fff; }'
    . '#page, #page-content, #region-main, .container-fluid, main,'
    . ' .embedded-main, [role="main"] { margin: 0 !important; padding: 0 !important; height: 100% !important;'
    . ' min-height: 0 !important; overflow: hidden !important; box-sizing: border-box; }');

// Performance: the Tier-1/Tier-2 exchange below makes two server-to-server
// calls and can take a noticeable moment. Right away (before it starts) we
// (a) let the browser resolve the helpdesk app's DNS name now (dns-prefetch;
// a full preconnect would not be reused by the nested cross-site widget
// iframe because of browser connection partitioning), and (b) flush the page
// so far with a small loading indicator instead of a blank white panel. Both
// are purely additive; the widget iframe below covers the indicator once it
// paints.
$earlyorigin = null;
$earlybase = rtrim((string) get_config('local_chronifysupport', 'helpdesk_base_url'), '/');
$earlyparts = $earlybase !== '' ? parse_url($earlybase) : false;
if (!empty($earlyparts['scheme']) && !empty($earlyparts['host'])) {
    $earlyorigin = $earlyparts['scheme'] . '://' . $earlyparts['host']
        . (isset($earlyparts['port']) ? ':' . $earlyparts['port'] : '');
    echo html_writer::empty_tag('link', ['rel' => 'dns-prefetch', 'href' => $earlyorigin]);
}
// Same indicator as the host page's panel (hook_callbacks.php), so the
// hand-over between the two is seamless.
echo html_writer::div(
    \local_chronifysupport\hook_callbacks::loader_svg()
        . html_writer::div(
            s(\local_chronifysupport\hook_callbacks::string_or('widget_loading', 'Connecting you to support…')),
            '',
            ['id' => 'local-chronifysupport-loading-text']
        ),
    '',
    ['id' => 'local-chronifysupport-loading', 'role' => 'status', 'aria-live' => 'polite']
);
echo html_writer::tag(
    'style',
    '#local-chronifysupport-loading { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex;'
    . ' flex-direction: column; align-items: center; justify-content: center; gap: 14px; padding: 24px;'
    . ' text-align: center; background: #fff; color: #6b7280;'
    . ' font: 15px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }'
    . '#local-chronifysupport-widget-frame { position: relative; z-index: 1; }'
);
$slowtext = \local_chronifysupport\hook_callbacks::js_value(
    \local_chronifysupport\hook_callbacks::string_or('widget_loading_slow', 'Still connecting. Thank you for your patience.')
);
echo html_writer::script('setTimeout(function() {'
    . ' var t = document.getElementById(\'local-chronifysupport-loading-text\');'
    . ' if (t) { t.textContent = ' . $slowtext . '; }'
    . '}, 8000);');
if (ob_get_level() > 0) {
    @ob_flush();
}
@flush();

// Performance: the Tier-1/Tier-2 exchange is the slowest part of opening
// the widget. When the same user re-opens the widget shortly after (page
// refresh, navigating to another page while the panel stays open, ...)
// reuse the session obtained a moment ago from this user's own Moodle
// session instead of exchanging again. Strictly conservative: only for the
// same user + same course + same activity context, only while the token
// is young (< 8 min old) and still has > 10 min of its ~20 min life left,
// and any problem simply falls back to a normal fresh exchange. The
// silent token refresh armed below keeps working exactly as before.
$cachekey = (int) $USER->id . '|' . (int) $courseid . '|' . md5((string) $activitycontext);
$session = null;
$fromcache = false;
$cached = $SESSION->local_chronifysupport_session_cache[$cachekey] ?? null;
if (
    is_array($cached) &&
    !empty($cached['session']['access_token']) &&
    !empty($cached['session']['expires_at']) &&
    !empty($cached['issued']) &&
    (time() - (int) $cached['issued']) < 480 &&
    (strtotime((string) $cached['session']['expires_at']) - time()) > 600
) {
    $session = $cached['session'];
    $fromcache = true;
}

// Optional "fast widget load" mode (off by default; see settings.php). On a
// cache miss the widget iframe is added to the page RIGHT NOW, before the slow
// token exchange, with only non-secret context in its URL fragment
// (await_token=1). The widget app (which is a large single-page app that takes
// a moment to download and start) loads in parallel with the exchange, and the
// session token is handed to it with a postMessage as soon as it is ready.
// The token is only ever posted to the helpdesk origin (strict targetOrigin)
// and the widget only accepts it from its parent frame. Default flow is
// unchanged when the setting is off, on a cache hit, or if the helpdesk base
// URL is not configured.
$earlyiframe = $session === null
    && !empty(get_config('local_chronifysupport', 'fast_widget_load'))
    && !empty($earlyorigin);
if ($earlyiframe) {
    $contextparts = ['await_token' => 1];
    if ($course) {
        $contextparts['course_id'] = $course->id;
        $contextparts['course_name'] = format_string($course->fullname);
    }
    if ($activitycontext) {
        $contextparts['activity_context'] = $activitycontext;
    }
    if ($pagetitleparam !== '') {
        $contextparts['page_title'] = $pagetitleparam;
    }
    if ($pageurlparam !== '') {
        $contextparts['page_url'] = $pageurlparam;
    }
    if ($visitorkeycookie !== '') {
        $contextparts['visitor_key'] = $visitorkeycookie;
    }
    if ($clientid !== '') {
        $contextparts['client_id'] = $clientid;
    }
    echo html_writer::tag('iframe', '', [
        'id' => 'local-chronifysupport-widget-frame',
        'src' => $earlybase . '/widget#' . http_build_query($contextparts, '', '&', PHP_QUERY_RFC3986),
        'referrerpolicy' => 'no-referrer',
        'sandbox' => 'allow-scripts allow-same-origin allow-forms allow-popups',
        'style' => 'width:100%;height:100%;display:block;border:0;',
        'title' => get_string('get_help_button', 'local_chronifysupport'),
    ]);
    // Handshake: the widget announces helpdesk:widget_ready when it is
    // listening; the token is delivered (window.__chronifyDeliver) once BOTH the
    // widget is ready and the exchange has finished, in either order.
    echo html_writer::script('(function() {'
        . 'var frame = document.getElementById(\'local-chronifysupport-widget-frame\');'
        . 'var origin = ' . \local_chronifysupport\hook_callbacks::js_value($earlyorigin) . ';'
        . 'var ready = false, init = null;'
        . 'function send() {'
        . '  if (ready && init && frame && frame.contentWindow) {'
        . '    try { frame.contentWindow.postMessage(init, origin); } catch (e) { /* widget times out on its own */ }'
        . '  }'
        . '}'
        . 'window.__chronifyDeliver = function(message) { init = message; send(); };'
        . 'window.addEventListener(\'message\', function(event) {'
        . '  if (!frame || event.source !== frame.contentWindow || event.origin !== origin) { return; }'
        . '  if (event.data && event.data.type === \'helpdesk:widget_ready\') { ready = true; send(); }'
        . '});'
        . '})();');
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

$exchangestart = microtime(true);

try {
    if ($session === null) {
        $session = \local_chronifysupport\token_client::exchange_for_session($USER, $course, $activitycontext);
        // Keep at most one cached session per user session (drop older keys).
        $SESSION->local_chronifysupport_session_cache = [$cachekey => ['issued' => time(), 'session' => $session]];
    }
    $exchangems = (int) round((microtime(true) - $exchangestart) * 1000);

    $baseurl = rtrim(get_config('local_chronifysupport', 'helpdesk_base_url'), '/');
    $baseurlparts = parse_url($baseurl);
    $helpdeskorigin = $baseurlparts['scheme'] . '://' . $baseurlparts['host']
        . (isset($baseurlparts['port']) ? ':' . $baseurlparts['port'] : '');

    // Contract §10.1: token travels in the URL *fragment*, never the query
    // string — fragments are never sent in HTTP requests (no Referer leak,
    // no server logs), unlike a ?query=string would be.
    $fragmentparts = [
        'access_token' => $session['access_token'],
        'expires_at' => $session['expires_at'],
        'user_id' => $session['user_id'],
    ];
    if ($course) {
        $fragmentparts['course_id'] = $course->id;
        $fragmentparts['course_name'] = format_string($course->fullname);
    }
    if ($activitycontext) {
        $fragmentparts['activity_context'] = $activitycontext;
    }
    if ($pagetitleparam !== '') {
        $fragmentparts['page_title'] = $pagetitleparam;
    }
    if ($pageurlparam !== '') {
        $fragmentparts['page_url'] = $pageurlparam;
    }
    if ($visitorkeycookie !== '') {
        $fragmentparts['visitor_key'] = $visitorkeycookie;
    }
    if ($clientid !== '') {
        $fragmentparts['client_id'] = $clientid;
    }
    $widgeturl = $baseurl . '/widget#' . http_build_query($fragmentparts, '', '&', PHP_QUERY_RFC3986);

    if ($earlyiframe) {
        // The iframe is already on the page (see above): just hand it the token.
        echo html_writer::script('if (window.__chronifyDeliver) { window.__chronifyDeliver('
            . \local_chronifysupport\hook_callbacks::js_value([
            'type' => 'helpdesk:init',
            'access_token' => $session['access_token'],
            'expires_at' => $session['expires_at'],
            'user_id' => $session['user_id'],
        ]) . '); }');
    } else {
        echo html_writer::tag('iframe', '', [
            'id' => 'local-chronifysupport-widget-frame',
            'src' => $widgeturl,
            'referrerpolicy' => 'no-referrer',
            'sandbox' => 'allow-scripts allow-same-origin allow-forms allow-popups',
            'style' => 'width:100%;height:100%;display:block;border:0;',
            'title' => get_string('get_help_button', 'local_chronifysupport'),
        ]);
    }

    // Contract §10.2: silently refresh at ~80% of the real token TTL by
    // re-running the exchange via the existing same-origin refresh_token.php,
    // then pushing the new token into the iframe via postMessage — no reload,
    // no interruption to whatever the widget is doing. Re-schedules itself
    // after each successful refresh using the *new* token's real expiry,
    // so this keeps working correctly even if the TTL ever changes again.
    $refreshurl = new moodle_url('/local/chronifysupport/refresh_token.php', [
        'courseid' => $courseid,
        'sesskey' => sesskey(),
    ]);

    // Live page-tracking (optional, off by default — see settings.php):
    // this page is same-origin with the rest of the Moodle site, so it's
    // the only place that can write into the Moodle site's own
    // localStorage. hook_callbacks.php's always-on tracking script (run on
    // *every* page view, not just this iframe) reads this same key on
    // subsequent page loads to know whether a support conversation is
    // already open and, if so, which one — it never creates a session
    // itself. Storing the raw access_token here is no new exposure: this
    // same token already sits in this iframe's own URL fragment and inside
    // the nested widget's origin; localStorage on the Moodle origin is a
    // second copy of the same short-lived credential, not a new secret.
    $livetrackingenabled = !empty(get_config('local_chronifysupport', 'enable_live_tracking'));
    $sessionstoragekey = 'local_chronifysupport_session';

    $js = '(function() {'
        // Diagnostics only (no behaviour): shows in the browser console how long
        // the server-side token exchange took, to help pinpoint slow loads.
        . 'try { console.info(\'[helpdesk] token exchange (server): ' . $exchangems . ' ms'
        . ($fromcache ? ' (reused recent session)' : '')
        . (!$fromcache && \local_chronifysupport\token_client::$lasttimings
            ? ' (tier1 ' . \local_chronifysupport\token_client::$lasttimings['tier1']
                . ' ms, tier2 ' . \local_chronifysupport\token_client::$lasttimings['tier2'] . ' ms)'
            : '')
        . '\'); } catch (e) {}'
        . 'var frame = document.getElementById(\'local-chronifysupport-widget-frame\');'
        . 'var helpdeskOrigin = ' . \local_chronifysupport\hook_callbacks::js_value($helpdeskorigin) . ';'
        . 'var refreshUrl = ' . \local_chronifysupport\hook_callbacks::js_value($refreshurl->out(false)) . ';'
        . 'var liveTrackingEnabled = ' . \local_chronifysupport\hook_callbacks::js_value($livetrackingenabled) . ';'
        . 'var storageKey = ' . \local_chronifysupport\hook_callbacks::js_value($sessionstoragekey) . ';'
        . 'var courseId = ' . \local_chronifysupport\hook_callbacks::js_value($courseid) . ';'
        . 'function saveSession(patch) {'
        . '  if (!liveTrackingEnabled) { return; }'
        . '  var current = {};'
        . '  try { current = JSON.parse(window.localStorage.getItem(storageKey)) || {}; } catch (e) { current = {}; }'
        . '  for (var k in patch) { current[k] = patch[k]; }'
        . '  try { window.localStorage.setItem(storageKey, JSON.stringify(current)); '
        . '} catch (e) { /* storage unavailable/full: tracking just stays off */ }'
        . '}'
        . 'function scheduleRefresh(expiresAtIso) {'
        . '  var expiresAt = new Date(expiresAtIso).getTime();'
        . '  var delay = Math.max(60000, (expiresAt - Date.now()) * 0.8);'
        . '  setTimeout(doRefresh, delay);'
        . '}'
        . 'function doRefresh() {'
        . '  fetch(refreshUrl, {credentials: \'same-origin\'})'
        . '    .then(function(r) { return r.json(); })'
        . '    .then(function(data) {'
        . '      if (data && data.access_token) {'
        . '        frame.contentWindow.postMessage({'
        . '          type: \'helpdesk:token_refresh\','
        . '          access_token: data.access_token,'
        . '          expires_at: data.expires_at'
        . '        }, helpdeskOrigin);'
        . '        saveSession({access_token: data.access_token, expires_at: data.expires_at});'
        . '        scheduleRefresh(data.expires_at);'
        . '      }'
        . '    })'
        . '    .catch(function() { /* best-effort: widget just keeps its current token until natural expiry */ });'
        . '}'
        . 'scheduleRefresh(' . \local_chronifysupport\hook_callbacks::js_value($session['expires_at']) . ');'
        . 'saveSession({'
        . '  access_token: ' . \local_chronifysupport\hook_callbacks::js_value($session['access_token']) . ','
        . '  expires_at: ' . \local_chronifysupport\hook_callbacks::js_value($session['expires_at']) . ','
        . '  user_id: ' . \local_chronifysupport\hook_callbacks::js_value($session['user_id']) . ','
        . '  course_id: courseId || null'
        . '});'
        // Relay the widget's own minimize/restore signal one level up to the
        // course page. This page is just a same-origin passthrough frame
        // (see class comment above) — the actual floating panel it needs to
        // resize lives on the parent course page, not here, so we simply
        // forward the message untouched via window.parent. event.source is
        // checked against the widget iframe itself so an unrelated frame on
        // the page can never spoof a resize.
        //
        // A new `helpdesk:conversation_id` message (sent once, right after
        // the widget resolves/creates the ticket's conversation) is instead
        // handled here directly rather than relayed further up — it exists
        // purely so this page can cache the id for the live page-tracking
        // feature; the parent course page has no use for it.
        . 'window.addEventListener(\'message\', function(event) {'
        . '  if (event.origin !== helpdeskOrigin || event.source !== frame.contentWindow) { return; }'
        . '  if (!event.data) { return; }'
        . '  if ((event.data.type === \'helpdesk:conversation_id\' || event.data.type '
        . '=== \'helpdesk:conversation_started\') && event.data.conversation_id) {'
        . '    saveSession({conversation_id: event.data.conversation_id});'
        . '    return;'
        . '  }'
        . '  if (event.data.type !== \'helpdesk:minimize\' && event.data.type !== \'helpdesk:restore\''
        . ' && event.data.type !== \'helpdesk:chat_state\') { return; }'
        . '  if (window.parent && window.parent !== window) {'
        . '    window.parent.postMessage(event.data, window.location.origin);'
        . '  }'
        . '});'
        // Downward relay: the parent course page (hook_callbacks.php) mirrors
        // the same 'helpdesk:restore' message back down here when it reopens
        // the panel from its own button, so a widget that paused/reset its
        // view when it last asked to be minimized gets a chance to resync
        // instead of staying blank. This page is just a same-origin
        // passthrough (see class comment above) — without this listener that
        // message had nowhere to go and was silently dropped, since the
        // listener above only accepts messages *from* the widget iframe, not
        // from window.parent. event.source is checked against window.parent
        // itself so only our real parent frame can trigger this, never some
        // unrelated frame spoofing the message.
        . 'window.addEventListener(\'message\', function(event) {'
        . '  if (event.origin !== window.location.origin || event.source !== window.parent) { return; }'
        . '  if (!event.data || event.data.type !== \'helpdesk:restore\') { return; }'
        . '  if (frame && frame.contentWindow) {'
        . '    try { frame.contentWindow.postMessage(event.data, helpdeskOrigin); }'
        . '    catch (e) { /* best-effort: widget just stays however it last left itself */ }'
        . '  }'
        . '});'
        . '})();';

    echo html_writer::script($js);
} catch (\moodle_exception $e) {
    if (!empty($earlyiframe)) {
        // The exchange failed after the iframe was already added: remove the
        // (token-less) widget and the loading indicator so the error is visible.
        echo html_writer::script('(function() {'
            . 'var f = document.getElementById(\'local-chronifysupport-widget-frame\');'
            . 'if (f && f.parentNode) { f.parentNode.removeChild(f); }'
            . 'var l = document.getElementById(\'local-chronifysupport-loading\');'
            . 'if (l && l.parentNode) { l.parentNode.removeChild(l); }'
            . '})();');
    }
    echo $OUTPUT->notification($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->footer();
