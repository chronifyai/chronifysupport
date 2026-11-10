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
 * Hook callbacks that add the Get Help widget to Moodle pages.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Moodle 4.4+ Hooks API callbacks.
 *
 * Injects a floating "Get Help" button into every page footer for logged-in
 * users (when enabled in settings). Clicking it does NOT navigate the tab
 * away from the course page — it lazily creates a small floating panel
 * (a small corner chat widget) containing an iframe pointing at
 * get_help.php, which performs the actual Tier-1/Tier-2 exchange server-side
 * and then embeds the real widget inside itself (see get_help.php and
 * the Tier-1/Tier-2 token contract).
 *
 * On the login page (/login/index.php), for visitors who are not logged in,
 * the same button instead opens a small pre-chat form (Name/Email/Message).
 * That case is deliberately bootstrap-free end-to-end (see the header
 * comment in get_help_anonymous.php for why) — the PHP here only prints the
 * button/form markup and JS; the actual exchange happens client-side via
 * fetch() against get_help_anonymous.php / refresh_token_anonymous.php.
 */
class hook_callbacks {
    /**
     * Maximum age, in seconds, of the pending-anonymous-identity cookie for it
     * to still be merged into a Moodle account at login. Keeps a different
     * person who logs in later on a shared computer from inheriting the
     * previous visitor's anonymous conversation.
     */
    const PENDING_ANON_MAX_AGE = 1800;

    /**
     * Some pages (observed on /login/index.php, which renders its form
     * region and the page footer as separate output passes) fire
     * before_footer_html_generation more than once per HTTP request. Since
     * $hook->add_html() appends raw HTML wherever that particular pass
     * happens to land, a second firing lands the button markup inline
     * inside whatever content region triggered it (e.g. inside the login
     * card, after "Sign Up") instead of at the real page footer — visually
     * showing up as an second, unstyled "Get Help" button. This flag makes
     * injection idempotent per request so only the first (correct) firing
     * actually adds the button.
     *
     * @var bool
     */
    protected static $buttoninjected = false;

    /**
     * Same idempotency concern as $buttoninjected above, but tracked
     * separately: the live page-tracking script (see
     * build_tracking_js()) is injected independently of the button and
     * must also only ever run once per real page view, even though it has
     * nothing to do with whichever pass first triggers the hook.
     *
     * @var bool
     */
    protected static $trackinginjected = false;

    /**
     * Same idempotency concern as $buttoninjected/$trackinginjected above,
     * tracked separately again: the site-wide visitor beacon (see
     * maybe_inject_visitor_beacon()) is injected unconditionally, on every
     * page for every visitor, independent of both the button and the
     * existing per-ticket live-tracking script above.
     *
     * @var bool
     */
    protected static $visitorbeaconinjected = false;

    /**
     * Adds the Get Help button and its scripts to the page footer.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $USER, $COURSE, $PAGE;

        // The plugin used to be called local_helpdesk. If that old copy is
        // still installed alongside this one, it already injects the widget;
        // stay silent so visitors never get two chat buttons. Remove the old
        // plugin to switch over.
        if (\local_chronifysupport\legacy_migration::old_plugin_present()) {
            return;
        }

        // Independent of the button below (and of its own per-request
        // idempotency flag): if a support conversation is already open in
        // this browser, let the agent see live page navigation. This is
        // deliberately checked before the early-return just below, since
        // that return only guards the button injection.
        self::maybe_inject_tracking($hook);

        // Also independent of the button/idempotency flag below, and of the
        // login-page branch further down: if this browser previously
        // submitted the anonymous pre-chat form (get_help_anonymous.php)
        // and the visitor has now logged into Moodle for real, silently
        // merge the two identities. See maybe_merge_anonymous_identity()
        // for why this is safe to call unconditionally on every page view.
        self::maybe_merge_anonymous_identity();

        // Site-wide visitor list (optional, off by default). Unlike every
        // other injection point in this method, this one deliberately has
        // NO isloggedin()/isguestuser()/login-page gate at all -- the whole
        // point of the feature (see 119-121, "Visitors" page) is to make
        // genuinely anonymous, not-yet-identified browsing sessions visible
        // to agents too, which is a case none of the checks above are able
        // to see in the first place.
        self::maybe_inject_visitor_beacon($hook);

        if (self::$buttoninjected) {
            return;
        }

        // Moodle's standard pagetype for /login/index.php. Visitors stuck
        // there (forgotten password, locked account) have no other way to
        // reach support, so this is a deliberate, narrow exception to the
        // isloggedin() check below — gated behind its own explicit admin
        // opt-in (enable_anonymous_button, default off) since it creates a
        // new unauthenticated entry point. See get_help_anonymous.php for
        // how identity/session works for these visitors.
        $isloginpage = ($PAGE->pagetype === 'login-index');

        if ($isloginpage) {
            if (empty(get_config('local_chronifysupport', 'enable_anonymous_button'))) {
                return;
            }
        } else {
            if (!isloggedin() || isguestuser()) {
                return;
            }

            if (empty(get_config('local_chronifysupport', 'enable_button'))) {
                return;
            }
        }

        // Don't show the button on the plugin's own pages (avoid recursion/clutter).
        if (strpos($PAGE->url->out_as_local_url(false), '/local/chronifysupport/') !== false) {
            return;
        }

        $label = get_string('get_help_button', 'local_chronifysupport');

        $buttonhtml = \html_writer::tag('button', s($label), [
            'id' => 'local_chronifysupport_get_help_button',
            'type' => 'button',
            'class' => 'btn btn-primary',
            // Layout properties carry !important on purpose: some themes
            // (observed on the login page of a custom theme) style bare
            // buttons / .btn-primary as full-width block buttons, which
            // stretched this floating button across the whole bottom edge
            // of the page. An inline !important declaration beats any
            // theme stylesheet rule, so the button keeps its compact,
            // bottom-right "pill" shape on every site and theme.
            // (display is intentionally NOT set here: the widget JS toggles
            // btn.style.display to show/hide the button.)
            'style' => 'position:fixed !important;right:24px !important;bottom:24px !important;'
                . 'left:auto !important;top:auto !important;'
                . 'width:auto !important;min-width:0 !important;'
                . 'max-width:calc(100vw - 48px) !important;height:auto !important;'
                . 'margin:0 !important;float:none !important;transform:none !important;'
                . 'box-sizing:border-box;white-space:nowrap;'
                . 'z-index:2147483000;'
                . 'border-radius:24px;padding:10px 20px;border:0;'
                . 'box-shadow:0 2px 8px rgba(0,0,0,0.2);',
        ]);

        if ($isloginpage) {
            // The pre-chat "Get Help" form on the login page
            // reuses the exact same client-side Supabase call pattern as
            // build_tracking_js()/build_visitor_beacon_js() above (anon key
            // + client_id, no Moodle session) to ask
            // get_widget_banner_status() whether to show a banner above the
            // form -- same fail-closed behaviour if these aren't configured:
            // no banner, not an error, since this whole feature is optional
            // decoration on top of the (already working) contact form.
            $supabaseurl = trim((string) get_config('local_chronifysupport', 'supabase_url'));
            $supabaseanonkey = trim((string) get_config('local_chronifysupport', 'supabase_anon_key'));
            $clientid = trim((string) get_config('local_chronifysupport', 'client_id'));
            // Knowledge Base search tab: calls the helpdesk app's own
            // /api/public/kb-search route (not Supabase directly, since
            // embedding the query text requires the app's server-side AI
            // credentials) -- so this needs the app base URL too, same
            // setting get_help.php already reads for the authenticated
            // widget iframe.
            $helpdeskbaseurl = trim((string) get_config('local_chronifysupport', 'helpdesk_base_url'));
            // Thread the login page's own title/URL into the
            // ticket-context payload, same as the authenticated flow already
            // does below via $pagetitle/$pageurl -- lets the ticket detail
            // page's Visitor Context panel show a real "Current page" link
            // (e.g. "Log in to the site") instead of the raw
            // activity_context string, matching how Visitors/course pages
            // already work.
            $loginpagetitle = $PAGE->title !== '' ? $PAGE->title : null;
            $loginpageurl = $PAGE->url->out(false);
            $js = self::build_anonymous_js(
                $label,
                $supabaseurl,
                $supabaseanonkey,
                $clientid,
                $loginpagetitle,
                $loginpageurl,
                $helpdeskbaseurl,
                self::string_or('widget_loading', 'Connecting you to support…'),
                self::string_or('widget_loading_slow', 'Still connecting. Thank you for your patience.')
            );
        } else {
            $courseid = !empty($COURSE->id) && $COURSE->id != SITEID ? $COURSE->id : 0;

            // Visitor-context capture (helpdesk end-user context panel):
            // this callback runs directly on the real page the agent's
            // requester is looking at, which is the only place in this
            // request where that's true — get_help.php is a same-origin
            // passthrough loaded inside an iframe, so it has no way to see
            // the parent page's own title/URL/activity itself. Capture the
            // three cheap, already-available signals here and forward them
            // as ordinary query params (same-origin request within the
            // Moodle site, nothing sensitive) so get_help.php can thread
            // them into the widget's context, exactly like course_name
            // already works below.
            $pagetitle = $PAGE->title !== '' ? $PAGE->title : null;
            $pageurl = $PAGE->url->out(false);
            // The $PAGE->cm value is only set when the current page belongs to a
            // course module (activity) — e.g. an assignment or forum page,
            // not a plain course page. Null on the course's own front page.
            $activityname = !empty($PAGE->cm) ? $PAGE->cm->name : null;

            $geturlparams = ['courseid' => $courseid, 'sesskey' => sesskey()];
            if ($pagetitle !== null) {
                $geturlparams['page_title'] = $pagetitle;
            }
            if ($pageurl !== '') {
                $geturlparams['page_url'] = $pageurl;
            }
            if ($activityname !== null) {
                $geturlparams['activity_name'] = $activityname;
            }

            $geturl = new \moodle_url('/local/chronifysupport/get_help.php', $geturlparams);
            $js = self::build_authenticated_js(
                $label,
                $geturl,
                self::string_or('widget_loading', 'Connecting you to support…'),
                self::string_or('widget_loading_slow', 'Still connecting. Thank you for your patience.')
            );
        }

        // Button text while a chat is in progress ("Continue chat" + green
        // dot). State is reported by the widget (helpdesk:chat_state) and
        // remembered per user in localStorage so it also shows on the next
        // page; best-effort and purely cosmetic -- the button works the
        // same either way.
        $statekey = 'local_chronifysupport_chat_active_' . ($isloginpage ? 'anon' : (int) $USER->id);
        $helpdeskorigin = '';
        $helpdeskbase = trim((string) get_config('local_chronifysupport', 'helpdesk_base_url'));
        if ($helpdeskbase !== '') {
            $parts = parse_url($helpdeskbase);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $helpdeskorigin = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        // Agent availability for the offline wording ("Continue conversation"):
        // same anon-key + client_id RPC the pre-chat form uses; empty when the
        // Supabase settings are missing (button then keeps the online wording).
        $presencesupabaseurl = trim((string) get_config('local_chronifysupport', 'supabase_url'));
        $presenceanonkey = trim((string) get_config('local_chronifysupport', 'supabase_anon_key'));
        $presenceclientid = trim((string) get_config('local_chronifysupport', 'client_id'));
        $presenceendpoint = ($presencesupabaseurl !== '' && $presenceanonkey !== '' && $presenceclientid !== '')
            ? rtrim($presencesupabaseurl, '/') . '/rest/v1/rpc/get_widget_agent_presence'
            : '';
        $statejs = self::build_chat_state_js(
            self::string_or('get_help_button_active', 'Continue chat'),
            $statekey,
            $helpdeskorigin,
            self::string_or('get_help_button_active_offline', 'Continue conversation'),
            $presenceendpoint,
            $presenceanonkey,
            $presenceclientid
        );

        // Not-logged-in visitors only: forget the previous conversation when the
        // browser was closed and reopened. A session cookie (no expiry, shared by
        // all tabs, dropped when the browser closes) marks "same browser session";
        // if it is missing, the stored anonymous session and the "Continue chat"
        // state are cleared before anything reads them. Skipped when cookies
        // cannot be set, so nothing is ever wiped on every page load.
        $resetjs = '';
        if ($isloginpage) {
            // Two triggers, either one resets: (1) the session cookie is missing
            // (browser was closed), (2) the login page was last seen more than 20 s
            // ago (covers browsers that restore session cookies and tabs). The
            // last-seen time is refreshed every 10 s while the page is open and
            // again on every page unload (pagehide), so normal navigation between
            // pages never resets; only a closed browser leaves it stale.
            $resetjs = '(function() {'
                . 'var name = \'local_chronifysupport_anon_alive\';'
                . 'var seenKey = \'local_chronifysupport_anon_seen\';'
                . 'function has() { return document.cookie.indexOf(name + \'=1\') !== -1; }'
                . 'function touch() { try { window.localStorage.setItem(seenKey, '
                . 'String(Date.now())); } catch (e) { /* best-effort */ } }'
                . 'try {'
                . '  var seen = 0;'
                . '  try { seen = parseInt(window.localStorage.getItem(seenKey) || \'0\', 10) || 0; } catch (e1) { seen = 0; }'
                . '  var stale = seen > 0 && (Date.now() - seen) > 20000;'
                . '  var fresh = false;'
                . '  if (!has()) {'
                . '    document.cookie = name + \'=1; path=/; SameSite=Lax\' + '
                . '(window.location.protocol === \'https:\' ? \'; Secure\' : \'\');'
                . '    fresh = has();'
                . '  }'
                . '  if (fresh || stale) {'
                . '    window.localStorage.removeItem(\'local_chronifysupport_session\');'
                . '    window.localStorage.removeItem(' . self::js_value($statekey) . ');'
                . '    try { window.sessionStorage.removeItem(\'local_chronifysupport_panel_open\'); '
                . '} catch (e2) { /* best-effort */ }'
                . '  }'
                . '  touch();'
                . '  setInterval(touch, 10000);'
                . '  window.addEventListener(\'pagehide\', touch);'
                . '} catch (e) { /* best-effort */ }'
                . '})();';
        }

        $hook->add_html($buttonhtml
            . ($resetjs !== '' ? \html_writer::script($resetjs) : '')
            . \html_writer::script($js) . \html_writer::script($statejs));
        self::$buttoninjected = true;
    }

    /**
     * Live page-tracking (optional, off by default — see settings.php /
     * enable_live_tracking): if this browser already has an open support
     * conversation (cached by get_help.php into localStorage — see that
     * file's saveSession()), ping Supabase directly with the page the
     * visitor just navigated to, so agents can see a live "last 5 pages"
     * list on the ticket (the ticket's Visitor Context panel).
     *
     * This intentionally never creates a session or a conversation itself
     * — it only ever sends a page_view event when one already exists,
     * exactly mirroring how get_help.php's silent token-refresh never
     * creates a new ticket either. A visitor who never clicks "Get Help"
     * (or whose ticket has since been closed and the ticket's own
     * conversation_id cleared from storage — see note in
     * build_tracking_js()) is invisible to this: nothing is ever sent.
     *
     * Restricted to the same authenticated, non-guest, non-login-page,
     * non-plugin-page surface as the button itself. Anonymous (login-page)
     * visitors are out of scope for this pass — there is no course page to
     * navigate between there anyway.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook being dispatched.
     */
    protected static function maybe_inject_tracking(\core\hook\output\before_footer_html_generation $hook): void {
        global $COURSE, $PAGE, $USER, $CFG;

        if (self::$trackinginjected) {
            return;
        }

        if ($PAGE->pagetype === 'login-index') {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        if (empty(get_config('local_chronifysupport', 'enable_live_tracking'))) {
            return;
        }

        $supabaseurl = trim((string) get_config('local_chronifysupport', 'supabase_url'));
        $supabaseanonkey = trim((string) get_config('local_chronifysupport', 'supabase_anon_key'));
        // The client_id is required here too, same as the
        // visitor beacon — record_page_view() (the new RPC this now calls
        // instead of a raw authenticated REST insert) validates it the exact
        // same way record_visitor_heartbeat() validates the beacon's.
        $clientid = trim((string) get_config('local_chronifysupport', 'client_id'));
        if ($supabaseurl === '' || $supabaseanonkey === '' || $clientid === '') {
            // Feature turned on but not (fully) configured yet — fail closed
            // rather than sending pings with no destination.
            return;
        }

        // Don't track visits to the plugin's own pages (the widget/button
        // pages themselves aren't meaningful "visitor context").
        if (strpos($PAGE->url->out_as_local_url(false), '/local/chronifysupport/') !== false) {
            return;
        }

        $courseid = !empty($COURSE->id) && $COURSE->id != SITEID ? $COURSE->id : 0;
        $pagetitle = $PAGE->title !== '' ? $PAGE->title : null;
        $pageurl = $PAGE->url->out(false);
        $activityname = !empty($PAGE->cm) ? $PAGE->cm->name : null;

        // Enrich the same live ping with course name, the
        // visitor's Moodle role(s), and the site's Moodle version — all
        // read from data Moodle already has loaded for this page view, at
        // zero extra DB cost. These ride the existing record_page_view()
        // call (stored server-side into user_activity_events.metadata) so
        // no widget/Track-A change is needed to surface them.
        $coursename = ($courseid && !empty($COURSE->fullname)) ? format_string($COURSE->fullname) : null;

        $roletext = null;
        $roleassignments = get_user_roles($PAGE->context, $USER->id, true);
        if (!empty($roleassignments)) {
            $allrolenames = role_get_names($PAGE->context);
            $rolenames = [];
            foreach ($roleassignments as $ra) {
                if (isset($allrolenames[$ra->roleid])) {
                    $rolenames[] = $allrolenames[$ra->roleid]->localname;
                }
            }
            $rolenames = array_unique($rolenames);
            if (!empty($rolenames)) {
                $roletext = implode(', ', $rolenames);
            }
        }

        $moodleversion = !empty($CFG->release) ? $CFG->release : null;

        $js = self::build_tracking_js(
            $supabaseurl,
            $supabaseanonkey,
            $clientid,
            $courseid,
            $pagetitle,
            $pageurl,
            $activityname,
            $coursename,
            $roletext,
            $moodleversion
        );
        $hook->add_html(\html_writer::script($js));
        self::$trackinginjected = true;
    }

    /**
     * Site-wide visitor list (optional, off by default — see settings.php /
     * enable_visitor_tracking): injects a background script into every page
     * for every visitor, logged in or not, which (a) generates/reuses a
     * per-browser visitor_key in localStorage, (b) periodically upserts a
     * presence row via the record_visitor_heartbeat() RPC so agents can see
     * this browser in the "Visitors" page, and (c) if an agent has
     * proactively started a chat with this specific browser (surfaced back
     * to us in that same RPC's response), exchanges the visitor_key for a
     * real session via accept_invite.php and opens the widget — all without
     * the visitor ever having clicked anything.
     *
     * Structurally independent of the button/tracking/merge logic above:
     * this is the only injection point in this class with no
     * isloggedin()/isguestuser()/login-page condition at all, because a
     * visitor with no Moodle account and no helpdesk conversation yet is
     * exactly the case this feature exists to make visible. It does,
     * however, still skip the plugin's own pages, same as the others, since
     * those aren't meaningful "browsing" for presence purposes.
     *
     * Fails closed (does nothing) if the feature is off or not fully
     * configured, exactly like maybe_inject_tracking() above -- this reuses
     * that same feature's supabase_url/supabase_anon_key settings rather
     * than needing its own.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook being dispatched.
     */
    protected static function maybe_inject_visitor_beacon(\core\hook\output\before_footer_html_generation $hook): void {
        global $COURSE, $PAGE;

        if (self::$visitorbeaconinjected) {
            return;
        }

        if (empty(get_config('local_chronifysupport', 'enable_visitor_tracking'))) {
            return;
        }

        $supabaseurl = trim((string) get_config('local_chronifysupport', 'supabase_url'));
        $supabaseanonkey = trim((string) get_config('local_chronifysupport', 'supabase_anon_key'));
        $clientid = trim((string) get_config('local_chronifysupport', 'client_id'));
        if ($supabaseurl === '' || $supabaseanonkey === '' || $clientid === '') {
            // Feature turned on but not (fully) configured yet -- fail
            // closed rather than sending heartbeats with no destination or
            // no way to resolve which installation they belong to.
            return;
        }

        // Don't track visits to the plugin's own pages, same rationale as
        // maybe_inject_tracking() -- the widget/button passthrough pages
        // themselves aren't meaningful visitor browsing activity.
        if (strpos($PAGE->url->out_as_local_url(false), '/local/chronifysupport/') !== false) {
            return;
        }

        $courseid = !empty($COURSE->id) && $COURSE->id != SITEID ? $COURSE->id : 0;
        $pagetitle = $PAGE->title !== '' ? $PAGE->title : null;
        $pageurl = $PAGE->url->out(false);

        $acceptinviteurl = new \moodle_url('/local/chronifysupport/accept_invite.php');

        $js = self::build_visitor_beacon_js(
            $supabaseurl,
            $supabaseanonkey,
            $clientid,
            $acceptinviteurl,
            $courseid,
            $pagetitle,
            $pageurl
        );
        $hook->add_html(\html_writer::script($js));
        self::$visitorbeaconinjected = true;
    }

    /**
     * If this browser has a pending anonymous-identity cookie set by
     * get_help_anonymous.php (see that file's comment for the full
     * rationale) and the visitor is now genuinely logged in, silently
     * merges the anonymous helpdesk identity into their real one via Track
     * A's merge endpoint, then clears the cookie so this only ever runs
     * once per anonymous session.
     *
     * Deliberately best-effort and non-blocking, exactly like
     * maybe_inject_tracking()'s page-view pings and get_help.php's own
     * silent token refresh: a failed merge attempt here must never surface
     * to the visitor or affect the page they're looking at. On failure the
     * cookie is left in place (it naturally expires after 24h regardless),
     * so the next page view simply tries again.
     *
     * The httponly cookie itself is the only thing that establishes "same
     * browser" -- there is deliberately no email-based matching here, since
     * the anonymous and real identities may use different email addresses
     * (e.g. a personal email on the pre-chat form vs. an institutional
     * Moodle account).
     */
    protected static function maybe_merge_anonymous_identity(): void {
        global $USER;

        // The pre-rename cookie (local_helpdesk_pending_anon) is still honoured so a
        // visitor who was mid-login during the upgrade is not left unmerged.
        $pendingraw = $_COOKIE['local_chronifysupport_pending_anon'] ?? ($_COOKIE['local_helpdesk_pending_anon'] ?? '');
        if (!isloggedin() || isguestuser() || empty($pendingraw)) {
            return;
        }

        $pending = json_decode($pendingraw, true);
        if (empty($pending['external_user_id'])) {
            // Malformed cookie -- clear it so we don't keep re-parsing junk
            // on every subsequent page view for this visitor.
            setcookie('local_chronifysupport_pending_anon', '', time() - 3600, '/');
            setcookie('local_helpdesk_pending_anon', '', time() - 3600, '/');
            return;
        }

        // Only merge when the cookie is recent. A cookie without an issue time
        // (older format) or older than the window is discarded, never merged.
        $age = isset($pending['ts']) ? time() - (int) $pending['ts'] : -1;
        if ($age < 0 || $age > self::PENDING_ANON_MAX_AGE) {
            setcookie('local_chronifysupport_pending_anon', '', time() - 3600, '/');
            setcookie('local_helpdesk_pending_anon', '', time() - 3600, '/');
            return;
        }

        require_once(__DIR__ . '/token_client.php');
        try {
            token_client::merge_anonymous_identity($pending['external_user_id'], (string) $USER->id);
        } catch (\Throwable $e) {
            debugging('local_chronifysupport merge_anonymous_identity: ' . $e->getMessage(), DEBUG_DEVELOPER);
            // Leave the cookie in place for a retry on the next page view.
            return;
        }

        setcookie('local_chronifysupport_pending_anon', '', time() - 3600, '/');
        setcookie('local_helpdesk_pending_anon', '', time() - 3600, '/');
    }

    /**
     * Reads the session cached by get_help.php (see that file's
     * saveSession()) and, as long as it names a conversation_id, calls the
     * record_page_view() RPC with that page's context.
     *
     * This used to require session.access_token AND
     * session.user_id AND an unexpired session.expires_at before sending
     * anything, mirroring an authenticated REST insert straight into
     * user_activity_events — but that made this silently drop every page
     * view once the cached token went stale between visits to the widget
     * (which is only refreshed while the widget panel itself is open), even
     * though the browser still knew perfectly well which conversation it
     * belonged to. record_page_view() is a SECURITY DEFINER RPC that
     * re-derives the real requester server-side from p_conversation_id (the
     * same resilience pattern record_visitor_heartbeat() already uses for
     * the Visitors page's beacon) so this can now always fire with just the
     * anon key, exactly like sendHeartbeat() does — no access_token, no
     * expiry check, no dependency on the widget having been reopened
     * recently.
     *
     * `keepalive: true` lets the request survive the page unload that
     * happens immediately after this fires on a normal link click/navigation.
     *
     * @param string $supabaseurl Supabase project URL.
     * @param string $supabaseanonkey Supabase anon key.
     * @param string $clientid Client ID of this installation.
     * @param int $courseid Current course ID (0 if none).
     * @param string|null $pagetitle Current page title.
     * @param string $pageurl Current page URL.
     * @param string|null $activityname Current activity name, if any.
     * @param string|null $coursename Current course name, if any.
     * @param string|null $role Role of the user in the current context, if known.
     * @param string|null $moodleversion Moodle release string, if known.
     * @return string The script source.
     */
    protected static function build_tracking_js(
        string $supabaseurl,
        string $supabaseanonkey,
        string $clientid,
        int $courseid,
        ?string $pagetitle,
        string $pageurl,
        ?string $activityname,
        ?string $coursename = null,
        ?string $role = null,
        ?string $moodleversion = null
    ): string {
        $endpoint = rtrim($supabaseurl, '/') . '/rest/v1/rpc/record_page_view';

        return '(function() {'
            . 'var storageKey = \'local_chronifysupport_session\';'
            . 'var endpoint = ' . self::js_value($endpoint) . ';'
            . 'var anonKey = ' . self::js_value($supabaseanonkey) . ';'
            . 'var clientId = ' . self::js_value($clientid) . ';'
            . 'var pageTitle = ' . self::js_value($pagetitle) . ';'
            . 'var pageUrl = ' . self::js_value($pageurl) . ';'
            . 'var activityName = ' . self::js_value($activityname) . ';'
            . 'var courseId = ' . self::js_value($courseid ?: null) . ';'
            . 'var courseName = ' . self::js_value($coursename) . ';'
            . 'var role = ' . self::js_value($role) . ';'
            . 'var moodleVersion = ' . self::js_value($moodleversion) . ';'
            . 'var session;'
            . 'try { session = JSON.parse(window.localStorage.getItem(storageKey)); } catch (e) { session = null; }'
            // Still only ever fires once a conversation actually exists for
            // this browser (i.e. the visitor has clicked "Get Help" at least
            // once) — deliberately unchanged from before. What's gone is the
            // access_token/user_id/expiry gate: the conversation_id alone is
            // enough for record_page_view() to safely attribute the event.
            . 'if (!session || !session.conversation_id) { return; }'
            . 'fetch(endpoint, {'
            . '  method: \'POST\','
            . '  keepalive: true,'
            . '  headers: {'
            . '    \'Content-Type\': \'application/json\','
            . '    \'apikey\': anonKey,'
            . '    \'Authorization\': \'Bearer \' + anonKey,'
            . '    \'Prefer\': \'return=minimal\''
            . '  },'
            . '  body: JSON.stringify({'
            . '    p_client_id: clientId,'
            . '    p_conversation_id: session.conversation_id,'
            . '    p_page_title: pageTitle,'
            . '    p_page_url: pageUrl,'
            . '    p_course_id: courseId,'
            . '    p_activity_name: activityName,'
            . '    p_course_name: courseName,'
            . '    p_role: role,'
            . '    p_moodle_version: moodleVersion'
            . '  })'
            . '}).catch(function() { /* best-effort: a missed page ping is not worth surfacing to the visitor */ });'
            . '})();';
    }

    /**
     * Generates the visitor-beacon script: per-browser visitor_key
     * creation, a ~25s heartbeat loop against record_visitor_heartbeat(),
     * and — the proactive-outreach half of the feature — picking up and
     * opening an agent-initiated invite.
     *
     * Deliberately its own small, self-contained widget-opening routine
     * (mirroring the *shape* of build_anonymous_js()'s renderWidget(), i.e.
     * a direct widget iframe addressed via the URL fragment with
     * access_token/expires_at/user_id) rather than literally calling into
     * build_anonymous_js() or build_authenticated_js(): each build_*_js()
     * method here already emits its own wholly separate, independently
     * scoped IIFE with no shared JS state between them (that's true of the
     * existing two methods already, e.g. build_tracking_js() vs
     * build_authenticated_js() never call into each other either), and a
     * proactively-invited visitor is specifically an *anonymous,
     * not-logged-into-Moodle* browsing session -- the same trust context as
     * the login-page pre-chat form, not the logged-in-Moodle-user Tier1/2
     * exchange that build_authenticated_js()'s get_help.php passthrough
     * performs for a completely different identity ($USER). Reusing
     * renderWidget()'s *pattern* is what actually fits here; reusing
     * build_authenticated_js() itself would authenticate as the wrong user
     * (or fail outright for a visitor who isn't logged in at all).
     *
     * Uses the same 'local_chronifysupport_session' localStorage key that both the
     * authenticated (get_help.php) and anonymous (get_help_anonymous.php)
     * flows already write to, so that once this visitor is identified, the
     * existing live page-tracking script (build_tracking_js() above) picks
     * up their session on the very next page view for free, with no
     * changes needed there.
     *
     * @param string $supabaseurl Supabase project URL.
     * @param string $supabaseanonkey Supabase anon key.
     * @param string $clientid Client ID of this installation.
     * @param \moodle_url $acceptinviteurl URL of accept_invite.php.
     * @param int $courseid Current course ID (0 if none).
     * @param string|null $pagetitle Current page title.
     * @param string $pageurl Current page URL.
     * @return string The script source.
     */
    protected static function build_visitor_beacon_js(
        string $supabaseurl,
        string $supabaseanonkey,
        string $clientid,
        \moodle_url $acceptinviteurl,
        int $courseid,
        ?string $pagetitle,
        string $pageurl
    ): string {
        $rpcendpoint = rtrim($supabaseurl, '/') . '/rest/v1/rpc/record_visitor_heartbeat';
        $endsessionendpoint = rtrim($supabaseurl, '/') . '/rest/v1/rpc/end_visitor_session';
        $heartbeatintervalms = 25000;

        return '(function() {'
            . 'var rpcEndpoint = ' . self::js_value($rpcendpoint) . ';'
            . 'var endSessionEndpoint = ' . self::js_value($endsessionendpoint) . ';'
            . 'var anonKey = ' . self::js_value($supabaseanonkey) . ';'
            . 'var clientId = ' . self::js_value($clientid) . ';'
            . 'var acceptInviteUrl = ' . self::js_value($acceptinviteurl->out(false)) . ';'
            . 'var courseId = ' . self::js_value($courseid ?: null) . ';'
            . 'var pageTitle = ' . self::js_value($pagetitle) . ';'
            . 'var pageUrl = ' . self::js_value($pageurl) . ';'
            . 'var heartbeatIntervalMs = ' . self::js_value($heartbeatintervalms) . ';'
            . 'var sessionStorageKey = \'local_chronifysupport_session\';'
            . 'var visitorKeyStorageKey = \'local_chronifysupport_visitor_key\';'
            // Pre-rename key (plugin was called local_helpdesk): read once so returning
            // visitors keep their identity, then the value is re-saved under the new key.
            . 'var legacyVisitorKeyStorageKey = \'local_helpdesk_visitor_key\';'
            . 'var openedInviteConversationId = null;'
            . 'var openingInvite = false;'
            // Some pages (observed on Moodle\'s /login/* flow) clear
            // localStorage between navigations even though the origin is
            // unchanged, which used to make every login/signup/forgot-password
            // page view register as a brand-new visitor. A first-party cookie
            // survives that, so it is used as a fallback store: read from
            // localStorage first, then the cookie, and keep both in sync
            // whenever a key is found or created.
            . 'function getCookie(name) {'
            . '  var match = document.cookie.match(new RegExp(\'(^|; )\' + name + \'=([^;]*)\'));'
            . '  return match ? decodeURIComponent(match[2]) : null;'
            . '}'
            . 'function setCookie(name, value) {'
            . '  var maxAgeSeconds = 60 * 60 * 24 * 365;'
            . '  try { document.cookie = name + \'=\' + encodeURIComponent(value) + \'; path=/; '
            . 'max-age=\' + maxAgeSeconds + \'; SameSite=Lax\'; } catch (e) { /* best-effort */ }'
            . '}'
            . 'function getVisitorKey() {'
            . '  var key;'
            . '  try { key = window.localStorage.getItem(visitorKeyStorageKey); } catch (e) { key = null; }'
            . '  if (!key) { key = getCookie(visitorKeyStorageKey); }'
            . '  if (!key) { try { key = window.localStorage.getItem(legacyVisitorKeyStorageKey); } catch (e) { key = null; } }'
            . '  if (!key) { key = getCookie(legacyVisitorKeyStorageKey); }'
            . '  if (key) {'
            . '    try { window.localStorage.setItem(visitorKeyStorageKey, key); } catch (e) { /* best-effort */ }'
            . '    setCookie(visitorKeyStorageKey, key);'
            . '    return key;'
            . '  }'
            . '  if (window.crypto && window.crypto.randomUUID) {'
            . '    key = window.crypto.randomUUID();'
            . '  } else {'
            // Fallback for browsers without crypto.randomUUID(): good enough
            // entropy for a client-generated join key, not used for anything
            // security-sensitive (record_visitor_heartbeat() never trusts
            // this value for identity, only as an opaque row key).
            . '    key = \'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx\'.replace(/[xy]/g, function(c) {'
            . '      var r = Math.random() * 16 | 0;'
            . '      var v = (c === \'x\') ? r : (r & 0x3 | 0x8);'
            . '      return v.toString(16);'
            . '    });'
            . '  }'
            . '  try { window.localStorage.setItem(visitorKeyStorageKey, key); } catch (e) { /* best-effort */ }'
            . '  setCookie(visitorKeyStorageKey, key);'
            . '  return key;'
            . '}'
            . 'function getSession() {'
            . '  var session;'
            . '  try { session = JSON.parse(window.localStorage.getItem(sessionStorageKey)); } catch (e) { session = null; }'
            . '  if (!session || !session.access_token || !session.user_id) { return null; }'
            . '  if (session.expires_at && new Date(session.expires_at).getTime() <= Date.now()) { return null; }'
            . '  return session;'
            . '}'
            . 'function saveSession(sessiondata) {'
            . '  try { window.localStorage.setItem(sessionStorageKey, '
            . 'JSON.stringify(sessiondata)); } catch (e) { /* best-effort */ }'
            . '}'
            . 'function detectContext() {'
            . '  var ua = navigator.userAgent || \'\';'
            . '  var browser = \'Other\';'
            . '  if (/Edg\\//.test(ua)) { browser = \'Edge\'; }'
            . '  else if (/OPR\\/|Opera/.test(ua)) { browser = \'Opera\'; }'
            . '  else if (/Firefox\\//.test(ua)) { browser = \'Firefox\'; }'
            . '  else if (/CriOS\\//.test(ua)) { browser = \'Chrome\'; }'
            . '  else if (/Chrome\\//.test(ua)) { browser = \'Chrome\'; }'
            . '  else if (/Safari\\//.test(ua)) { browser = \'Safari\'; }'
            . '  var os = \'Other\';'
            . '  if (/Windows/.test(ua)) { os = \'Windows\'; }'
            . '  else if (/Mac OS X/.test(ua)) { os = \'macOS\'; }'
            . '  else if (/Android/.test(ua)) { os = \'Android\'; }'
            . '  else if (/iPhone|iPad|iPod/.test(ua)) { os = \'iOS\'; }'
            . '  else if (/Linux/.test(ua)) { os = \'Linux\'; }'
            . '  var device = \'desktop\';'
            . '  if (/iPad|Tablet/.test(ua)) { device = \'tablet\'; }'
            . '  else if (/Mobi|iPhone|Android/.test(ua)) { device = \'mobile\'; }'
            . '  return {browser: browser, operatingSystem: os, deviceType: device};'
            . '}'
            . 'function openInvitedWidget(acceptdata, conversationId) {'
            . '  var baseurl = String(acceptdata.helpdesk_base_url).replace(/\\/$/, \'\');'
            . '  var baseurlobj;'
            . '  try { baseurlobj = new URL(baseurl); } catch (e) { baseurlobj = null; }'
            . '  var helpdeskOrigin = baseurlobj ? baseurlobj.origin : baseurl;'
            . '  var fragmentparams = new URLSearchParams();'
            . '  fragmentparams.set(\'access_token\', acceptdata.access_token);'
            . '  fragmentparams.set(\'expires_at\', acceptdata.expires_at);'
            . '  fragmentparams.set(\'user_id\', acceptdata.user_id);'
            . '  fragmentparams.set(\'activity_context\', \'visitor_invite\');'
            . '  fragmentparams.set(\'visitor_key\', getVisitorKey());'
            . '  fragmentparams.set(\'client_id\', clientId);'
            . '  if (pageTitle) { fragmentparams.set(\'page_title\', pageTitle); }'
            . '  if (pageUrl) { fragmentparams.set(\'page_url\', pageUrl); }'
            . '  var widgeturl = baseurl + \'/widget#\' + fragmentparams.toString();'
            . '  var panelWidth = \'420px\';'
            . '  var PUSH_MIN_VIEWPORT = 1024;'
            . '  var backdrop = null;'
            . '  var pushedHeaderEls = [];'
            . '  var panelActive = false;'
            . '  function showOverlay() {'
            . '    if (!backdrop) {'
            . '      backdrop = document.createElement(\'div\');'
            . '      backdrop.style.cssText = \'position:fixed;top:0;left:0;right:0;bottom:0;'
            . 'background:rgba(17,17,17,.25);z-index:2147482999;opacity:0;pointer-events:none;'
            . 'transition:opacity .2s ease;\';'
            . '      backdrop.addEventListener(\'click\', function() {'
            . '        panel.style.transform = \'translateX(100%)\';'
            . '        pushBody(false);'
            . '      });'
            . '      document.body.appendChild(backdrop);'
            . '    }'
            . '    backdrop.style.pointerEvents = \'auto\';'
            . '    requestAnimationFrame(function() { backdrop.style.opacity = \'1\'; });'
            . '  }'
            . '  function hideOverlay() {'
            . '    if (!backdrop) { return; }'
            . '    backdrop.style.opacity = \'0\';'
            . '    backdrop.style.pointerEvents = \'none\';'
            . '  }'
            // Same generic fixed-header detection as the other two flows
            // (see build_authenticated_js()) -- margin-right alone doesn't
            // narrow the site's position:fixed header, so this finds it
            // without any theme-specific selectors and narrows it too.
            . '  function pushFixedHeaders(active) {'
            . '    if (active) {'
            . '      var vw = window.innerWidth;'
            . '      var atTop = window.scrollY <= 50;'
            . '      var candidates = document.querySelectorAll(\'body > *, body > * > *, body > * > * > *\');'
            . '      for (var i = 0; i < candidates.length; i++) {'
            . '        var el = candidates[i];'
            . '        if (el === panel || el === backdrop) { continue; }'
            . '        if (panel && panel.contains(el)) { continue; }'
            . '        if (backdrop && backdrop.contains(el)) { continue; }'
            . '        var cs = window.getComputedStyle(el);'
            . '        var isFixed = cs.position === \'fixed\' || cs.position === \'sticky\';'
            . '        var isAbsolute = cs.position === \'absolute\';'
            . '        if (!isFixed && !atTop) { continue; }'
            . '        var rect = el.getBoundingClientRect();'
            . '        if (rect.top > 120) { continue; }'
            . '        var isWide = rect.width >= vw * 0.85;'
            . '        var canUseRightInset = (isFixed || isAbsolute) && cs.right !== \'auto\';'
            . '        var reachesRightEdge = rect.right >= vw - 15;'
            . '        if (!isWide && !(canUseRightInset && reachesRightEdge)) { continue; }'
            . '        if (!isWide && rect.height < 150) { continue; }'
            . '        if (!isWide && canUseRightInset) {'
            . '          pushedHeaderEls.push({ el: el, mode: \'right\', right: el.style.right, transition: el.style.transition });'
            . '          el.style.transition = \'right .2s ease\';'
            . '          el.style.right = \'calc(\' + cs.right + \' + \' + panelWidth + \')\';'
            . '        } else {'
            . '          pushedHeaderEls.push({ el: el, mode: \'width\', '
            . 'maxWidth: el.style.maxWidth, transition: el.style.transition });'
            . '          el.style.transition = \'max-width .2s ease\';'
            . '          el.style.maxWidth = \'calc(100vw - \' + panelWidth + \')\';'
            . '        }'
            . '      }'
            . '    } else {'
            . '      for (var j = 0; j < pushedHeaderEls.length; j++) {'
            . '        var item = pushedHeaderEls[j];'
            . '        if (item.mode === \'right\') {'
            . '          item.el.style.right = item.right;'
            . '        } else {'
            . '          item.el.style.maxWidth = item.maxWidth;'
            . '        }'
            . '        item.el.style.transition = item.transition;'
            . '      }'
            . '      pushedHeaderEls = [];'
            . '    }'
            . '  }'
            . '  function pushBody(active) {'
            . '    var noPushLayouts = [\'pagelayout-login\', \'pagelayout-maintenance\'];'
            . '    var canPush = window.innerWidth >= PUSH_MIN_VIEWPORT;'
            . '    if (canPush) {'
            . '      for (var i = 0; i < noPushLayouts.length; i++) {'
            . '        if (document.body.classList.contains(noPushLayouts[i])) { canPush = false; break; }'
            . '      }'
            . '    }'
            . '    if (!canPush) {'
            . '      pushFixedHeaders(false);'
            . '      document.body.style.marginRight = \'\';'
            . '      if (active) { showOverlay(); } else { hideOverlay(); }'
            . '      return;'
            . '    }'
            . '    hideOverlay();'
            . '    document.body.style.transition = \'margin-right .2s ease\';'
            . '    document.body.style.marginRight = active ? panelWidth : \'\';'
            . '    pushFixedHeaders(active);'
            . '  }'
            . '  function handleResize() {'
            . '    if (panelActive) { pushBody(true); }'
            . '  }'
            . '  var resizeTimer = null;'
            . '  window.addEventListener(\'resize\', function() {'
            . '    clearTimeout(resizeTimer);'
            . '    resizeTimer = setTimeout(handleResize, 150);'
            . '  });'
            . '  var panel = document.createElement(\'div\');'
            . '  panel.id = \'local-chronifysupport-visitor-invite-panel\';'
            . '  panel.style.cssText = \'position:fixed;top:0;right:0;height:100vh;height:100dvh;'
            . 'width:\' + panelWidth + \';max-width:100vw;z-index:2147483000;overflow:hidden;'
            . 'background:#fff;border-left:1px solid #e5e7eb;'
            . 'box-shadow:-2px 0 16px rgba(0,0,0,0.08);'
            . 'transition:transform .2s ease;transform:translateX(0);\';'
            . '  var iframe = document.createElement(\'iframe\');'
            . '  iframe.src = widgeturl;'
            . '  iframe.referrerPolicy = \'no-referrer\';'
            . '  iframe.setAttribute(\'sandbox\', \'allow-scripts allow-same-origin allow-forms allow-popups\');'
            . '  iframe.style.cssText = \'width:100%;height:100%;border:0;display:block;\';'
            . '  panel.appendChild(iframe);'
            . '  document.body.appendChild(panel);'
            . '  pushBody(true);'
            . '  panelActive = true;'
            . '  window.addEventListener(\'message\', function(event) {'
            . '    if (event.origin !== helpdeskOrigin || event.source !== iframe.contentWindow) { return; }'
            . '    if (!event.data) { return; }'
            . '    if (event.data.type === \'helpdesk:minimize\') { panel.style.transform '
            . '= \'translateX(100%)\'; pushBody(false); panelActive = false; }'
            . '    else if (event.data.type === \'helpdesk:restore\') { panel.style.transform '
            . '= \'translateX(0)\'; pushBody(true); panelActive = true; }'
            . '  });'
            . '  saveSession({'
            . '    access_token: acceptdata.access_token,'
            . '    expires_at: acceptdata.expires_at,'
            . '    user_id: acceptdata.user_id,'
            . '    conversation_id: conversationId'
            . '  });'
            . '  openedInviteConversationId = conversationId;'
            . '}'
            . 'function acceptInvite(conversationId) {'
            . '  if (openingInvite || document.getElementById(\'local-chronifysupport-visitor-invite-panel\')) { return; }'
            . '  openingInvite = true;'
            . '  fetch(acceptInviteUrl, {'
            . '    method: \'POST\','
            . '    headers: {\'Content-Type\': \'application/json\'},'
            . '    body: JSON.stringify({visitor_key: getVisitorKey()})'
            . '  })'
            . '    .then(function(r) { return r.json().then(function(data) { return {ok: r.ok, data: data}; }); })'
            . '    .then(function(result) {'
            . '      if (!result.ok || !result.data || !result.data.access_token) { return; }'
            . '      openInvitedWidget(result.data, conversationId);'
            . '    })'
            . '    .catch(function() { /* best-effort: try again on the next heartbeat that still reports this invite */ })'
            . '    .then(function() { openingInvite = false; });'
            . '}'
            . 'function sendHeartbeat() {'
            . '  var session = getSession();'
            . '  var ctx = detectContext();'
            . '  var authToken = (session && session.access_token) ? session.access_token : anonKey;'
            . '  var conversationId = openedInviteConversationId || (session ? session.conversation_id : null) || null;'
            . '  var referrer = document.referrer || \'\';'
            . '  fetch(rpcEndpoint, {'
            . '    method: \'POST\','
            . '    keepalive: true,'
            . '    headers: {'
            . '      \'Content-Type\': \'application/json\','
            . '      \'apikey\': anonKey,'
            . '      \'Authorization\': \'Bearer \' + authToken'
            . '    },'
            . '    body: JSON.stringify({'
            . '      p_client_id: clientId,'
            . '      p_visitor_key: getVisitorKey(),'
            . '      p_conversation_id: conversationId,'
            . '      p_browser: ctx.browser,'
            . '      p_operating_system: ctx.operatingSystem,'
            . '      p_device_type: ctx.deviceType,'
            . '      p_page_title: pageTitle,'
            . '      p_page_url: pageUrl,'
            . '      p_course_id: courseId ? String(courseId) : null,'
            . '      p_referrer: referrer'
            . '    })'
            . '  })'
            . '    .then(function(r) { return r.ok ? r.json() : null; })'
            . '    .then(function(data) {'
            . '      if (!data || !data.invite_conversation_id) { return; }'
            . '      if (data.invite_conversation_id === openedInviteConversationId) { return; }'
            . '      acceptInvite(data.invite_conversation_id);'
            . '    })'
            . '    .catch(function() { /* best-effort: a missed heartbeat just '
            . 'means this browser looks offline until the next one */ });'
            . '}'
            // This beacon (not the "Get Help" widget) is
            // what actually runs on every Moodle page, whether or not the
            // visitor has ever opened the chat -- so it is the piece that
            // must send the instant-offline signal for plain browsing
            // sessions. The widget iframe (build_anonymous_js()/
            // build_authenticated_js()) only exists in the DOM at all once
            // "Get Help" has been clicked, which is why closing the tab
            // before that point previously fell all the way back to the
            // 90-second heartbeat timeout while closing it after starting a
            // chat looked instant (that instant path was the widget's own,
            // separate close-signal, already implemented on the helpdesk app's side).
            //
            // Deliberately only listens for pagehide, not visibilitychange
            // -- Moodle page-to-page navigation is a full document unload,
            // so pagehide already covers real tab/window close *and*
            // genuine navigation away (the next page's own beacon calls
            // sendHeartbeat() immediately on load, so a normal click to
            // another course page just flips the row offline then online
            // again within about a page load). Wiring visibilitychange too
            // would additionally mark a visitor offline any time they
            // merely switch to another browser tab without closing
            // anything, which is a different, unwanted behaviour the user
            // did not ask for.
            . 'function sendEndSession() {'
            . '  fetch(endSessionEndpoint, {'
            . '    method: \'POST\','
            . '    keepalive: true,'
            . '    headers: {'
            . '      \'Content-Type\': \'application/json\','
            . '      \'apikey\': anonKey,'
            . '      \'Authorization\': \'Bearer \' + anonKey'
            . '    },'
            . '    body: JSON.stringify({'
            . '      p_client_id: clientId,'
            . '      p_visitor_key: getVisitorKey()'
            . '    })'
            . '  }).catch(function() { /* best-effort: the 90-second heartbeat timeout remains the fallback */ });'
            . '}'
            . 'window.addEventListener(\'pagehide\', sendEndSession);'
            . 'sendHeartbeat();'
            . 'setInterval(sendHeartbeat, heartbeatIntervalMs);'
            . '})();';
    }

    /**
     * JS for the existing, unchanged authenticated flow: the panel iframe is
     * created lazily on first click and just toggled (shown/hidden) after
     * that, so the Tier-1/Tier-2 exchange only runs once per page view
     * rather than on every open/close.
     *
     * Also listens for a same-origin `helpdesk:minimize` / `helpdesk:restore`
     * postMessage, relayed up to us by get_help.php (which itself forwards
     * whatever the cross-origin widget iframe nested inside it sends — see
     * that file's inline JS for the relay and the reasoning behind it). When
     * received, we shrink/restore the panel's own height so the outer
     * floating box actually collapses along with the widget's own minimized
     * content, instead of staying at full size around a collapsed widget.
     *
     * The panel's open/closed state is also mirrored into sessionStorage
     * (tab-scoped, so each tab/window remembers its own state and closing
     * one doesn't affect another) under the 'local_chronifysupport_panel_open' key.
     * Moodle page-to-page navigation is a full document reload that tears
     * down this entire script and the panel DOM along with it, so without
     * this the panel would silently revert to closed on every click to a
     * different page even though the visitor never explicitly minimized it.
     * On init we check that flag and, if set, re-open the panel immediately
     * -- letting the visitor keep browsing the site and chatting at the same
     * time, with the panel only actually closing when they explicitly
     * minimize it (via the widget's own minimize control, or the overlay
     * backdrop on narrow viewports).
     *
     * @param string $label Button label.
     * @param \moodle_url $geturl URL of get_help.php for this page.
     * @param string $loadingtext Text shown while the panel loads.
     * @param string $loadingslowtext Text shown when loading takes long.
     * @return string The script source.
     */
    protected static function build_authenticated_js(
        string $label,
        \moodle_url $geturl,
        string $loadingtext = '',
        string $loadingslowtext = ''
    ): string {
        // The panel now docks full-height against the right edge, so
        // there's no "minimized height" to track -- minimize/restore slides
        // the panel off/on screen instead, and toggles whether the host
        // page content is pushed over to make room for it.
        $panelwidth = '420px';

        return '(function() {'
            . 'var btn = document.getElementById(\'local_chronifysupport_get_help_button\');'
            . 'if (!btn) { return; }'
            . 'var panel = null;'
            . 'var iframe = null;'
            . 'var geturl = ' . self::js_value($geturl->out(false)) . ';'
            . 'var label = ' . self::js_value($label) . ';'
            . 'var panelWidth = ' . self::js_value($panelwidth) . ';'
            . 'var PUSH_MIN_VIEWPORT = 1024;'
            . 'var panelStorageKey = \'local_chronifysupport_panel_open\';'
            . 'var backdrop = null;'
            . 'var pushedHeaderEls = [];'
            . 'var panelActive = false;'
            . 'function setPanelOpen(open) {'
            . '  try {'
            . '    if (open) { window.sessionStorage.setItem(panelStorageKey, \'1\'); }'
            . '    else { window.sessionStorage.removeItem(panelStorageKey); }'
            . '  } catch (e) { /* best-effort: worst case the panel just won\'t auto-reopen on the next page */ }'
            . '}'
            . 'function showOverlay() {'
            . '  if (!backdrop) {'
            . '    backdrop = document.createElement(\'div\');'
            . '    backdrop.style.cssText = \'position:fixed;top:0;left:0;right:0;bottom:0;'
            . 'background:rgba(17,17,17,.25);z-index:2147482999;opacity:0;pointer-events:none;'
            . 'transition:opacity .2s ease;\';'
            . '    backdrop.addEventListener(\'click\', function() { hidePanel(); });'
            . '    document.body.appendChild(backdrop);'
            . '  }'
            . '  backdrop.style.pointerEvents = \'auto\';'
            . '  requestAnimationFrame(function() { backdrop.style.opacity = \'1\'; });'
            . '}'
            . 'function hideOverlay() {'
            . '  if (!backdrop) { return; }'
            . '  backdrop.style.opacity = \'0\';'
            . '  backdrop.style.pointerEvents = \'none\';'
            . '}'
            // Pushing document.body over with margin-right only narrows
            // normal-flow content -- it does nothing for the site's
            // position:fixed header/navbar (virtually every Moodle theme
            // docks its top bar with position:fixed), so without this the
            // header stays full-width and the panel just overlaps it. This
            // finds fixed, near-full-width, near-top elements generically
            // (no theme-specific selectors) and narrows those too, so it
            // keeps working across different themes on different sites.
            . 'function pushFixedHeaders(active) {'
            . '  if (active) {'
            . '    var vw = window.innerWidth;'
            . '    var atTop = window.scrollY <= 50;'
            . '    var candidates = document.querySelectorAll(\'body > *, body > * > *, body > * > * > *\');'
            . '    for (var i = 0; i < candidates.length; i++) {'
            . '      var el = candidates[i];'
            . '      if (el === panel || el === backdrop || el === btn) { continue; }'
            . '      if (panel && panel.contains(el)) { continue; }'
            . '      if (backdrop && backdrop.contains(el)) { continue; }'
            . '      if (btn && btn.contains(el)) { continue; }'
            . '      var cs = window.getComputedStyle(el);'
            . '      var isFixed = cs.position === \'fixed\' || cs.position === \'sticky\';'
            . '      var isAbsolute = cs.position === \'absolute\';'
            . '      if (!isFixed && !atTop) { continue; }'
            . '      var rect = el.getBoundingClientRect();'
            . '      if (rect.top > 120) { continue; }'
            . '      var isWide = rect.width >= vw * 0.85;'
            . '      var canUseRightInset = (isFixed || isAbsolute) && cs.right !== \'auto\';'
            . '      var reachesRightEdge = rect.right >= vw - 15;'
            . '      if (!isWide && !(canUseRightInset && reachesRightEdge)) { continue; }'
            . '      if (!isWide && rect.height < 150) { continue; }'
            . '      if (!isWide && canUseRightInset) {'
            . '        pushedHeaderEls.push({ el: el, mode: \'right\', right: el.style.right, transition: el.style.transition });'
            . '        el.style.transition = \'right .2s ease\';'
            . '        el.style.right = \'calc(\' + cs.right + \' + \' + panelWidth + \')\';'
            . '      } else {'
            . '        pushedHeaderEls.push({ el: el, mode: \'width\', '
            . 'maxWidth: el.style.maxWidth, transition: el.style.transition });'
            . '        el.style.transition = \'max-width .2s ease\';'
            . '        el.style.maxWidth = \'calc(100vw - \' + panelWidth + \')\';'
            . '      }'
            . '    }'
            . '  } else {'
            . '    for (var j = 0; j < pushedHeaderEls.length; j++) {'
            . '      var item = pushedHeaderEls[j];'
            . '      if (item.mode === \'right\') {'
            . '        item.el.style.right = item.right;'
            . '      } else {'
            . '        item.el.style.maxWidth = item.maxWidth;'
            . '      }'
            . '      item.el.style.transition = item.transition;'
            . '    }'
            . '    pushedHeaderEls = [];'
            . '  }'
            . '}'
            . 'function pushBody(active) {'
            . '  var noPushLayouts = [\'pagelayout-login\', \'pagelayout-maintenance\'];'
            . '  var canPush = window.innerWidth >= PUSH_MIN_VIEWPORT;'
            . '  if (canPush) {'
            . '    for (var i = 0; i < noPushLayouts.length; i++) {'
            . '      if (document.body.classList.contains(noPushLayouts[i])) { canPush = false; break; }'
            . '    }'
            . '  }'
            . '  if (!canPush) {'
            . '    pushFixedHeaders(false);'
            . '    document.body.style.marginRight = \'\';'
            . '    if (active) { showOverlay(); } else { hideOverlay(); }'
            . '    return;'
            . '  }'
            . '  hideOverlay();'
            . '  document.body.style.transition = \'margin-right .2s ease\';'
            . '  document.body.style.marginRight = active ? panelWidth : \'\';'
            . '  pushFixedHeaders(active);'
            . '}'
            . 'function handleResize() {'
            . '  if (panelActive) { pushBody(true); }'
            . '}'
            . 'var resizeTimer = null;'
            . 'window.addEventListener(\'resize\', function() {'
            . '  clearTimeout(resizeTimer);'
            . '  resizeTimer = setTimeout(handleResize, 150);'
            . '});'
            . 'function hidePanel() {'
            . '  if (panel) { panel.style.transform = \'translateX(100%)\'; }'
            . '  pushBody(false);'
            . '  btn.style.display = \'\';'
            . '  setPanelOpen(false);'
            . '  panelActive = false;'
            . '}'
            . 'function showPanel() {'
            . '  var reopening = !!panel;'
            . '  if (!panel) {'
            . '    panel = document.createElement(\'div\');'
            . '    panel.style.cssText = \'position:fixed;top:0;right:0;height:100vh;height:100dvh;'
            . 'width:\' + panelWidth + \';max-width:100vw;z-index:2147483000;overflow:hidden;'
            . 'background:#fff;border-left:1px solid #e5e7eb;'
            . 'box-shadow:-2px 0 16px rgba(0,0,0,0.08);'
            . 'transition:transform .2s ease;transform:translateX(0);\';'
            // Loading indicator: shown instantly (no server round trip) while the
            // panel's frame loads; same look as get_help.php's own indicator so the
            // hand-over is seamless. Removed once the frame has loaded (or after a
            // safety timeout). Text via textContent only.
            . '    var loader = document.createElement(\'div\');'
            . '    loader.setAttribute(\'role\', \'status\');'
            . '    loader.setAttribute(\'aria-live\', \'polite\');'
            . '    loader.style.cssText = \'position:absolute;top:0;left:0;right:0;bottom:0;display:flex;flex-direction:column;'
            . 'align-items:center;justify-content:center;gap:14px;padding:24px;text-align:center;background:#fff;color:#6b7280;'
            . 'font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;\';'
            . '    loader.innerHTML = ' . self::js_value(self::loader_svg()) . ';'
            . '    var loaderText = document.createElement(\'div\');'
            . '    loaderText.textContent = ' . self::js_value($loadingtext) . ';'
            . '    loader.appendChild(loaderText);'
            . '    var loaderSlow = setTimeout(function() { loaderText.textContent = '
            . self::js_value($loadingslowtext !== '' ? $loadingslowtext : $loadingtext)
            . '; }, 8000);'
            . '    panel.appendChild(loader);'
            . '    function dropLoader() { clearTimeout(loaderSlow); if '
            . '(loader.parentNode) { loader.parentNode.removeChild(loader); } }'
            . '    iframe = document.createElement(\'iframe\');'
            . '    iframe.src = geturl;'
            . '    iframe.title = label;'
            . '    iframe.style.cssText = \'position:relative;z-index:1;width:100%;height:100%;border:0;display:block;\';'
            . '    iframe.addEventListener(\'load\', dropLoader);'
            . '    setTimeout(dropLoader, 20000);'
            . '    panel.appendChild(iframe);'
            . '    document.body.appendChild(panel);'
            . '  } else {'
            . '    panel.style.transform = \'translateX(0)\';'
            // Force a reflow: guards against the iframe staying blank if the browser
            // skipped repainting it while off-screen.
            . '    void panel.offsetHeight;'
            . '  }'
            // This button reopens the panel from the host side, which is a
            // different path than the widget's own restore control telling
            // us to show it (that one already round-trips through the
            // message listener below). Mirror the same 'helpdesk:restore'
            // message back to the iframe here too, so a widget that paused
            // or reset its own view when it last asked to be minimized gets
            // a chance to resume/redraw instead of staying stuck blank.
            . '  if (reopening && iframe && iframe.contentWindow) {'
            . '    try { iframe.contentWindow.postMessage({ type: \'helpdesk:restore\' }, window.location.origin); }'
            . '    catch (e) { /* best-effort: worst case the widget just stays however it last left itself */ }'
            . '  }'
            . '  pushBody(true);'
            . '  btn.style.display = \'none\';'
            . '  setPanelOpen(true);'
            . '  panelActive = true;'
            . '}'
            . 'window.addEventListener(\'message\', function(event) {'
            . '  if (!panel || !iframe || event.origin !== window.location.origin'
            . ' || event.source !== iframe.contentWindow) { return; }'
            . '  if (!event.data) { return; }'
            . '  if (event.data.type === \'helpdesk:minimize\') {'
            . '    hidePanel();'
            . '  } else if (event.data.type === \'helpdesk:restore\') {'
            . '    showPanel();'
            . '  }'
            . '});'
            . 'btn.addEventListener(\'click\', function() { showPanel(); });'
            . 'try {'
            . '  if (window.sessionStorage.getItem(panelStorageKey) === \'1\') { showPanel(); }'
            . '} catch (e) { /* best-effort: panel just starts closed like before */ }'
            . '})();';
    }

    /**
     * JS for the anonymous (login-page) flow: first click renders a small
     * First name/Last name/Email pre-chat form inside the panel (built entirely
     * client-side — there is no server-rendered iframe target to point at
     * yet, since no session exists until the form is submitted). This form
     * only collects identity — the embedded widget shows its own "What do
     * you need help with?" prompt immediately after loading, so the issue
     * text is captured there instead of being asked for twice. Submitting
     * POSTs JSON to get_help_anonymous.php; on success the form is replaced
     * with the real widget iframe, addressed via the URL fragment exactly
     * like the authenticated flow (contract §10.1), and a silent refresh
     * timer is armed that re-POSTs the same {name, email} to
     * refresh_token_anonymous.php at ~80% of the token TTL — mirroring
     * get_help.php's own scheduleRefresh/doRefresh pattern.
     *
     * Subsequent clicks (once a session exists) just toggle the panel,
     * exactly like the authenticated flow. Re-opening after a session was
     * already established does NOT re-show the form.
     *
     * renderForm() also fires a one-off, best-effort
     * fetch to the get_widget_banner_status() RPC (same anon-key + client_id
     * call shape as build_tracking_js()/build_visitor_beacon_js() -- see
     * those for the established pattern this reuses) and, if it comes back
     * with show_banner=true, inserts a plain-text notice between the form
     * title and the divider -- mirroring where the same banner appears on
     * the authenticated widget's own pre-chat screen (the same notice shown there). A
     * missing/failed call, or the feature being unconfigured
     * ($supabaseurl/$supabaseanonkey/$clientid empty), simply means no
     * banner is shown; it never blocks or delays the form itself from
     * rendering.
     *
     * Like build_authenticated_js(), the panel's open/closed state is
     * mirrored into sessionStorage ('local_chronifysupport_panel_open') so it
     * survives Moodle's full-page-reload navigation and only actually
     * closes when the visitor explicitly minimizes it. Because this flow's
     * session additionally needs the visitor's name/email (for the token
     * refresh call) and the widget's base URL (to rebuild the iframe src)
     * to reconnect silently, renderWidget() now also persists
     * helpdesk_base_url/name/email into local_chronifysupport_session alongside
     * the existing access_token/expires_at/user_id/conversation_id -- so
     * that re-opening the panel on a later page, while the saved session
     * is still valid, can jump straight to renderWidget() again instead of
     * re-showing the pre-chat form (which would otherwise defeat the point
     * of staying connected while browsing the site).
     *
     * @param string $label Button label.
     * @param string $supabaseurl Supabase project URL.
     * @param string $supabaseanonkey Supabase anon key.
     * @param string $clientid Client ID of this installation.
     * @param string|null $pagetitle Current page title.
     * @param string $pageurl Current page URL.
     * @param string $helpdeskbaseurl Help desk base URL.
     * @param string $loadingtext Text shown while the panel loads.
     * @param string $loadingslowtext Text shown when loading takes long.
     * @return string The script source.
     */
    protected static function build_anonymous_js(
        string $label,
        string $supabaseurl = '',
        string $supabaseanonkey = '',
        string $clientid = '',
        ?string $pagetitle = null,
        string $pageurl = '',
        string $helpdeskbaseurl = '',
        string $loadingtext = '',
        string $loadingslowtext = ''
    ): string {
        $bannerendpoint = ($supabaseurl !== '' && $supabaseanonkey !== '' && $clientid !== '')
            ? rtrim($supabaseurl, '/') . '/rest/v1/rpc/get_widget_banner_status'
            : '';
        // Articles tab on the pre-chat form: same client_id ->
        // organization_id resolution as the banner endpoint above, just a
        // different RPC (get_published_kb_articles) and no user auth --
        // the anon key is sufficient since the RPC is SECURITY DEFINER and
        // scoped by client_id server-side.
        // Live agent availability (online/offline) for the status row; same
        // anon-key, client_id-scoped pattern, returns only a boolean.
        $presenceendpoint = ($supabaseurl !== '' && $supabaseanonkey !== '' && $clientid !== '')
            ? rtrim($supabaseurl, '/') . '/rest/v1/rpc/get_widget_agent_presence'
            : '';
        $articlesendpoint = ($supabaseurl !== '' && $supabaseanonkey !== '' && $clientid !== '')
            ? rtrim($supabaseurl, '/') . '/rest/v1/rpc/get_published_kb_articles'
            : '';
        // Which of the Knowledge Base / Search tabs the organization admin
        // has switched on (Administration -> Knowledge Base -> Chat widget).
        // Same anon-key + client_id pattern; returns only two booleans.
        $tabsendpoint = ($supabaseurl !== '' && $supabaseanonkey !== '' && $clientid !== '')
            ? rtrim($supabaseurl, '/') . '/rest/v1/rpc/get_widget_tabs_status'
            : '';
        // Knowledge Base search tab: unlike the list above, this calls the
        // helpdesk app itself (not Supabase REST directly) at
        // /api/public/kb-search, because turning the typed query into an
        // embedding vector requires the app's own server-side AI
        // credentials -- the anon key alone can't do that client-side.
        $searchendpoint = ($helpdeskbaseurl !== '' && $clientid !== '')
            ? rtrim($helpdeskbaseurl, '/') . '/api/public/kb-search'
            : '';
        $submiturl = new \moodle_url('/local/chronifysupport/get_help_anonymous.php');
        $refreshurl = new \moodle_url('/local/chronifysupport/refresh_token_anonymous.php');

        // See build_authenticated_js() for the shared full-height-panel
        // design -- there's no "minimized height" to track here either.
        $panelwidth = '420px';

        $strings = [
            'formtitle' => get_string('get_help_anon_form_title', 'local_chronifysupport'),
            'subtitle' => get_string('get_help_anon_subtitle', 'local_chronifysupport'),
            'firstnamelabel' => get_string('get_help_anon_firstname_label', 'local_chronifysupport'),
            'lastnamelabel' => get_string('get_help_anon_lastname_label', 'local_chronifysupport'),
            'emaillabel' => get_string('get_help_anon_email_label', 'local_chronifysupport'),
            'submit' => get_string('get_help_anon_submit', 'local_chronifysupport'),
            'submitting' => get_string('get_help_anon_submitting', 'local_chronifysupport'),
            'error' => get_string('get_help_anon_error', 'local_chronifysupport'),
            // The pre-chat form reuses the same
            // cream header / logo / status / segmented-tabs chrome as the
            // main widget, so it
            // needs several more display strings than the plain title +
            // close button it had before.
            'reassure' => get_string('get_help_anon_reassure', 'local_chronifysupport'),
            'onlinestatus' => get_string('get_help_anon_online_status', 'local_chronifysupport'),
            'offlinestatus' => self::string_or('get_help_anon_offline_status', 'Offline'),
            'offlinenote' => self::string_or(
                'get_help_anon_offline_note',
                'We\'re currently offline. Leave a message and we\'ll reply as soon as we\'re back.'
            ),
            'minimize' => get_string('get_help_anon_minimize', 'local_chronifysupport'),
            'tabchat' => get_string('get_help_anon_tab_chat', 'local_chronifysupport'),
            // Offline variants: used only while no agent is available, so the
            // widget never promises a live "chat" (online wording unchanged).
            'submitoffline' => self::string_or('get_help_anon_submit_offline', 'Send message'),
            'submittingoffline' => self::string_or('get_help_anon_submitting_offline', 'Sending...'),
            'minimizeoffline' => self::string_or('get_help_anon_minimize_offline', 'Minimize'),
            'tabchatoffline' => self::string_or('get_help_anon_tab_chat_offline', 'Message'),
            'tabarticles' => get_string('get_help_anon_tab_articles', 'local_chronifysupport'),
            'tabsearch' => get_string('get_help_anon_tab_search', 'local_chronifysupport'),
            'articlesloading' => get_string('get_help_anon_articles_loading', 'local_chronifysupport'),
            'articlesempty' => get_string('get_help_anon_articles_empty', 'local_chronifysupport'),
            'articleserror' => get_string('get_help_anon_articles_error', 'local_chronifysupport'),
            'articlesback' => get_string('get_help_anon_articles_back', 'local_chronifysupport'),
            'searchplaceholder' => get_string('get_help_anon_search_placeholder', 'local_chronifysupport'),
            'searchloading' => get_string('get_help_anon_search_loading', 'local_chronifysupport'),
            'searchempty' => get_string('get_help_anon_search_empty', 'local_chronifysupport'),
            'searcherror' => get_string('get_help_anon_search_error', 'local_chronifysupport'),
            'searchback' => get_string('get_help_anon_search_back', 'local_chronifysupport'),
        ];

        return '(function() {'
            . 'var btn = document.getElementById(\'local_chronifysupport_get_help_button\');'
            . 'if (!btn) { return; }'
            . 'var panel = null;'
            . 'var widgetFrame = null;'
            . 'var widgetOrigin = \'\';'
            . 'var sessionStarted = false;'
            . 'var submitUrl = ' . self::js_value($submiturl->out(false)) . ';'
            . 'var refreshUrl = ' . self::js_value($refreshurl->out(false)) . ';'
            . 'var bannerEndpoint = ' . self::js_value($bannerendpoint) . ';'
            . 'var articlesEndpoint = ' . self::js_value($articlesendpoint) . ';'
            . 'var presenceEndpoint = ' . self::js_value($presenceendpoint) . ';'
            . 'var presenceTimer = null;'
            . 'var isOffline = false;'
            . 'var tabsEndpoint = ' . self::js_value($tabsendpoint) . ';'
            . 'var tabsStatus = null;'
            . 'var searchEndpoint = ' . self::js_value($searchendpoint) . ';'
            . 'var bannerAnonKey = ' . self::js_value($supabaseanonkey) . ';'
            . 'var bannerClientId = ' . self::js_value($clientid) . ';'
            . 'var pageTitle = ' . self::js_value($pagetitle) . ';'
            . 'var pageUrl = ' . self::js_value($pageurl) . ';'
            . 'var label = ' . self::js_value($label) . ';'
            . 'var strings = ' . self::js_value($strings) . ';'
            . 'var panelWidth = ' . self::js_value($panelwidth) . ';'
            . 'var PUSH_MIN_VIEWPORT = 1024;'
            . 'var backdrop = null;'
            . 'var pushedHeaderEls = [];'
            . 'var panelActive = false;'
            . 'function showOverlay() {'
            . '  if (!backdrop) {'
            . '    backdrop = document.createElement(\'div\');'
            . '    backdrop.style.cssText = \'position:fixed;top:0;left:0;right:0;bottom:0;'
            . 'background:rgba(17,17,17,.25);z-index:2147482999;opacity:0;pointer-events:none;'
            . 'transition:opacity .2s ease;\';'
            . '    backdrop.addEventListener(\'click\', function() { hidePanel(); });'
            . '    document.body.appendChild(backdrop);'
            . '  }'
            . '  backdrop.style.pointerEvents = \'auto\';'
            . '  requestAnimationFrame(function() { backdrop.style.opacity = \'1\'; });'
            . '}'
            . 'function hideOverlay() {'
            . '  if (!backdrop) { return; }'
            . '  backdrop.style.opacity = \'0\';'
            . '  backdrop.style.pointerEvents = \'none\';'
            . '}'
            // Pushing document.body over with margin-right only narrows
            // normal-flow content -- it does nothing for the site's
            // position:fixed header/navbar (virtually every Moodle theme
            // docks its top bar with position:fixed), so without this the
            // header stays full-width and the panel just overlaps it. This
            // finds fixed, near-full-width, near-top elements generically
            // (no theme-specific selectors) and narrows those too, so it
            // keeps working across different themes on different sites.
            . 'function pushFixedHeaders(active) {'
            . '  if (active) {'
            . '    var vw = window.innerWidth;'
            . '    var atTop = window.scrollY <= 50;'
            . '    var candidates = document.querySelectorAll(\'body > *, body > * > *, body > * > * > *\');'
            . '    for (var i = 0; i < candidates.length; i++) {'
            . '      var el = candidates[i];'
            . '      if (el === panel || el === backdrop || el === btn) { continue; }'
            . '      if (panel && panel.contains(el)) { continue; }'
            . '      if (backdrop && backdrop.contains(el)) { continue; }'
            . '      if (btn && btn.contains(el)) { continue; }'
            . '      var cs = window.getComputedStyle(el);'
            . '      var isFixed = cs.position === \'fixed\' || cs.position === \'sticky\';'
            . '      var isAbsolute = cs.position === \'absolute\';'
            . '      if (!isFixed && !atTop) { continue; }'
            . '      var rect = el.getBoundingClientRect();'
            . '      if (rect.top > 120) { continue; }'
            . '      var isWide = rect.width >= vw * 0.85;'
            . '      var canUseRightInset = (isFixed || isAbsolute) && cs.right !== \'auto\';'
            . '      var reachesRightEdge = rect.right >= vw - 15;'
            . '      if (!isWide && !(canUseRightInset && reachesRightEdge)) { continue; }'
            . '      if (!isWide && rect.height < 150) { continue; }'
            . '      if (!isWide && canUseRightInset) {'
            . '        pushedHeaderEls.push({ el: el, mode: \'right\', right: el.style.right, transition: el.style.transition });'
            . '        el.style.transition = \'right .2s ease\';'
            . '        el.style.right = \'calc(\' + cs.right + \' + \' + panelWidth + \')\';'
            . '      } else {'
            . '        pushedHeaderEls.push({ el: el, mode: \'width\', '
            . 'maxWidth: el.style.maxWidth, transition: el.style.transition });'
            . '        el.style.transition = \'max-width .2s ease\';'
            . '        el.style.maxWidth = \'calc(100vw - \' + panelWidth + \')\';'
            . '      }'
            . '    }'
            . '  } else {'
            . '    for (var j = 0; j < pushedHeaderEls.length; j++) {'
            . '      var item = pushedHeaderEls[j];'
            . '      if (item.mode === \'right\') {'
            . '        item.el.style.right = item.right;'
            . '      } else {'
            . '        item.el.style.maxWidth = item.maxWidth;'
            . '      }'
            . '      item.el.style.transition = item.transition;'
            . '    }'
            . '    pushedHeaderEls = [];'
            . '  }'
            . '}'
            . 'function pushBody(active) {'
            // Login page: always use the overlay fallback. Login/marketing
            // layouts vary too unpredictably across Moodle themes for the
            // generic push heuristic to be 100% reliable, so this flow
            // intentionally never narrows the page.
            . '  var canPush = false;' /* login page: always overlay, never push */
            . '  if (!canPush) {'
            . '    pushFixedHeaders(false);'
            . '    document.body.style.marginRight = \'\';'
            . '    if (active) { showOverlay(); } else { hideOverlay(); }'
            . '    return;'
            . '  }'
            . '  hideOverlay();'
            . '  document.body.style.transition = \'margin-right .2s ease\';'
            . '  document.body.style.marginRight = active ? panelWidth : \'\';'
            . '  pushFixedHeaders(active);'
            . '}'
            . 'function handleResize() {'
            . '  if (panelActive) { pushBody(true); }'
            . '}'
            . 'var resizeTimer = null;'
            . 'window.addEventListener(\'resize\', function() {'
            . '  clearTimeout(resizeTimer);'
            . '  resizeTimer = setTimeout(handleResize, 150);'
            . '});'
            . 'var panelStorageKey = \'local_chronifysupport_panel_open\';'
            . 'function setPanelOpen(open) {'
            . '  try {'
            . '    if (open) { window.sessionStorage.setItem(panelStorageKey, \'1\'); }'
            . '    else { window.sessionStorage.removeItem(panelStorageKey); }'
            . '  } catch (e) { /* best-effort: worst case the panel just won\'t auto-reopen on the next page */ }'
            . '}'
            . 'var stylesInjected = false;'
            . 'var sessionStorageKey = \'local_chronifysupport_session\';'
            // Same client-generated visitor_key + storage-key/cookie-fallback
            // scheme as build_visitor_beacon_js()'s own getVisitorKey() (see
            // that function for the full rationale) -- duplicated here rather
            // than shared, since this form runs in a completely separate IIFE
            // from the beacon script. Reads (and persists back) whatever the
            // beacon already generated for this browser when that feature is
            // on, or generates a fresh one on the spot when it's off, so the
            // widget below always has *a* visitor_key to report ticket-created
            // / tab-closed events with, exactly like the authenticated flow.
            . 'var visitorKeyStorageKey = \'local_chronifysupport_visitor_key\';'
            // Pre-rename key (plugin was called local_helpdesk): read once so returning
            // visitors keep their identity, then the value is re-saved under the new key.
            . 'var legacyVisitorKeyStorageKey = \'local_helpdesk_visitor_key\';'
            . 'function getCookie(name) {'
            . '  var match = document.cookie.match(new RegExp(\'(^|; )\' + name + \'=([^;]*)\'));'
            . '  return match ? decodeURIComponent(match[2]) : null;'
            . '}'
            . 'function setCookie(name, value) {'
            . '  var maxAgeSeconds = 60 * 60 * 24 * 365;'
            . '  try { document.cookie = name + \'=\' + encodeURIComponent(value) + \'; path=/; '
            . 'max-age=\' + maxAgeSeconds + \'; SameSite=Lax\'; } catch (e) { /* best-effort */ }'
            . '}'
            . 'function getVisitorKey() {'
            . '  var key;'
            . '  try { key = window.localStorage.getItem(visitorKeyStorageKey); } catch (e) { key = null; }'
            . '  if (!key) { key = getCookie(visitorKeyStorageKey); }'
            . '  if (!key) { try { key = window.localStorage.getItem(legacyVisitorKeyStorageKey); } catch (e) { key = null; } }'
            . '  if (!key) { key = getCookie(legacyVisitorKeyStorageKey); }'
            . '  if (key) {'
            . '    try { window.localStorage.setItem(visitorKeyStorageKey, key); } catch (e) { /* best-effort */ }'
            . '    setCookie(visitorKeyStorageKey, key);'
            . '    return key;'
            . '  }'
            . '  if (window.crypto && window.crypto.randomUUID) {'
            . '    key = window.crypto.randomUUID();'
            . '  } else {'
            . '    key = \'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx\'.replace(/[xy]/g, function(c) {'
            . '      var r = Math.random() * 16 | 0;'
            . '      var v = (c === \'x\') ? r : (r & 0x3 | 0x8);'
            . '      return v.toString(16);'
            . '    });'
            . '  }'
            . '  try { window.localStorage.setItem(visitorKeyStorageKey, key); } catch (e) { /* best-effort */ }'
            . '  setCookie(visitorKeyStorageKey, key);'
            . '  return key;'
            . '}'
            // Shared with build_visitor_beacon_js()'s own saveSession(): this
            // is deliberately the same localStorage key and merge-not-replace
            // shape, so that once the visitor-beacon feature is on, its
            // heartbeat picks up the conversation_id set below and the
            // visitor_sessions row for this browser gets linked to the
            // ticket this flow creates — without that, an anonymous visitor
            // who submits this form and gets a ticket keeps showing up under
            // "All other visitors" on the agent Visitors page forever, since
            // nothing ever tells that row which conversation belongs to it.
            . 'function saveSession(patch) {'
            . '  var current = {};'
            . '  try { current = JSON.parse(window.localStorage.getItem(sessionStorageKey)) || {}; } catch (e) { current = {}; }'
            . '  for (var k in patch) { current[k] = patch[k]; }'
            . '  try { window.localStorage.setItem(sessionStorageKey, JSON.stringify(current)); } catch (e) { /* best-effort */ }'
            . '}'
            // Lets showPanel() decide whether a freshly (re)created panel
            // can jump straight to renderWidget() -- silently reconnecting
            // to an existing chat -- or has to fall back to renderForm()
            // because no usable session was ever established, or it has
            // since expired.
            . 'function getStoredSession() {'
            . '  var session;'
            . '  try { session = JSON.parse(window.localStorage.getItem(sessionStorageKey)); } catch (e) { session = null; }'
            . '  if (!session || !session.access_token || !session.user_id || !session.helpdesk_base_url'
            . ' || !session.name || !session.email) { return null; }'
            . '  if (session.expires_at && new Date(session.expires_at).getTime() <= Date.now()) { return null; }'
            . '  return session;'
            . '}'
            . 'function injectStyles() {'
            . '  if (stylesInjected) { return; }'
            . '  stylesInjected = true;'
            . '  var style = document.createElement(\'style\');'
            . '  style.textContent = ['
            . '    \'.lhd-anon-field{margin-bottom:14px}\','
            . '    \'.lhd-anon-input{transition:border-color .15s ease, box-shadow .15s ease}\','
            . '    \'.lhd-anon-input:focus{outline:none;border-color:#b2532b;box-shadow:0 0 0 0.5px #b2532b}\','
            . '    \'.lhd-anon-submit{transition:background-color .15s ease, transform .05s ease}\','
            . '    \'.lhd-anon-submit:hover:not(:disabled){background:#9d4523}\','
            . '    \'.lhd-anon-submit:active:not(:disabled){transform:translateY(1px)}\','
            . '    \'.lhd-anon-submit:disabled{opacity:0.7;cursor:default}\''
            . '  ].join(\'\');'
            . '  document.head.appendChild(style);'
            . '}'
            // The renderForm() function builds the same
            // cream header / logo / status row / segmented Chat-Articles-
            // Search tabs as the main widget, instead of the old plain title
            // + close-button header. The submission logic below this point
            // -- field names, honeypot, fetch(submitUrl, ...), session
            // handling, and the banner fetch -- is untouched.
            // Small, safe Markdown renderer for article bodies (DOM nodes only,
            // never innerHTML; links limited to http/https).
            . self::markdown_renderer_js()
            . 'function renderForm() {'
            . '  injectStyles();'
            . '  panel.innerHTML = \'\';'
            . '  var outer = document.createElement(\'div\');'
            . '  outer.style.cssText = \'display:flex;flex-direction:column;height:100%;'
            . 'box-sizing:border-box;font-family:inherit;\';'
            . '  var header = document.createElement(\'div\');'
            . '  header.style.cssText = \'flex-shrink:0;padding:12px 16px;background:#f1eee8;'
            . 'border-bottom:1px solid #e3ddd3;box-sizing:border-box;\';'
            . '  var headertop = document.createElement(\'div\');'
            . '  headertop.style.cssText = \'display:flex;align-items:center;'
            . 'justify-content:space-between;gap:8px;\';'
            . '  var brand = document.createElement(\'div\');'
            . '  brand.style.cssText = \'display:flex;align-items:center;gap:8px;min-width:0;\';'
            . '  var logoicon = document.createElement(\'span\');'
            . '  logoicon.setAttribute(\'aria-hidden\', \'true\');'
            . '  logoicon.style.cssText = \'display:inline-flex;flex-shrink:0;width:22px;'
            . 'height:22px;color:#b2532b;\';'
            . '  logoicon.innerHTML = \'<svg viewBox="0 0 24 24" fill="none" '
            . 'xmlns="http://www.w3.org/2000/svg" width="22" height="22">'
            . '<path d="M12 3C7.03 3 3 6.58 3 11c0 2.08 0.9 3.97 2.38 5.39L4 21l4.89-1.64'
            . 'C10.03 19.78 11 20 12 20c4.97 0 9-3.58 9-9s-4.03-8-9-8z" stroke="currentColor" '
            . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="12" cy="11" r="4" stroke="currentColor" stroke-width="1.6"/>'
            . '<path d="M12 9v2l1.5 1" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"/></svg>\';'
            . '  var wordmark = document.createElement(\'h1\');'
            . '  wordmark.style.cssText = \'margin:0;font-size:16px;font-weight:700;'
            . 'color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;\';'
            . '  wordmark.appendChild(document.createTextNode(\'Chronify\'));'
            . '  var wordmarksuffix = document.createElement(\'span\');'
            . '  wordmarksuffix.style.color = \'#b2532b\';'
            . '  wordmarksuffix.textContent = \'.Support\';'
            . '  wordmark.appendChild(wordmarksuffix);'
            . '  brand.appendChild(logoicon);'
            . '  brand.appendChild(wordmark);'
            . '  var closebtn = document.createElement(\'button\');'
            . '  closebtn.type = \'button\';'
            . '  closebtn.setAttribute(\'aria-label\', strings.minimize);'
            . '  closebtn.style.cssText = \'display:inline-flex;align-items:center;'
            . 'justify-content:center;flex-shrink:0;width:32px;height:32px;padding:0;'
            . 'border:0;border-radius:8px;background:transparent;color:#7a736a;cursor:pointer;\';'
            . '  closebtn.innerHTML = \'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" width="16" height="16" aria-hidden="true">'
            . '<line x1="5" y1="12" x2="19" y2="12"></line></svg>\';'
            . '  closebtn.addEventListener(\'mouseenter\', function() { closebtn.style.background = \'rgba(0,0,0,0.05)\'; });'
            . '  closebtn.addEventListener(\'mouseleave\', function() { closebtn.style.background = \'transparent\'; });'
            . '  closebtn.addEventListener(\'click\', function() { hidePanel(); });'
            . '  headertop.appendChild(brand);'
            . '  headertop.appendChild(closebtn);'
            . '  var statusrow = document.createElement(\'div\');'
            . '  statusrow.style.cssText = \'display:flex;align-items:center;gap:6px;'
            . 'margin-top:4px;font-size:12px;color:#7a736a;\';'
            . '  var statusdot = document.createElement(\'span\');'
            . '  statusdot.style.cssText = \'width:8px;height:8px;flex-shrink:0;'
            . 'border-radius:50%;background:#2e9e55;display:inline-block;\';'
            . '  var statustext = document.createElement(\'span\');'
            . '  statustext.textContent = strings.onlinestatus;'
            . '  statusrow.appendChild(statusdot);'
            . '  statusrow.appendChild(statustext);'
            . '  var tabsrow = document.createElement(\'div\');'
            . '  tabsrow.style.cssText = \'display:grid;grid-template-columns:repeat(3,1fr);'
            . 'gap:4px;margin-top:12px;padding:3px;border-radius:10px;background:#e3ddd3;\';'
            // Knowledge Base / Search tabs on the pre-chat form:
            // tab() takes an optional onclick -- only tabs
            // with a handler become clickable. "Active" styling is applied
            // separately by setTabActive() below so clicking can move it
            // between tabs.
            . '  function tab(labeltext, active, onclick) {'
            . '    var t = document.createElement(\'button\');'
            . '    t.type = \'button\';'
            . '    t.textContent = labeltext;'
            . '    if (!onclick) { t.disabled = true; }'
            . '    t.style.cssText = \'display:inline-flex;align-items:center;justify-content:center;'
            . 'height:32px;border:0;border-radius:8px;background:transparent;font-family:inherit;'
            . 'font-size:13px;color:#7a736a;cursor:\' + (onclick ? \'pointer\' : \'default\') + \';\';'
            . '    if (active) {'
            . '      t.style.background = \'#ffffff\';'
            . '      t.style.fontWeight = \'700\';'
            . '      t.style.color = \'#b2532b\';'
            . '      t.style.boxShadow = \'0 1px 3px rgba(0,0,0,0.08)\';'
            . '    }'
            . '    if (onclick) { t.addEventListener(\'click\', onclick); }'
            . '    return t;'
            . '  }'
            . '  function setTabActive(activetab) {'
            . '    [chattab, articlestab, searchtab].forEach(function(t) {'
            . '      var isactive = (t === activetab);'
            . '      t.style.background = isactive ? \'#ffffff\' : \'transparent\';'
            . '      t.style.fontWeight = isactive ? \'700\' : \'400\';'
            . '      t.style.color = isactive ? \'#b2532b\' : \'#7a736a\';'
            . '      t.style.boxShadow = isactive ? \'0 1px 3px rgba(0,0,0,0.08)\' : \'none\';'
            . '    });'
            . '  }'
            . '  var chattab = tab(strings.tabchat, true, function() { selectTab(\'chat\'); });'
            . '  var articlestab = tab(strings.tabarticles, false, function() { selectTab(\'articles\'); });'
            . '  var searchtab = tab(strings.tabsearch, false, function() { selectTab(\'search\'); });'
            . '  tabsrow.appendChild(chattab);'
            . '  tabsrow.appendChild(articlestab);'
            . '  tabsrow.appendChild(searchtab);'
            . '  header.appendChild(headertop);'
            . '  header.appendChild(statusrow);'
            . '  header.appendChild(tabsrow);'
            . '  var body = document.createElement(\'div\');'
            . '  body.style.cssText = \'flex:1;min-height:0;overflow-y:auto;padding:24px 16px 16px;'
            . 'background:#ffffff;box-sizing:border-box;\';'
            . '  var title = document.createElement(\'div\');'
            . '  title.textContent = strings.formtitle;'
            . '  title.style.cssText = \'font-size:20px;font-weight:700;color:#1a1a1a;\';'
            . '  var subtext = document.createElement(\'div\');'
            . '  subtext.textContent = strings.subtitle;'
            . '  subtext.style.cssText = \'margin:4px 0 0;font-size:14px;color:#7a736a;\';'
            // Hidden until (if ever) the banner fetch below
            // decides to fill it in -- no reserved space, no layout shift,
            // matching the authenticated widget's own "render nothing extra
            // if empty" rule.
            . '  var bannerbox = document.createElement(\'div\');'
            . '  bannerbox.style.cssText = \'display:none;font-size:13px;line-height:1.4;'
            . 'color:#374151;background:#f3f4f6;border-radius:8px;padding:10px 12px;'
            . 'margin:16px 0 0;white-space:pre-line;\';'
            . '  var offlinenote = document.createElement(\'div\');'
            . '  offlinenote.style.cssText = \'display:none;font-size:13px;line-height:1.4;'
            . 'color:#374151;background:#f3f4f6;border-radius:8px;padding:10px 12px;'
            . 'margin:16px 0 0;\';'
            . '  offlinenote.textContent = strings.offlinenote;'
            . '  var form = document.createElement(\'form\');'
            . '  form.style.cssText = \'display:flex;flex-direction:column;margin-top:20px;\';'
            . '  function field(labeltext, inputtag, attrs) {'
            . '    var l = document.createElement(\'label\');'
            . '    l.className = \'lhd-anon-field\';'
            . '    l.style.cssText = \'display:block;\';'
            . '    var span = document.createElement(\'span\');'
            . '    span.textContent = labeltext;'
            . '    span.style.cssText = \'display:block;font-size:12px;font-weight:500;'
            . 'color:#4a453f;margin-bottom:6px;\';'
            . '    var input = document.createElement(inputtag);'
            . '    input.className = \'lhd-anon-input\';'
            . '    for (var k in attrs) { input.setAttribute(k, attrs[k]); }'
            . '    input.style.cssText = \'width:100%;box-sizing:border-box;height:44px;'
            . 'padding:0 12px;border:1px solid #e3ddd3;border-radius:10px;font-size:14px;'
            . 'font-family:inherit;color:#1a1a1a;background:#fff;\';'
            . '    l.appendChild(span);'
            . '    l.appendChild(input);'
            . '    form.appendChild(l);'
            . '    return input;'
            . '  }'
            . '  var firstnameinput = field(strings.firstnamelabel, \'input\', {type: \'text\', name: \'firstname\', '
            . 'required: \'required\', maxlength: \'100\', autocomplete: \'given-name\'});'
            . '  var lastnameinput = field(strings.lastnamelabel, \'input\', {type: \'text\', name: \'lastname\', '
            . 'required: \'required\', maxlength: \'100\', autocomplete: \'family-name\'});'
            . '  var emailinput = field(strings.emaillabel, \'input\', {type: \'email\', name: \'email\', '
            . 'required: \'required\', maxlength: \'320\', autocomplete: \'email\'});'
            // Honeypot: hidden from real visitors via CSS (not `type=hidden`,
            // which some bots skip); left legitimately empty by humans.
            . '  var honeypot = field(\'Website\', \'input\', {type: \'text\', '
            . 'name: \'website\', tabindex: \'-1\', autocomplete: \'off\'});'
            . '  honeypot.parentElement.style.cssText += '
            . '\';position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;margin:0;\';'
            . '  var errorbox = document.createElement(\'div\');'
            . '  errorbox.setAttribute(\'role\', \'alert\');'
            . '  errorbox.style.cssText = \'color:#b2532b;font-size:12px;margin:0 0 10px;'
            . 'text-align:center;display:none;\';'
            . '  var submitbtn = document.createElement(\'button\');'
            . '  submitbtn.type = \'submit\';'
            . '  submitbtn.textContent = isOffline ? strings.submitoffline : strings.submit;'
            . '  submitbtn.className = \'lhd-anon-submit\';'
            . '  submitbtn.style.cssText = \'margin-top:6px;width:100%;height:48px;padding:0 16px;'
            . 'border:0;border-radius:10px;background:#b2532b;color:#ffffff;font-family:inherit;'
            . 'font-size:15px;font-weight:700;cursor:pointer;\';'
            . '  var reassure = document.createElement(\'p\');'
            . '  reassure.textContent = strings.reassure;'
            . '  reassure.style.cssText = \'margin:10px 0 0;font-size:12px;color:#7a736a;'
            . 'text-align:center;\';'
            . '  form.appendChild(errorbox);'
            . '  form.appendChild(submitbtn);'
            . '  form.appendChild(reassure);'
            . '  form.addEventListener(\'submit\', function(ev) {'
            . '    ev.preventDefault();'
            . '    errorbox.style.display = \'none\';'
            . '    submitbtn.disabled = true;'
            . '    submitbtn.textContent = isOffline ? strings.submittingoffline : strings.submitting;'
            . '    var firstname = firstnameinput.value.trim();'
            . '    var lastname = lastnameinput.value.trim();'
            . '    var name = (firstname + \' \' + lastname).replace(/\\s+/g, \' \').trim();'
            . '    var email = emailinput.value.trim();'
            . '    fetch(submitUrl, {'
            . '      method: \'POST\','
            . '      headers: {\'Content-Type\': \'application/json\'},'
            . '      body: JSON.stringify({name: name, email: email, website: honeypot.value})'
            . '    })'
            . '      .then(function(r) { return r.json().then(function(data) { return {ok: r.ok, data: data}; }); })'
            . '      .then(function(result) {'
            . '        if (!result.ok || !result.data || !result.data.access_token) {'
            . '          throw new Error((result.data && result.data.error) || \'request_failed\');'
            . '        }'
            . '        sessionStarted = true;'
            . '        renderWidget(result.data, name, email);'
            . '      })'
            . '      .catch(function() {'
            . '        errorbox.textContent = strings.error;'
            . '        errorbox.style.display = \'block\';'
            . '        submitbtn.disabled = false;'
            . '        submitbtn.textContent = isOffline ? strings.submitoffline : strings.submit;'
            . '      });'
            . '  });'
            // Articles tab on the pre-chat form: a second panel,
            // sibling to the form `body` above, toggled by selectTab(). Kept
            // deliberately simple (no routing, no localStorage) -- just an
            // in-memory list/detail switch, same as the real widget's own
            // Articles tab (WidgetArticlesPanel.tsx).
            . '  var articlespanel = document.createElement(\'div\');'
            . '  articlespanel.style.cssText = \'flex:1;min-height:0;overflow-y:auto;'
            . 'padding:16px;background:#ffffff;box-sizing:border-box;display:none;\';'
            . '  var articlesstatus = document.createElement(\'div\');'
            . '  articlesstatus.style.cssText = \'display:none;font-size:13px;color:#7a736a;'
            . 'text-align:center;padding:24px 0;\';'
            . '  var articleslist = document.createElement(\'div\');'
            . '  articleslist.style.cssText = \'display:flex;flex-direction:column;gap:8px;\';'
            . '  var articledetail = document.createElement(\'div\');'
            . '  articledetail.style.cssText = \'display:none;\';'
            . '  var articlebackbtn = document.createElement(\'button\');'
            . '  articlebackbtn.type = \'button\';'
            . '  articlebackbtn.textContent = strings.articlesback;'
            . '  articlebackbtn.style.cssText = \'display:inline-flex;align-items:center;'
            . 'border:0;background:transparent;color:#b2532b;font-family:inherit;font-size:13px;'
            . 'font-weight:600;cursor:pointer;padding:0 0 12px;\';'
            . '  var articledetailtitle = document.createElement(\'div\');'
            . '  articledetailtitle.style.cssText = \'font-size:16px;font-weight:700;'
            . 'color:#1a1a1a;margin-bottom:8px;\';'
            . '  var articledetailbody = document.createElement(\'div\');'
            . '  articledetailbody.style.cssText = \'font-size:14px;line-height:1.5;'
            . 'color:#374151;overflow-wrap:anywhere;\';'
            . '  articlebackbtn.addEventListener(\'click\', function() { showArticleList(); });'
            . '  articledetail.appendChild(articlebackbtn);'
            . '  articledetail.appendChild(articledetailtitle);'
            . '  articledetail.appendChild(articledetailbody);'
            . '  articlespanel.appendChild(articlesstatus);'
            . '  articlespanel.appendChild(articleslist);'
            . '  articlespanel.appendChild(articledetail);'
            . '  var articlesState = {status: \'idle\', articles: []};'
            . '  function renderArticlesList() {'
            . '    articleslist.innerHTML = \'\';'
            . '    articlesstatus.style.display = \'none\';'
            . '    if (articlesState.status === \'loading\') {'
            . '      articlesstatus.textContent = strings.articlesloading;'
            . '      articlesstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    if (articlesState.status === \'error\') {'
            . '      articlesstatus.textContent = strings.articleserror;'
            . '      articlesstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    if (!articlesState.articles.length) {'
            . '      articlesstatus.textContent = strings.articlesempty;'
            . '      articlesstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    articlesState.articles.forEach(function(article) {'
            . '      var card = document.createElement(\'button\');'
            . '      card.type = \'button\';'
            . '      card.style.cssText = \'display:block;width:100%;text-align:left;'
            . 'border:1px solid #e3ddd3;border-radius:10px;padding:12px;background:#fff;'
            . 'cursor:pointer;font-family:inherit;\';'
            . '      var cardtitle = document.createElement(\'div\');'
            . '      cardtitle.textContent = article.title;'
            . '      cardtitle.style.cssText = \'font-size:14px;font-weight:700;color:#1a1a1a;\';'
            . '      card.appendChild(cardtitle);'
            . '      if (article.excerpt) {'
            . '        var cardexcerpt = document.createElement(\'div\');'
            . '        cardexcerpt.textContent = article.excerpt;'
            . '        cardexcerpt.style.cssText = \'margin-top:4px;font-size:13px;'
            . 'color:#7a736a;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;'
            . '-webkit-box-orient:vertical;\';'
            . '        card.appendChild(cardexcerpt);'
            . '      }'
            . '      card.addEventListener(\'click\', function() { showArticleDetail(article); });'
            . '      articleslist.appendChild(card);'
            . '    });'
            . '  }'
            . '  function showArticleList() {'
            . '    articledetail.style.display = \'none\';'
            . '    articleslist.style.display = \'\';'
            . '    renderArticlesList();'
            . '  }'
            . '  function showArticleDetail(article) {'
            . '    articledetailtitle.textContent = article.title;'
            . '    lhdRenderMarkdown(articledetailbody, article.body);'
            . '    articleslist.style.display = \'none\';'
            . '    articlesstatus.style.display = \'none\';'
            . '    articledetail.style.display = \'\';'
            . '  }'
            // Cached once loaded (articlesState.status !== 'idle') so
            // switching tabs back and forth doesn't refetch -- mirrors the
            // parent-owned cache in the real widget's Articles tab.
            . '  function loadArticles() {'
            . '    if (articlesState.status !== \'idle\') {'
            . '      renderArticlesList();'
            . '      return;'
            . '    }'
            . '    if (!articlesEndpoint) {'
            . '      articlesState = {status: \'error\', articles: []};'
            . '      renderArticlesList();'
            . '      return;'
            . '    }'
            . '    articlesState = {status: \'loading\', articles: []};'
            . '    renderArticlesList();'
            . '    fetch(articlesEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {'
            . '        \'Content-Type\': \'application/json\','
            . '        \'apikey\': bannerAnonKey,'
            . '        \'Authorization\': \'Bearer \' + bannerAnonKey'
            . '      },'
            . '      body: JSON.stringify({p_client_id: bannerClientId})'
            . '    })'
            . '      .then(function(r) { return r.ok ? r.json() : Promise.reject(new Error(\'request_failed\')); })'
            . '      .then(function(rows) {'
            . '        articlesState = {status: \'ready\', articles: rows || []};'
            . '        renderArticlesList();'
            . '      })'
            . '      .catch(function() {'
            . '        articlesState = {status: \'error\', articles: []};'
            . '        renderArticlesList();'
            . '      });'
            . '  }'
            // Search tab: real-time semantic search against the same
            // published, organization-scoped Knowledge Base articles as the
            // tab above, but query-driven instead of a pre-loaded list, so
            // it mirrors the real widget's WidgetKbSearchPanel.tsx instead
            // of the Articles-tab list/detail pattern directly above --
            // debounced input, no caching across queries (each keystroke
            // after the debounce window is its own fresh request), and the
            // query embedding happens server-side via searchEndpoint
            // (the helpdesk app's own /api/public/kb-search route) since the
            // anon key alone can't turn text into an embedding client-side.
            . '  var searchpanel = document.createElement(\'div\');'
            . '  searchpanel.style.cssText = \'flex:1;min-height:0;overflow-y:auto;'
            . 'padding:16px;background:#ffffff;box-sizing:border-box;display:none;\';'
            . '  var searchinput = document.createElement(\'input\');'
            . '  searchinput.type = \'search\';'
            . '  searchinput.placeholder = strings.searchplaceholder;'
            . '  searchinput.setAttribute(\'aria-label\', strings.searchplaceholder);'
            . '  searchinput.maxLength = 500;'
            . '  searchinput.style.cssText = \'width:100%;box-sizing:border-box;height:40px;'
            . 'padding:0 12px;margin-bottom:12px;border:1px solid #e3ddd3;border-radius:10px;'
            . 'font-size:14px;font-family:inherit;color:#1a1a1a;background:#fff;\';'
            . '  var searchstatus = document.createElement(\'div\');'
            . '  searchstatus.style.cssText = \'display:none;font-size:13px;color:#7a736a;'
            . 'text-align:center;padding:24px 0;\';'
            . '  var searchresultslist = document.createElement(\'div\');'
            . '  searchresultslist.style.cssText = \'display:flex;flex-direction:column;gap:8px;\';'
            . '  var searchdetail = document.createElement(\'div\');'
            . '  searchdetail.style.cssText = \'display:none;\';'
            . '  var searchbackbtn = document.createElement(\'button\');'
            . '  searchbackbtn.type = \'button\';'
            . '  searchbackbtn.textContent = strings.searchback;'
            . '  searchbackbtn.style.cssText = \'display:inline-flex;align-items:center;'
            . 'border:0;background:transparent;color:#b2532b;font-family:inherit;font-size:13px;'
            . 'font-weight:600;cursor:pointer;padding:0 0 12px;\';'
            . '  var searchdetailtitle = document.createElement(\'div\');'
            . '  searchdetailtitle.style.cssText = \'font-size:16px;font-weight:700;'
            . 'color:#1a1a1a;margin-bottom:8px;\';'
            . '  var searchdetailbody = document.createElement(\'div\');'
            . '  searchdetailbody.style.cssText = \'font-size:14px;line-height:1.5;'
            . 'color:#374151;overflow-wrap:anywhere;\';'
            . '  searchbackbtn.addEventListener(\'click\', function() { showSearchList(); });'
            . '  searchdetail.appendChild(searchbackbtn);'
            . '  searchdetail.appendChild(searchdetailtitle);'
            . '  searchdetail.appendChild(searchdetailbody);'
            . '  searchpanel.appendChild(searchinput);'
            . '  searchpanel.appendChild(searchstatus);'
            . '  searchpanel.appendChild(searchresultslist);'
            . '  searchpanel.appendChild(searchdetail);'
            . '  var searchState = {status: \'idle\', results: []};'
            . '  var searchDebounceTimer = null;'
            . '  var searchRequestSeq = 0;'
            . '  function renderSearchResults() {'
            . '    searchresultslist.innerHTML = \'\';'
            . '    searchstatus.style.display = \'none\';'
            . '    if (searchState.status === \'idle\') { return; }'
            . '    if (searchState.status === \'loading\') {'
            . '      searchstatus.textContent = strings.searchloading;'
            . '      searchstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    if (searchState.status === \'error\') {'
            . '      searchstatus.textContent = strings.searcherror;'
            . '      searchstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    if (!searchState.results.length) {'
            . '      searchstatus.textContent = strings.searchempty;'
            . '      searchstatus.style.display = \'block\';'
            . '      return;'
            . '    }'
            . '    searchState.results.forEach(function(article) {'
            . '      var card = document.createElement(\'button\');'
            . '      card.type = \'button\';'
            . '      card.style.cssText = \'display:block;width:100%;text-align:left;'
            . 'border:1px solid #e3ddd3;border-radius:10px;padding:12px;background:#fff;'
            . 'cursor:pointer;font-family:inherit;\';'
            . '      var cardtitle = document.createElement(\'div\');'
            . '      cardtitle.textContent = article.title;'
            . '      cardtitle.style.cssText = \'font-size:14px;font-weight:700;color:#1a1a1a;\';'
            . '      card.appendChild(cardtitle);'
            . '      if (article.excerpt) {'
            . '        var cardexcerpt = document.createElement(\'div\');'
            . '        cardexcerpt.textContent = article.excerpt;'
            . '        cardexcerpt.style.cssText = \'margin-top:4px;font-size:13px;'
            . 'color:#7a736a;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;'
            . '-webkit-box-orient:vertical;\';'
            . '        card.appendChild(cardexcerpt);'
            . '      }'
            . '      card.addEventListener(\'click\', function() { showSearchDetail(article); });'
            . '      searchresultslist.appendChild(card);'
            . '    });'
            . '  }'
            . '  function showSearchList() {'
            . '    searchdetail.style.display = \'none\';'
            . '    searchresultslist.style.display = \'\';'
            . '    renderSearchResults();'
            . '  }'
            . '  function showSearchDetail(article) {'
            . '    searchdetailtitle.textContent = article.title;'
            . '    lhdRenderMarkdown(searchdetailbody, article.body);'
            . '    searchresultslist.style.display = \'none\';'
            . '    searchstatus.style.display = \'none\';'
            . '    searchdetail.style.display = \'\';'
            . '  }'
            // Each call supersedes any earlier one in flight (seq guard)
            // rather than queueing/cancelling via AbortController, since
            // fetch() abort support isn't guaranteed on every browser this
            // pre-chat form has to run on.
            . '  function runSearch(query) {'
            . '    var seq = ++searchRequestSeq;'
            . '    if (!searchEndpoint) {'
            . '      searchState = {status: \'error\', results: []};'
            . '      renderSearchResults();'
            . '      return;'
            . '    }'
            . '    searchState = {status: \'loading\', results: []};'
            . '    renderSearchResults();'
            . '    fetch(searchEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {\'Content-Type\': \'application/json\'},'
            . '      body: JSON.stringify({client_id: bannerClientId, query: query})'
            . '    })'
            . '      .then(function(r) { return r.json().then(function(data) { return {ok: r.ok, data: data}; }); })'
            . '      .then(function(result) {'
            . '        if (seq !== searchRequestSeq) { return; }'
            . '        if (!result.ok || !result.data || result.data.error) {'
            . '          searchState = {status: \'error\', results: []};'
            . '          renderSearchResults();'
            . '          return;'
            . '        }'
            . '        searchState = {status: \'ready\', results: result.data.articles || []};'
            . '        renderSearchResults();'
            . '      })'
            . '      .catch(function() {'
            . '        if (seq !== searchRequestSeq) { return; }'
            . '        searchState = {status: \'error\', results: []};'
            . '        renderSearchResults();'
            . '      });'
            . '  }'
            . '  searchinput.addEventListener(\'input\', function() {'
            . '    var value = searchinput.value.trim();'
            . '    if (searchDebounceTimer) { clearTimeout(searchDebounceTimer); }'
            . '    if (value.length < 2) {'
            . '      searchRequestSeq++;'
            . '      searchState = {status: \'idle\', results: []};'
            . '      renderSearchResults();'
            . '      return;'
            . '    }'
            . '    searchDebounceTimer = setTimeout(function() { runSearch(value); }, 350);'
            . '  });'
            . '  function selectTab(name) {'
            . '    if (name === \'chat\') {'
            . '      setTabActive(chattab);'
            . '      body.style.display = \'\';'
            . '      articlespanel.style.display = \'none\';'
            . '      searchpanel.style.display = \'none\';'
            . '      return;'
            . '    }'
            . '    if (name === \'search\') {'
            . '      setTabActive(searchtab);'
            . '      body.style.display = \'none\';'
            . '      articlespanel.style.display = \'none\';'
            . '      searchpanel.style.display = \'\';'
            . '      searchdetail.style.display = \'none\';'
            . '      searchresultslist.style.display = \'\';'
            . '      return;'
            . '    }'
            . '    setTabActive(articlestab);'
            . '    body.style.display = \'none\';'
            . '    articlespanel.style.display = \'\';'
            . '    searchpanel.style.display = \'none\';'
            . '    articledetail.style.display = \'none\';'
            . '    articleslist.style.display = \'\';'
            . '    loadArticles();'
            . '  }'
            . '  body.appendChild(title);'
            . '  body.appendChild(subtext);'
            . '  body.appendChild(bannerbox);'
            . '  body.appendChild(offlinenote);'
            . '  body.appendChild(form);'
            . '  outer.appendChild(header);'
            . '  outer.appendChild(body);'
            . '  outer.appendChild(articlespanel);'
            . '  outer.appendChild(searchpanel);'
            . '  panel.appendChild(outer);'
            . '  firstnameinput.focus();'
            // Agent availability: fail-open (keeps the default "Online"
            // look if the check is unavailable), re-checked every 60 s while
            // the form is on screen and the panel is open.
            . '  function setPresence(online) {'
            . '    statusdot.style.background = online ? \'#2e9e55\' : \'#a8a29e\';'
            . '    statustext.textContent = online ? strings.onlinestatus : strings.offlinestatus;'
            . '    offlinenote.style.display = online ? \'none\' : \'block\';'
            // Offline wording (no "chat" promise). Do not touch the submit
            // button while a request is in flight (it is disabled then).
            . '    isOffline = !online;'
            . '    chattab.textContent = online ? strings.tabchat : strings.tabchatoffline;'
            . '    closebtn.setAttribute(\'aria-label\', online ? strings.minimize : strings.minimizeoffline);'
            . '    if (!submitbtn.disabled) { submitbtn.textContent = online ? strings.submit : strings.submitoffline; }'
            . '  }'
            . '  function checkPresence(force) {'
            . '    if (!statusdot.isConnected) { clearInterval(presenceTimer); presenceTimer = null; return; }'
            . '    if (!panelActive && force !== true) { return; }'
            . '    fetch(presenceEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {\'Content-Type\': \'application/json\', \'apikey\': '
            . 'bannerAnonKey, \'Authorization\': \'Bearer \' + bannerAnonKey},'
            . '      body: JSON.stringify({p_client_id: bannerClientId})'
            . '    })'
            . '      .then(function(r) { return r.ok ? r.json() : null; })'
            . '      .then(function(v) { if (v === true || v === false) { setPresence(v); } })'
            . '      .catch(function() { /* best-effort: keep current look */ });'
            . '  }'
            . '  if (presenceEndpoint) {'
            . '    checkPresence(true);'
            . '    if (presenceTimer) { clearInterval(presenceTimer); }'
            . '    presenceTimer = setInterval(checkPresence, 60000);'
            . '  }'
            . '  if (bannerEndpoint) {'
            . '    fetch(bannerEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {'
            . '        \'Content-Type\': \'application/json\','
            . '        \'apikey\': bannerAnonKey,'
            . '        \'Authorization\': \'Bearer \' + bannerAnonKey'
            . '      },'
            . '      body: JSON.stringify({p_client_id: bannerClientId})'
            . '    })'
            . '      .then(function(r) { return r.ok ? r.json() : null; })'
            . '      .then(function(rows) {'
            . '        var row = rows && rows[0];'
            . '        if (row && row.show_banner && row.banner_text) {'
            . '          bannerbox.textContent = row.banner_text;'
            . '          bannerbox.style.display = \'block\';'
            . '        }'
            . '      })'
            // Best-effort, exactly like build_tracking_js()'s page-view
            // pings: a failed/blocked banner check must never stop the
            // contact form itself from working.
            . '      .catch(function() { /* best-effort: banner is decorative only */ });'
            . '  }'
            // Admin-controlled tabs. Fail-open: any problem (no endpoint,
            // network error, bad response, slow reply) leaves all three
            // tabs exactly as before. The tab row keeps its space but is
            // invisible for at most 1.5 s while the status loads, so tabs
            // do not flash on and off. The result is cached for the page
            // so re-opening the form does not re-fetch.
            . '  function applyTabsStatus(st) {'
            . '    if (!st || tabsApplied) { return; }'
            . '    tabsApplied = true;'
            . '    var kbon = st.kb_enabled !== false;'
            . '    var searchon = st.search_enabled !== false;'
            . '    if (!kbon) { articlestab.style.display = \'none\'; }'
            . '    if (!searchon) { searchtab.style.display = \'none\'; }'
            . '    var count = 1 + (kbon ? 1 : 0) + (searchon ? 1 : 0);'
            . '    if (count === 1) {'
            . '      tabsrow.style.display = \'none\';'
            . '    } else {'
            . '      tabsrow.style.gridTemplateColumns = \'repeat(\' + count + \',1fr)\';'
            . '    }'
            . '    if ((!kbon && articlespanel.style.display !== \'none\') ||'
            . '        (!searchon && searchpanel.style.display !== \'none\')) {'
            . '      selectTab(\'chat\');'
            . '    }'
            . '  }'
            . '  function revealTabs() { tabsrow.style.visibility = \'visible\'; }'
            . '  var tabsApplied = false;'
            . '  if (tabsStatus) {'
            . '    applyTabsStatus(tabsStatus);'
            . '  } else if (tabsEndpoint) {'
            . '    tabsrow.style.visibility = \'hidden\';'
            . '    var tabsRevealTimer = setTimeout(revealTabs, 1500);'
            . '    fetch(tabsEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {'
            . '        \'Content-Type\': \'application/json\','
            . '        \'apikey\': bannerAnonKey,'
            . '        \'Authorization\': \'Bearer \' + bannerAnonKey'
            . '      },'
            . '      body: JSON.stringify({p_client_id: bannerClientId})'
            . '    })'
            . '      .then(function(r) { return r.ok ? r.json() : null; })'
            . '      .then(function(st) {'
            . '        clearTimeout(tabsRevealTimer);'
            . '        if (st && typeof st === \'object\' && !Array.isArray(st)) {'
            . '          tabsStatus = st;'
            . '          if (tabsrow.isConnected) { applyTabsStatus(st); }'
            . '        }'
            . '        revealTabs();'
            . '      })'
            . '      .catch(function() { clearTimeout(tabsRevealTimer); revealTabs(); });'
            . '  }'
            . '}'
            . 'function renderWidget(sessiondata, name, email) {'
            . '  panel.innerHTML = \'\';'
            . '  saveSession({'
            . '    access_token: sessiondata.access_token,'
            . '    expires_at: sessiondata.expires_at,'
            . '    user_id: sessiondata.user_id,'
            . '    helpdesk_base_url: sessiondata.helpdesk_base_url,'
            . '    name: name,'
            . '    email: email'
            . '  });'
            . '  var baseurl = String(sessiondata.helpdesk_base_url).replace(/\\/$/, \'\');'
            . '  var baseurlobj;'
            . '  try { baseurlobj = new URL(baseurl); } catch (e) { baseurlobj = null; }'
            . '  var helpdeskOrigin = baseurlobj ? baseurlobj.origin : baseurl;'
            . '  var fragmentparams = new URLSearchParams();'
            . '  fragmentparams.set(\'access_token\', sessiondata.access_token);'
            . '  fragmentparams.set(\'expires_at\', sessiondata.expires_at);'
            . '  fragmentparams.set(\'user_id\', sessiondata.user_id);'
            . '  fragmentparams.set(\'activity_context\', \'login_page_form\');'
            . '  fragmentparams.set(\'visitor_key\', getVisitorKey());'
            . '  fragmentparams.set(\'client_id\', bannerClientId);'
            . '  if (pageTitle) { fragmentparams.set(\'page_title\', pageTitle); }'
            . '  if (pageUrl) { fragmentparams.set(\'page_url\', pageUrl); }'
            . '  var widgeturl = baseurl + \'/widget#\' + fragmentparams.toString();'
            . '  var iframe = document.createElement(\'iframe\');'
            . '  iframe.id = \'local-chronifysupport-anon-widget-frame\';'
            . '  iframe.src = widgeturl;'
            . '  iframe.title = label;'
            . '  iframe.referrerPolicy = \'no-referrer\';'
            . '  iframe.setAttribute(\'sandbox\', \'allow-scripts allow-same-origin allow-forms allow-popups\');'
            // Same instant, branded loading indicator as the signed-in flow:
            // shown behind the frame until it has loaded (or after a safety
            // timeout). Text via textContent only.
            . '  var loader = document.createElement(\'div\');'
            . '  loader.setAttribute(\'role\', \'status\');'
            . '  loader.setAttribute(\'aria-live\', \'polite\');'
            . '  loader.style.cssText = \'position:absolute;top:0;left:0;right:0;bottom:0;display:flex;flex-direction:column;'
            . 'align-items:center;justify-content:center;gap:14px;padding:24px;text-align:center;background:#fff;color:#6b7280;'
            . 'font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;\';'
            . '  loader.innerHTML = ' . self::js_value(self::loader_svg()) . ';'
            . '  var loaderText = document.createElement(\'div\');'
            . '  loaderText.textContent = ' . self::js_value($loadingtext) . ';'
            . '  loader.appendChild(loaderText);'
            . '  var loaderSlow = setTimeout(function() { loaderText.textContent = '
            . self::js_value($loadingslowtext !== '' ? $loadingslowtext : $loadingtext)
            . '; }, 8000);'
            . '  function dropLoader() { clearTimeout(loaderSlow); if '
            . '(loader.parentNode) { loader.parentNode.removeChild(loader); } }'
            . '  iframe.style.cssText = \'position:relative;z-index:1;width:100%;height:100%;border:0;display:block;\';'
            . '  iframe.addEventListener(\'load\', dropLoader);'
            . '  setTimeout(dropLoader, 20000);'
            . '  widgetFrame = iframe;'
            . '  widgetOrigin = helpdeskOrigin;'
            . '  panel.appendChild(loader);'
            . '  panel.appendChild(iframe);'
            // The widget iframe here is a direct child of our own panel (no
            // passthrough page in between, unlike the authenticated flow),
            // so we can shrink/restore the panel straight from its signal.
            . '  window.addEventListener(\'message\', function(event) {'
            . '    if (event.origin !== helpdeskOrigin || event.source !== iframe.contentWindow) { return; }'
            . '    if (!event.data) { return; }'
            . '    if ((event.data.type === \'helpdesk:conversation_id\' || event.data.type '
            . '=== \'helpdesk:conversation_started\') && event.data.conversation_id) {'
            . '      saveSession({conversation_id: event.data.conversation_id});'
            . '      return;'
            . '    }'
            . '    if (event.data.type === \'helpdesk:minimize\') {'
            . '      hidePanel();'
            . '    } else if (event.data.type === \'helpdesk:restore\') {'
            . '      showPanel();'
            . '    }'
            . '  });'
            . '  function scheduleRefresh(expiresAtIso) {'
            . '    var expiresAt = new Date(expiresAtIso).getTime();'
            . '    var delay = Math.max(60000, (expiresAt - Date.now()) * 0.8);'
            . '    setTimeout(doRefresh, delay);'
            . '  }'
            . '  function doRefresh() {'
            . '    fetch(refreshUrl, {'
            . '      method: \'POST\','
            . '      headers: {\'Content-Type\': \'application/json\'},'
            . '      body: JSON.stringify({name: name, email: email})'
            . '    })'
            . '      .then(function(r) { return r.json(); })'
            . '      .then(function(data) {'
            . '        if (data && data.access_token) {'
            . '          iframe.contentWindow.postMessage({'
            . '            type: \'helpdesk:token_refresh\','
            . '            access_token: data.access_token,'
            . '            expires_at: data.expires_at'
            . '          }, helpdeskOrigin);'
            . '          saveSession({access_token: data.access_token, expires_at: data.expires_at});'
            . '          scheduleRefresh(data.expires_at);'
            . '        }'
            . '      })'
            . '      .catch(function() { /* best-effort: widget just keeps its current token until natural expiry */ });'
            . '  }'
            . '  scheduleRefresh(sessiondata.expires_at);'
            . '}'
            . 'function hidePanel() {'
            . '  if (panel) { panel.style.transform = \'translateX(100%)\'; }'
            . '  pushBody(false);'
            . '  btn.style.display = \'\';'
            . '  setPanelOpen(false);'
            . '  panelActive = false;'
            . '}'
            . 'function showPanel() {'
            . '  if (!panel) {'
            . '    panel = document.createElement(\'div\');'
            . '    panel.style.cssText = \'position:fixed;top:0;right:0;height:100vh;height:100dvh;'
            . 'width:\' + panelWidth + \';max-width:100vw;z-index:2147483000;overflow:hidden;'
            . 'background:#fff;border-left:1px solid #e5e7eb;'
            . 'box-shadow:-2px 0 16px rgba(0,0,0,0.08);'
            . 'transition:transform .2s ease;transform:translateX(0);\';'
            . '    document.body.appendChild(panel);'
            . '    var stored = getStoredSession();'
            . '    if (stored) {'
            . '      sessionStarted = true;'
            . '      renderWidget(stored, stored.name, stored.email);'
            . '    } else {'
            . '      renderForm();'
            . '    }'
            . '  } else {'
            . '    panel.style.transform = \'translateX(0)\';'
            . '    void panel.offsetHeight;'
            // Reopening from the host button: tell the widget it is visible
            // again. Otherwise a widget that was minimized from inside keeps
            // its body hidden and shows an empty panel until a page reload.
            . '    if (widgetFrame && widgetFrame.contentWindow && widgetOrigin) {'
            . '      try { widgetFrame.contentWindow.postMessage({ type: \'helpdesk:restore\' }, widgetOrigin); }'
            . '      catch (e) { /* best-effort */ }'
            . '    }'
            . '  }'
            . '  pushBody(true);'
            . '  btn.style.display = \'none\';'
            . '  setPanelOpen(true);'
            . '  panelActive = true;'
            . '}'
            . 'btn.addEventListener(\'click\', function() { showPanel(); });'
            . 'try {'
            . '  if (window.sessionStorage.getItem(panelStorageKey) === \'1\') { showPanel(); }'
            . '} catch (e) { /* best-effort: panel just starts closed like before */ }'
            . '})();';
    }

    /**
     * Cosmetic "chat in progress" state for the floating button: while the
     * widget reports an active chat (helpdesk:chat_state), the label reads
     * "Continue chat" with a small green dot; otherwise the original label
     * is restored. The last known state is kept per user in localStorage
     * (and treated as stale after 24 h) so the next page shows it at once.
     * Messages are only accepted from one of this page's own iframes and
     * only from this site or the helpdesk app origin. Entirely best-effort:
     * any failure leaves the button exactly as it was.
     *
     * @param string $activelabel Button label while a chat is active.
     * @param string $storagekey localStorage key for the last known state.
     * @param string $helpdeskorigin Origin of the help desk app, for postMessage checks.
     * @param string $offlinelabel Label shown when the team is offline.
     * @param string $presenceendpoint Supabase project URL for the availability lookup.
     * @param string $presenceanonkey Supabase anon key for the availability lookup.
     * @param string $presenceclientid Client ID of this installation.
     * @return string The script source.
     */
    protected static function build_chat_state_js(
        string $activelabel,
        string $storagekey,
        string $helpdeskorigin,
        string $offlinelabel = '',
        string $presenceendpoint = '',
        string $presenceanonkey = '',
        string $presenceclientid = ''
    ): string {
        return '(function() {'
            . 'var btn = document.getElementById(\'local_chronifysupport_get_help_button\');'
            . 'if (!btn) { return; }'
            . 'var activeLabel = ' . self::js_value($activelabel) . ';'
            . 'var offlineLabel = ' . self::js_value($offlinelabel !== '' ? $offlinelabel : $activelabel) . ';'
            . 'var presenceEndpoint = ' . self::js_value($presenceendpoint) . ';'
            . 'var presenceKey = ' . self::js_value($presenceanonkey) . ';'
            . 'var presenceClientId = ' . self::js_value($presenceclientid) . ';'
            . 'var agentsOffline = false;'
            . 'var lastPresenceCheck = 0;'
            . 'var storageKey = ' . self::js_value($storagekey) . ';'
            . 'var allowedOrigins = [window.location.origin, ' . self::js_value($helpdeskorigin) . '];'
            . 'var originalLabel = btn.textContent;'
            . 'var currentActive = false;'
            . 'var renderedOffline = false;'
            . 'var reported = false;'
            . 'function render() {'
            . '  renderedOffline = agentsOffline;'
            . '  while (btn.firstChild) { btn.removeChild(btn.firstChild); }'
            . '  if (currentActive) {'
            // Green "live" dot only while agents are online; offline shows
            // the neutral "Continue conversation" wording without it.
            . '    if (!agentsOffline) {'
            . '      var dot = document.createElement(\'span\');'
            . '      dot.setAttribute(\'aria-hidden\', \'true\');'
            . '      dot.style.cssText = \'display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;'
            . 'margin-right:8px;vertical-align:middle;position:relative;top:-1px;box-shadow:0 0 0 2px rgba(255,255,255,0.35);\';'
            . '      btn.appendChild(dot);'
            . '    }'
            . '    btn.appendChild(document.createTextNode(agentsOffline ? offlineLabel : activeLabel));'
            . '  } else {'
            . '    btn.appendChild(document.createTextNode(originalLabel));'
            . '  }'
            . '}'
            // Best-effort presence lookup, only while a conversation is in
            // progress and at most once a minute. Fail-open: any problem
            // keeps the online wording.
            . 'function checkPresence() {'
            . '  if (!presenceEndpoint || !currentActive) { return; }'
            . '  var now = Date.now();'
            . '  if (now - lastPresenceCheck < 60000) { return; }'
            . '  lastPresenceCheck = now;'
            . '  try {'
            . '    fetch(presenceEndpoint, {'
            . '      method: \'POST\','
            . '      headers: {\'Content-Type\': \'application/json\', \'apikey\': '
            . 'presenceKey, \'Authorization\': \'Bearer \' + presenceKey},'
            . '      body: JSON.stringify({p_client_id: presenceClientId})'
            . '    })'
            . '      .then(function(r) { return r.ok ? r.json() : null; })'
            . '      .then(function(v) {'
            . '        if (v === true || v === false) {'
            . '          agentsOffline = (v === false);'
            . '          if (currentActive && agentsOffline !== renderedOffline) { render(); }'
            . '        }'
            . '      })'
            . '      .catch(function() { /* best-effort */ });'
            . '  } catch (e) { /* best-effort */ }'
            . '}'
            . 'function apply(active) {'
            . '  if (active === currentActive) { return; }'
            . '  currentActive = active;'
            . '  render();'
            . '  if (active) { checkPresence(); }'
            . '}'
            . 'function readStored() {'
            . '  try {'
            . '    var v = JSON.parse(window.localStorage.getItem(storageKey));'
            . '    return !!(v && v.a === 1 && v.t && (Date.now() - v.t) < 86400000);'
            . '  } catch (e) { return false; }'
            . '}'
            . 'function store(active) {'
            . '  try {'
            . '    if (active) { window.localStorage.setItem(storageKey, JSON.stringify({a: 1, t: Date.now()})); }'
            . '    else { window.localStorage.removeItem(storageKey); }'
            . '  } catch (e) { /* best-effort */ }'
            . '}'
            . 'apply(readStored());'
            . 'window.addEventListener(\'message\', function(event) {'
            . '  var d = event.data;'
            . '  if (!d || d.type !== \'helpdesk:chat_state\' || typeof d.active !== \'boolean\') { return; }'
            . '  if (allowedOrigins.indexOf(event.origin) === -1) { return; }'
            . '  var frames = document.getElementsByTagName(\'iframe\');'
            . '  var ours = false;'
            . '  for (var i = 0; i < frames.length; i++) { if (frames[i].contentWindow === event.source) { ours = true; break; } }'
            . '  if (!ours) { return; }'
            . '  reported = true;'
            . '  store(d.active);'
            . '  apply(d.active);'
            . '});'
            // Re-read the stored state once the page has fully loaded, so the
            // label never depends on the order in which inline scripts ran (the
            // stored state may be cleared by the logged-out reset script after
            // this one first read it). Skipped when the widget has already
            // reported its own state, so a live report is never overridden.
            . 'function resync() { if (!reported) { apply(readStored()); } }'
            . 'if (document.readyState === \'complete\') { resync(); }'
            . 'else { window.addEventListener(\'load\', resync); }'
            // Keep other open tabs of the same site in sync.
            . 'window.addEventListener(\'storage\', function(event) {'
            . '  if (event.key === storageKey) { apply(readStored()); }'
            . '});'
            . '})();';
    }

    /**
     * Minimal, dependency-free Markdown renderer for the pre-chat form's
     * Knowledge Base panels (headings, lists, bold/italic/strike, inline and
     * fenced code, quotes, rules and http/https links). Builds DOM nodes
     * only (never innerHTML), so article text can never inject markup.
     *
     * @return string JavaScript source defining lhdRenderMarkdown(target, text)
     */
    protected static function markdown_renderer_js(): string {
        return <<<'LHDJS'
function lhdRenderMarkdown(target, source) {
  while (target.firstChild) { target.removeChild(target.firstChild); }
  var lines = String(source == null ? '' : source).replace(/\r\n?/g, '\n').split('\n');
  function el(tag, css) {
    var e = document.createElement(tag);
    if (css) { e.style.cssText = css; }
    return e;
  }
  function inline(parent, text) {
    var re = /(\*\*|__)(.+?)\1|~~(.+?)~~|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|(\*|_)(\S|\S.*?\S)\7/g;
    var last = 0, m;
    while ((m = re.exec(text)) !== null) {
      var before = m.index > 0 ? text.charAt(m.index - 1) : '';
      var after = text.charAt(re.lastIndex);
      var word = /[A-Za-z0-9]/;
      if ((m[1] === '__' || m[7]) && ((before && word.test(before)) || (after && word.test(after)))) {
        re.lastIndex = m.index + 1;
        continue;
      }
      if (m.index > last) { parent.appendChild(document.createTextNode(text.slice(last, m.index))); }
      var node;
      if (m[1]) {
        node = el('strong', 'font-weight:700;'); inline(node, m[2]);
      } else if (m[3] !== undefined) {
        node = el('del'); inline(node, m[3]);
      } else if (m[4] !== undefined) {
        node = el('code', 'font-family:ui-monospace,Menlo,Consolas,monospace;font-size:0.9em;'
          + 'background:#f3f4f6;border-radius:4px;padding:1px 4px;');
        node.textContent = m[4];
      } else if (m[5] !== undefined) {
        if (/^https?:\/\//i.test(m[6])) {
          node = el('a', 'color:#b5532c;font-weight:500;text-decoration:underline;');
          node.href = m[6]; node.target = '_blank'; node.rel = 'noopener noreferrer';
          inline(node, m[5]);
        } else {
          node = document.createTextNode(m[5]);
        }
      } else {
        node = el('em', 'font-style:italic;'); inline(node, m[8]);
      }
      parent.appendChild(node);
      last = re.lastIndex;
    }
    if (last < text.length) { parent.appendChild(document.createTextNode(text.slice(last))); }
  }
  var i = 0, para = [];
  function flushPara() {
    if (!para.length) { return; }
    var p = el('p', 'margin:0 0 10px;');
    for (var k = 0; k < para.length; k++) {
      if (k) { p.appendChild(document.createElement('br')); }
      inline(p, para[k]);
    }
    target.appendChild(p);
    para = [];
  }
  while (i < lines.length) {
    var line = lines[i], m;
    if (/^\s*$/.test(line)) { flushPara(); i++; continue; }
    if ((m = /^```/.exec(line))) {
      flushPara();
      var code = [];
      i++;
      while (i < lines.length && !/^```/.test(lines[i])) { code.push(lines[i]); i++; }
      i++;
      var pre = el('pre', 'margin:0 0 10px;padding:10px;background:#f3f4f6;border-radius:6px;'
        + 'overflow-x:auto;font-size:0.9em;white-space:pre;');
      var c = document.createElement('code');
      c.textContent = code.join('\n');
      pre.appendChild(c);
      target.appendChild(pre);
      continue;
    }
    if ((m = /^(#{1,4})\s+(.*?)\s*#*\s*$/.exec(line))) {
      flushPara();
      var level = m[1].length;
      var sizes = {1: '20px', 2: '17px', 3: '15px', 4: '14px'};
      var h = el('h' + (level + 1 > 6 ? 6 : level + 1),
        'margin:14px 0 6px;font-size:' + sizes[level] + ';font-weight:700;color:#1a1a1a;line-height:1.3;');
      inline(h, m[2]);
      target.appendChild(h);
      i++; continue;
    }
    if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
      flushPara();
      target.appendChild(el('hr', 'border:0;border-top:1px solid #e5e7eb;margin:12px 0;'));
      i++; continue;
    }
    if (/^\s*>\s?/.test(line)) {
      flushPara();
      var q = [];
      while (i < lines.length && /^\s*>\s?/.test(lines[i])) { q.push(lines[i].replace(/^\s*>\s?/, '')); i++; }
      var bq = el('blockquote', 'margin:0 0 10px;padding-left:10px;border-left:3px solid #e3ddd3;color:#6b7280;');
      inline(bq, q.join(' '));
      target.appendChild(bq);
      continue;
    }
    var lm = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(line);
    if (lm) {
      flushPara();
      var ordered = /\d/.test(lm[2]);
      var list = el(ordered ? 'ol' : 'ul', 'margin:0 0 10px;padding-left:22px;');
      while (i < lines.length) {
        var im = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/.exec(lines[i]);
        if (!im || /\d/.test(im[2]) !== ordered) { break; }
        var li = el('li', 'margin:3px 0;');
        var text = im[3];
        i++;
        while (i < lines.length && /^\s{2,}\S/.test(lines[i]) && !/^\s*([-*+]|\d+[.)])\s+/.test(lines[i])) {
          text += ' ' + lines[i].trim(); i++;
        }
        inline(li, text);
        list.appendChild(li);
      }
      target.appendChild(list);
      continue;
    }
    para.push(line.replace(/\s+$/, ''));
    i++;
  }
  flushPara();
}
LHDJS;
    }

    /**
     * Encodes a PHP value as a JavaScript literal that is safe to place inside
     * an inline <script> block. In addition to normal JSON escaping, the
     * characters < > & ' and " are written as \\uXXXX escapes, so a value
     * (for example a page title) can never close the script element or break
     * out of its string literal. The resulting JavaScript value is identical.
     *
     * @param mixed $value
     * @return string
     */
    public static function js_value($value): string {
        $encoded = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $encoded === false ? 'null' : $encoded;
    }

    /**
     * Language string with a built-in English fallback. During a plugin
     * upgrade Moodle still renders pages (e.g. the upgrade screen footer)
     * with the OLD cached language file while the NEW code is already
     * running; asking get_string() for a string that is not in that cache
     * yet prints a debugging notice. string_exists() avoids that.
     *
     * @param string $identifier String identifier in this plugin.
     * @param string $fallback English text used when the string is not available.
     * @return string
     */
    public static function string_or(string $identifier, string $fallback): string {
        if (get_string_manager()->string_exists($identifier, 'local_chronifysupport')) {
            return get_string($identifier, 'local_chronifysupport');
        }
        return $fallback;
    }

    /**
     * Inline SVG used by every "connecting to support" loading indicator (the
     * host-page panel and get_help.php), so the hand-over between them is
     * seamless. A brand-coloured arc spins around a chat-bubble icon (SMIL
     * animation, no CSS/JS needed). Static markup only: no user data.
     */
    public static function loader_svg(): string {
        return '<svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" '
            . 'aria-hidden="true" focusable="false">'
            . '<circle cx="32" cy="32" r="27" fill="none" stroke="#ece7e3" stroke-width="4"/>'
            . '<circle cx="32" cy="32" r="27" fill="none" stroke="#b5532c" '
            . 'stroke-width="4" stroke-linecap="round" stroke-dasharray="46 124">'
            . '<animateTransform attributeName="transform" type="rotate" from="0 '
            . '32 32" to="360 32 32" dur="1.1s" repeatCount="indefinite"/>'
            . '</circle>'
            . '<path d="M22 24h20a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H33l-6 5v-5h-5a4 4 0 0 1-4-4V28a4 4 0 0 1 4-4z" fill="#b5532c"/>'
            . '</svg>';
    }
}
