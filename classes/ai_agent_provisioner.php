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
 * Automatic provisioning of the "AI Agent" course-completion-check webservice.
 *
 * Replaces steps 1-5 of the Moodle AI Agent setup guide (enable
 * webservices, enable REST, create an External service with the 3 required
 * functions, create a minimal-privilege service account, generate a token)
 * with a single idempotent routine that db/install.php and db/upgrade.php
 * call automatically. Step 6 (paste the resulting token into the Chronify.Support
 * app) and step 7 (make sure base_url is reachable from the internet) stay
 * manual — see settings.php for where the token is surfaced to the admin.
 *
 * ensure_provisioned() is safe to call repeatedly (every upgrade run): each
 * step first checks whether the thing it would create already exists.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

defined('MOODLE_INTERNAL') || die();

/**
 * Sets up and maintains the optional AI Agent web service (service, role, service account, token).
 */
class ai_agent_provisioner {
    /** @var string Shortname of the External service created for the AI Agent. */
    const SERVICE_SHORTNAME = 'local_chronifysupport_ai_agent';

    /** @var string Shortname of the system role assigned to the service account. */
    const ROLE_SHORTNAME = 'local_chronifysupport_ai_agent';

    /** @var string Username of the dedicated service account. */
    const SERVICE_USERNAME = 'chronifysupport-ai-service';

    /** Display name of the external service. */
    const SERVICE_NAME = 'Chronify.Support AI Agent';

    /** Display name of the dedicated role. */
    const ROLE_NAME = 'Chronify.Support AI Agent';

    /** First/last name of the service account (also the placeholder names uninstall matches on). */
    const SERVICE_FIRSTNAME = 'Chronify.Support';

    /** @var string Last name of the service account. */
    const SERVICE_LASTNAME = 'AI Agent';

    /**
     * @var string[] Web service functions attached to the service unconditionally: the original
     *               three plus later core and report additions that exist on every site.
     */
    const FUNCTIONS = [
        'core_user_get_users_by_field',
        'core_enrol_get_users_courses',
        'core_completion_get_course_completion_status',
        // Returns a user's grade items (and grades) for a single course.
        // gradereport_user ships as a standard report plugin with every
        // Moodle distribution — it's not a separately-installed plugin
        // like mod_customcert/mod_certificate below — so, like the three
        // functions above, it's safe to attach unconditionally rather
        // than going through the OPTIONAL_FUNCTIONS existence-check
        // mechanism. Added in 0.2.6.
        'gradereport_user_get_grade_items',
        // Lists a course's sections/activities, including each activity's
        // modname (e.g. "customcert", "certificate") and instance id.
        // The Chronify.Support AI agent needs this as the first step of certificate lookup: the
        // two OPTIONAL_FUNCTIONS below (mod_customcert_get_issued_certificates
        // / mod_certificate_get_issued_certificates) both key off an
        // *activity instance* id, not a course id, so the only way to find
        // whether a given course even contains a certificate activity — and
        // which one — is to list its contents first. core_course_get_contents
        // is core itself (unlike the two OPTIONAL_FUNCTIONS, it exists on
        // every Moodle site regardless of which, if any, certificate plugin
        // is installed), so it's safe to attach unconditionally here rather
        // than through the OPTIONAL_FUNCTIONS existence-check mechanism —
        // same reasoning as core_enrol_get_users_courses/gradereport_user_
        // get_grade_items above. Confirmed missing live: before this was
        // added, calling it returned "Access to the function
        // core_course_get_contents() is not allowed" (webservice_access_
        // exception/accessexception) even though the function itself exists
        // in Moodle core — it simply wasn't attached to our service, which
        // would have silently blocked every certificate lookup the AI agent
        // attempts, on any site, regardless of whether that site actually
        // has a certificate plugin installed. Added in 0.2.9.
        'core_course_get_contents',
        // Full-text search across the site's entire course catalog (fullname/
        // shortname/summary), not just courses the student is already
        // enrolled in. Added because every function above it only works once
        // a course is already known — core_enrol_get_users_courses only ever
        // returns courses the student is enrolled in, and
        // core_course_get_contents needs a course id you must already have.
        // Without this, a student mentioning a real course by name that
        // they're not yet enrolled in is indistinguishable to the AI Agent
        // from one making up a course that doesn't exist — there was
        // genuinely no function available to check. Core itself (like
        // core_course_get_contents above), so safe to attach unconditionally.
        // Respects normal course visibility rules via the existing
        // moodle/course:view capability already granted to this role in
        // 0.2.8 — hidden courses still won't surface here. Added in 0.2.13.
        'core_course_search_courses',
        // Lists a course's assignment activities (name, due date, allow-
        // submissions-from date, cutoff date) across one or more courses.
        // A frequent helpdesk question is "when is my assignment due" —
        // this is the definition-level companion to
        // mod_assign_get_submission_status below, which answers "did I
        // actually submit it". mod_assign ships with every Moodle
        // distribution as a core activity module (unlike mod_customcert/
        // mod_certificate in OPTIONAL_FUNCTIONS below), so — same reasoning
        // as core_course_get_contents/core_course_search_courses above —
        // safe to attach unconditionally. Needs mod/assign:view on each
        // course module context; see CAPABILITIES. Not yet called by
        // anything — attached ahead of need, same as core_course_search_
        // courses was before 0.2.13 wired it up. Added in 0.2.14.
        'mod_assign_get_assignments',
        // Answers "did I submit assignment X, and what's its status"
        // (draft / submitted / graded / reopened) for a specific, named
        // student — takes an explicit userid parameter, unlike
        // get_assignments above. Viewing another user's (not the caller's
        // own) submission needs mod/assign:viewgrades; see CAPABILITIES.
        // Added in 0.2.14.
        'mod_assign_get_submission_status',
        // Lists a course's quiz activities (name, time limit, number of
        // allowed attempts) — definition-level companion to
        // mod_quiz_get_user_attempts below, same pairing as the two
        // mod_assign functions above. Core bundled activity module, safe
        // to attach unconditionally. Needs mod/quiz:view. Added in 0.2.14.
        'mod_quiz_get_quizzes_by_courses',
        // Answers "how many times have I attempted this quiz, and what's
        // the state of each attempt" for a specific student — explicit
        // userid parameter. Viewing another user's attempts (rather than
        // only the caller's own) needs mod/quiz:viewreports — this exact
        // capability name is a best-effort guess pending live
        // confirmation, same caveat already flagged on the
        // mod_certificate_get_issued_certificates entry in
        // OPTIONAL_FUNCTIONS below; narrow down or correct if real-world
        // testing shows otherwise. Added in 0.2.14.
        'mod_quiz_get_user_attempts',
        // Per-activity completion status within a course (which specific
        // activities are/aren't marked complete for a given student),
        // unlike core_completion_get_course_completion_status above which
        // only reports the course as a single whole. Answers "why isn't my
        // course marked complete" with the actual missing activity instead
        // of just a yes/no. Takes an explicit userid parameter. Expected
        // to be covered by the moodle/course:view + moodle/course:
        // viewparticipants + report/completion:view capabilities already
        // granted below for the course-level function, but flagged for
        // live re-verification — Moodle's completion API has a history of
        // enforcing capabilities inconsistently across its different entry
        // points, per the course-level function's own capability trail
        // above (0.2.4/0.2.5/0.2.8). Added in 0.2.14.
        'core_completion_get_activities_completion_status',
        // Lists a forum's discussions (topics, not individual posts) —
        // listing-level companion to mod_forum_get_discussion_posts below.
        // Useful if a student's ticket references "my question on the
        // course forum". Core bundled activity module. Needs mod/forum:
        // viewdiscussion. Added in 0.2.14.
        'mod_forum_get_forum_discussions',
        // Lists the actual posts inside one forum discussion thread, once
        // its id is known from get_forum_discussions above. Same
        // capability, mod/forum:viewdiscussion. Added in 0.2.14.
        'mod_forum_get_discussion_posts',
        // Lists badges a specific student has earned (or not). Badges is a
        // core Moodle feature (admin/badges), not a separately-installed
        // plugin like mod_customcert/mod_certificate, so safe to attach
        // unconditionally even on a site that has the feature disabled
        // site-wide (returns an empty result there, same shape as a site
        // with no matches for core_course_search_courses above). Takes an
        // explicit userid parameter. Viewing another user's badges needs
        // moodle/badges:viewotherbadges; see CAPABILITIES. Added in 0.2.14.
        'core_badges_get_user_badges',
        // Answers "which group is this student in, for this course" —
        // relevant wherever a course uses groups (group assignments,
        // group-restricted activities) and a ticket is "I wasn't added to
        // a group" or "my group's deadline is different". Takes an
        // explicit userid parameter. Added in 0.2.14.
        'core_group_get_course_user_groups',
        // Basic "who/what is this site" introspection (site name, Moodle
        // release, list of functions enabled for the calling token) — no
        // access-control requirements of its own beyond webservice/
        // rest:use (already granted above). The Knowledge Base site-
        // analysis pipeline (src/lib/kbAnalysis.server.ts in the web app)
        // calls this first, before anything else, to confirm the token
        // can reach the site at all. Confirmed missing live: every call
        // failed with "Access control exception" even though this
        // function needs no special capability — it simply wasn't
        // attached to our service, same root cause as core_course_get_
        // contents in 0.2.9 and core_course_search_courses in 0.2.13.
        // Added in 0.2.15.
        'core_webservice_get_site_info',
        // Lists the site's course categories (used by the Knowledge Base
        // pipeline above to understand the site's overall subject-area
        // structure before generating general, site-wide articles).
        // Core itself, same reasoning as core_course_get_contents/
        // core_course_search_courses above — safe to attach
        // unconditionally. Visible categories work with no extra
        // capability; moodle/category:viewhiddencategories (added to
        // CAPABILITIES above) additionally surfaces hidden ones. Added
        // in 0.2.15.
        'core_course_get_categories',
        // Lists the site's courses. The Knowledge Base pipeline calls
        // this first and only falls back to core_course_get_courses_by_
        // field below if this one fails — see the comment in
        // kbAnalysis.server.ts ("core_course_get_courses isn't granted
        // on every token"). Respects moodle/course:view, already granted
        // at system context in 0.2.8. Added in 0.2.15.
        'core_course_get_courses',
        // Fallback for core_course_get_courses above, used by the same
        // Knowledge Base pipeline call when the primary function isn't
        // available on a given token/site. Same moodle/course:view
        // dependency as core_course_get_courses. Added in 0.2.15.
        'core_course_get_courses_by_field',
        // Lists a course's active enrolment methods (manual, self, cohort
        // sync, etc.) — the Knowledge Base "Analyze Site" pipeline uses this
        // to decide whether self-enrolment / cohort-sync instructions are
        // relevant on a given site. Confirmed missing live: every sampled
        // course came back with "core_enrol_get_course_enrolment_methods:
        // Access control exception" in the pipeline's own error log, same
        // root cause and same "Access control exception" shape as
        // core_webservice_get_site_info/core_course_get_categories before
        // 0.2.15 — it simply wasn't attached to our service. Core itself,
        // same reasoning as the other 0.2.15 additions above, so safe to
        // attach unconditionally. validate_context() inside this function
        // should already be satisfied by moodle/course:view (granted in
        // 0.2.8) the same way it is for core_course_get_contents/
        // core_course_search_courses — this function doesn't appear to
        // require any capability beyond that per Moodle core, but that's a
        // best-effort reading rather than a live-confirmed one (same caveat
        // already flagged elsewhere in this file, e.g. mod_quiz_get_user_
        // attempts above); narrow down or add a capability here if live
        // testing on is-local-moodle still throws after this release.
        // Added in 0.2.18.
        'core_enrol_get_course_enrolment_methods',
    ];

    /**
     * Webservice functions that are only safe to attach if their owning
     * plugin is actually installed on this site — unlike FUNCTIONS above
     * (all either core itself or a report plugin core always ships),
     * neither mod_customcert nor mod_certificate is bundled with Moodle,
     * so a given site may have neither, either, or both installed. Each
     * entry maps a function name to the capability name(s) it needs the
     * AI Agent role to hold; see ensure_optional_functions_and_capabilities()
     * for how existence is checked before attaching/granting anything, so
     * a site lacking both plugins is entirely unaffected rather than
     * having its whole provisioning run aborted by an exception. Added in
     * 0.2.7.
     *
     * @var array<string, string[]>
     */
    const OPTIONAL_FUNCTIONS = [
        // The mod_customcert ("Custom certificate", the actively-maintained
        // certificate plugin — https://moodle.org/plugins/mod_customcert)
        // only gained its own webservice function in release 5.0.1
        // (~November 2025). list_issues() in that plugin's externallib.php
        // require_capability()s mod/customcert:viewallcertificates at the
        // system context specifically when asking for a user other than
        // the caller, which is always the case here (the AI Agent always
        // acts on behalf of the ticket requester, never itself).
        'mod_customcert_get_issued_certificates' => [
            'mod/customcert:viewallcertificates',
        ],
        // The mod_certificate ("Certificate", the older/classic certificate
        // plugin — https://moodle.org/plugins/mod_certificate) exposes
        // mod_certificate_external::get_issued_certificates(). This entry
        // is lower-confidence than everything else in this file: community
        // references point to a check against BOTH mod/certificate:view
        // (view one's own certificate) and mod/certificate:manage (most
        // likely the "view other users' certificates" capability,
        // following the same split as moodle/grade:viewall and
        // mod/customcert:viewallcertificates above), without a
        // primary-source confirmation of exactly which combination the
        // function enforces. Both are granted defensively; narrow this
        // down if real-world testing on a site running mod_certificate
        // shows one is unnecessary or insufficient.
        'mod_certificate_get_issued_certificates' => [
            'mod/certificate:view',
            'mod/certificate:manage',
        ],
    ];

    /** @var string[] The capabilities step 4 of the instructions grants, plus any later additions. */
    const CAPABILITIES = [
        'moodle/user:viewdetails',
        'moodle/user:viewhiddendetails',
        'moodle/course:useremail',
        // Without this one, every REST call the service account makes fails
        // with "Access control exception" (debug info: "You are not allowed
        // to use the rest protocol (missing capability: webservice/rest:use)"),
        // even though the 3 capabilities above are all present and correct —
        // they grant access to the *data*, this one grants permission to use
        // the REST *transport* at all. Added in 0.2.2; see
        // ensure_role_capabilities_current() for how this self-heals an
        // already-provisioned 0.2.0/0.2.1 role.
        'webservice/rest:use',
        // Without these two, core_user_get_users_by_field(field: 'email')
        // silently returns an EMPTY array for a real, existing, confirmed
        // user — no exception, no error, just a false "not found" — because
        // email is an "identity field" gated by $CFG->showuseridentity:
        // moodle/site:viewuseridentity controls whether identity fields
        // (email, idnumber, ...) are considered at all when searching for
        // users (get_extra_user_fields()), and moodle/user:viewalldetails
        // is what multiple independent Moodle community reports identify
        // as the missing piece specifically for the email-lookup branch of
        // get_users_by_field(). Added in 0.2.3.
        'moodle/site:viewuseridentity',
        'moodle/user:viewalldetails',
        // The core_enrol_get_users_courses() function silently drops a course from its
        // result for every course where the *calling* user (our service
        // account) lacks this capability — validate_context() inside that
        // function treats "can't view this course's participants" as "this
        // course isn't yours to see", not as an error, so a target user who
        // is genuinely enrolled still comes back with an empty course list
        // and no exception at all. Same silent-empty-result shape as the
        // two capabilities above, just for the 2nd of the 3 functions this
        // plugin calls instead of the 1st. Added in 0.2.4.
        'moodle/course:viewparticipants',
        // Without this one, the service account — which is intentionally
        // never enrolled in any course — fails require_login($course)
        // itself for every course-scoped call, before capability checks
        // like viewparticipants/report:completion:view above even come
        // into play. Two different failure shapes depending on which of
        // the 3 functions hits it: core_enrol_get_users_courses() calls
        // validate_context() per course, which treats the require_login()
        // failure the same as "can't see this course" and just silently
        // drops it from the result (same silent-empty-result shape as
        // moodle/course:viewparticipants above — a genuinely enrolled
        // target user still comes back with an empty course list, no
        // exception); core_completion_get_course_completion_status()
        // instead calls require_login($course) directly and lets it
        // throw, surfacing a loud "require_login_exception / Not
        // enrolled" error. Confirmed live on is-local-moodle: a
        // known-enrolled user (Student, Active) came back as "[]" from
        // the first function and an explicit "Not enrolled" exception
        // from the second, on a role already carrying every other
        // capability in this list. Added in 0.2.8.
        'moodle/course:view',
        // Unlike every capability above, missing this one does NOT fail
        // silently: core_completion_get_course_completion_status() calls
        // require_capability('report/completion:view', ...) directly and
        // throws a loud "accessdenied" exception the moment the AI Agent
        // tries to check a course's completion status — confirmed via a
        // Moodle community forum thread covering exactly this function.
        // Added in 0.2.5, ahead of hitting that error for real: the
        // function was already registered on the service from day one but
        // had never actually been exercised until the 0.2.4 fix stopped
        // course lists from coming back empty.
        'report/completion:view',
        // Lets gradereport_user_get_grade_items() (added to FUNCTIONS in
        // 0.2.6) return anything useful. gradereport/user:view is the base
        // "can see a grade report" capability; moodle/grade:viewall is the
        // separate "can see grades belonging to someone other than
        // yourself" capability — without it the function would only ever
        // be able to report the *service account's own* (nonexistent)
        // grades, following the same "view" vs "view all/others" split as
        // moodle/course:viewparticipants above. Added in 0.2.6.
        'gradereport/user:view',
        'moodle/grade:viewall',
        // Needed for mod_assign_get_assignments (added in 0.2.14) to list a
        // course's assignments at all — without it the function silently
        // drops every assignment in a course the service account can't
        // mod/assign:view, same silent-empty-result shape as moodle/
        // course:viewparticipants above.
        'mod/assign:view',
        // Needed for mod_assign_get_submission_status (added in 0.2.14) to
        // view a student's submission rather than only the calling
        // account's own (nonexistent) one.
        'mod/assign:viewgrades',
        // Needed for mod_quiz_get_quizzes_by_courses (added in 0.2.14),
        // same "can see the activity at all" role as mod/assign:view above.
        'mod/quiz:view',
        // Needed for mod_quiz_get_user_attempts (added in 0.2.14) to view a
        // student's attempts rather than only the calling account's own.
        // Lower-confidence than most entries in this list — no primary-
        // source confirmation that this exact capability name is what mod_
        // quiz's external API enforces for viewing another user's
        // attempts, same caveat already flagged on the mod_certificate
        // entry in OPTIONAL_FUNCTIONS above; narrow down or correct if
        // live testing says otherwise.
        'mod/quiz:viewreports',
        // Needed for mod_forum_get_forum_discussions and mod_forum_get_
        // discussion_posts (both added in 0.2.14) to read a course's
        // forum content at all.
        'mod/forum:viewdiscussion',
        // Needed for core_badges_get_user_badges (added in 0.2.14) to view
        // a student's badges rather than only the calling account's own.
        'moodle/badges:viewotherbadges',
        // Needed for core_group_get_course_user_groups (added in 0.2.14)
        // to see which group a student belongs to without the service
        // account itself being a member of (or able to access) that group.
        // Lower-confidence, same caveat as mod/quiz:viewreports above —
        // granted defensively; narrow down if live testing shows it's
        // unnecessary or insufficient.
        'moodle/site:accessallgroups',
        // Needed for core_course_get_categories (added in 0.2.15) to
        // include hidden categories in the Knowledge Base site-analysis
        // picture, not just the visible ones every site always returns
        // regardless of capabilities. Not strictly required for the
        // function to work at all (unlike the access-control-exception
        // functions below) — granted defensively, same spirit as the
        // other "lower confidence, narrow down later" entries above.
        'moodle/category:viewhiddencategories',
    ];

    /**
     * The `webservice` class (webservice/lib.php) is a legacy, non-autoloaded
     * piece of Moodle core — unlike accesslib.php/moodlelib.php (home of
     * create_role()/assign_capability()/role_assign()/user_create_user(),
     * used elsewhere in this file), it is not pulled in by the standard page
     * bootstrap (config.php -> lib/setup.php). Every method below that uses
     * it must call this first. Tokens are created with
     * \core_external\util::generate_token(), which is autoloaded, so the
     * deprecated lib/externallib.php is deliberately not included.
     * require_once() is itself idempotent, so calling this repeatedly across
     * methods in the same request is safe and cheap.
     */
    protected static function require_webservice_libs(): void {
        global $CFG;

        require_once($CFG->dirroot . '/webservice/lib.php');
    }

    /**
     * Run (or re-run) the full provisioning sequence. Never throws — any
     * failure is recorded in the 'ai_agent_provision_error' plugin config so
     * it can be surfaced on the settings page, and install/upgrade of the
     * rest of the plugin is never blocked by a provisioning problem.
     */
    public static function ensure_provisioned(): void {
        try {
            self::ensure_webservices_enabled();
            $serviceid = self::ensure_external_service();
            self::ensure_functions_attached($serviceid);
            $roleid = self::ensure_role();
            self::ensure_optional_functions_and_capabilities($serviceid, $roleid);
            $userid = self::ensure_service_account();
            self::ensure_role_assigned($roleid, $userid);
            self::ensure_authorised_user($serviceid, $userid);
            self::ensure_token($serviceid, $userid);

            // Success: clear any previously recorded error and timestamp the run.
            unset_config('ai_agent_provision_error', 'local_chronifysupport');
            set_config('ai_agent_provisioned_at', time(), 'local_chronifysupport');
        } catch (\Throwable $e) {
            set_config('ai_agent_provision_error', $e->getMessage(), 'local_chronifysupport');
            debugging('local_chronifysupport AI Agent provisioning failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Step 1 + step 2 of the instructions: enable web services core-wide and
     * make sure the REST protocol is in the enabled protocol list.
     */
    protected static function ensure_webservices_enabled(): void {
        if (empty(get_config('core', 'enablewebservices'))) {
            set_config('enablewebservices', 1);
        }

        $protocols = array_filter(array_map('trim', explode(',', get_config('core', 'webserviceprotocols') ?: '')));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }
    }

    /**
     * Step 3 (service half): create the External service if it doesn't
     * already exist. Returns the service id either way.
     */
    protected static function ensure_external_service(): int {
        self::require_webservice_libs();
        $webservicemanager = new \webservice();

        $existing = $webservicemanager->get_external_service_by_shortname(self::SERVICE_SHORTNAME);
        if ($existing) {
            return $existing->id;
        }

        $service = new \stdClass();
        $service->name = self::SERVICE_NAME;
        $service->shortname = self::SERVICE_SHORTNAME;
        $service->enabled = 1;
        $service->restrictedusers = 1;
        $service->downloadfiles = 0;
        $service->uploadfiles = 0;
        $service->timecreated = time();
        $service->timemodified = time();

        return $webservicemanager->add_external_service($service);
    }

    /**
     * Step 3 (functions half): attach each of the 3 required functions to
     * the service. add_external_function_to_service() already checks
     * service_function_exists() internally before inserting, so calling it
     * unconditionally on every run is safe and does not duplicate rows.
     */
    protected static function ensure_functions_attached(int $serviceid): void {
        self::require_webservice_libs();
        $webservicemanager = new \webservice();

        foreach (self::FUNCTIONS as $functionname) {
            $webservicemanager->add_external_function_to_service($functionname, $serviceid);
        }
    }

    /**
     * Step 4 (role half): create a dedicated system-level role carrying the
     * capabilities from the instructions, if it doesn't exist yet. Returns
     * the role id either way.
     *
     * Capability assignment happens on every call, not just at creation —
     * assign_capability() already checks for an unchanged existing row
     * before writing, so this is cheap, and it's what lets
     * ensure_role_capabilities_current() self-heal a role that was created
     * by an older plugin version with a shorter CAPABILITIES list (e.g.
     * 0.2.0/0.2.1 roles are missing webservice/rest:use, added in 0.2.2).
     */
    protected static function ensure_role(): int {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => self::ROLE_SHORTNAME]);
        if (!$roleid) {
            $roleid = create_role(
                self::ROLE_NAME,
                self::ROLE_SHORTNAME,
                'Minimal-privilege role for the Chronify.Support AI Agent service account. ' .
                    'Grants only the capabilities needed to look up a user by email, ' .
                    'list their course enrolments, read their email on course pages, ' .
                    'and use the REST protocol.',
                ''
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }

        self::ensure_role_capabilities_current($roleid);

        return $roleid;
    }

    /**
     * Grant every capability in CAPABILITIES to the role, including any
     * added by a newer plugin version than the one that originally created
     * this role. assign_capability()'s own existing-row check makes this a
     * no-op for capabilities already granted.
     */
    protected static function ensure_role_capabilities_current(int $roleid): void {
        $systemcontext = \context_system::instance();
        foreach (self::CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }
    }

    /**
     * Attach whichever OPTIONAL_FUNCTIONS are actually available on this
     * site (i.e. their owning plugin — mod_customcert or mod_certificate —
     * is installed) and grant the role the capabilities each one needs.
     *
     * This is deliberately separate from ensure_functions_attached()/
     * ensure_role_capabilities_current() above: those two assume every
     * name in FUNCTIONS/CAPABILITIES always exists, which holds for core
     * functions/capabilities and for report plugins core always ships,
     * but not for certificate plugins that may simply not be installed.
     * Calling add_external_function_to_service() for a function Moodle
     * has never heard of throws, and ensure_provisioned() wraps the
     * entire sequence in one try/catch — so doing that unconditionally
     * here would silently abort every step that runs after it (service
     * account creation, role assignment, token generation, ...) on any
     * site without these plugins, not just skip the certificate part.
     * Checking existence first keeps this entirely optional and
     * side-effect-free wherever the plugins are absent.
     */
    protected static function ensure_optional_functions_and_capabilities(int $serviceid, int $roleid): void {
        global $DB;

        self::require_webservice_libs();
        $webservicemanager = new \webservice();
        $systemcontext = \context_system::instance();

        foreach (self::OPTIONAL_FUNCTIONS as $functionname => $capabilities) {
            if (!$DB->record_exists('external_functions', ['name' => $functionname])) {
                // Owning plugin (mod_customcert or mod_certificate) isn't
                // installed on this site — expected on many sites, not an
                // error, nothing further to do for this function.
                continue;
            }

            $webservicemanager->add_external_function_to_service($functionname, $serviceid);

            foreach ($capabilities as $capability) {
                if (!$DB->record_exists('capabilities', ['name' => $capability])) {
                    // The function exists but this particular capability
                    // name doesn't match what's actually registered by the
                    // installed plugin version — skip just this one
                    // capability rather than aborting the whole run.
                    continue;
                }
                assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
            }
        }
    }

    /**
     * Step 4 (account half): create the dedicated service account if it
     * doesn't exist yet. Returns the user id either way.
     *
     * auth is 'manual' with a long random password nobody is given —
     * NOT 'nologin'. Moodle's own webservice token check
     * (external_api::validate_auth(), "Refused web service access for
     * nologin authentication") explicitly rejects tokens belonging to a
     * nologin (or suspended) account regardless of capabilities, so a
     * nologin service account's token can never actually be used — the
     * account practically can't sign in anyway since no one knows the
     * random password, which achieves the same goal without tripping
     * that check.
     */
    protected static function ensure_service_account(): int {
        global $DB, $CFG;

        // The user_create_user() function lives in user/lib.php, which is not loaded on
        // every entry point (e.g. regenerate_ai_agent_token.php).
        require_once($CFG->dirroot . '/user/lib.php');

        $existing = $DB->get_record('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);
        if ($existing) {
            self::ensure_service_account_can_authenticate($existing);
            return $existing->id;
        }

        $host = '';
        if (!empty($CFG->noreplyaddress) && strpos($CFG->noreplyaddress, '@') !== false) {
            $host = substr($CFG->noreplyaddress, strpos($CFG->noreplyaddress, '@') + 1);
        } else {
            $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'example.com';
        }

        $user = new \stdClass();
        $user->auth = 'manual';
        $user->username = self::SERVICE_USERNAME;
        $user->firstname = self::SERVICE_FIRSTNAME;
        $user->lastname = self::SERVICE_LASTNAME;
        $user->email = self::SERVICE_USERNAME . '@' . $host;
        $user->confirmed = 1;
        $user->suspended = 0;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->password = self::generate_service_password();

        return user_create_user($user, true, false);
    }

    /**
     * Self-heal an existing service account created by plugin release
     * 0.2.0, which used auth=nologin — that version's token could be
     * generated fine but every actual webservice call against it was
     * refused by Moodle core with "Refused web service access for
     * nologin authentication username: chronifysupport-ai-service". Rotates the
     * account to auth=manual (+ a fresh random password, + unsuspended)
     * so its *existing* token starts working immediately, no token
     * regeneration needed. A no-op once the account is already manual
     * and not suspended.
     */
    protected static function ensure_service_account_can_authenticate(\stdClass $user): void {
        global $DB;

        $needsfix = ($user->auth === 'nologin') || !empty($user->suspended);
        if (!$needsfix) {
            return;
        }

        $user->auth = 'manual';
        $user->suspended = 0;
        $DB->update_record('user', $user);

        $freshuser = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        update_internal_user_password($freshuser, self::generate_service_password());
    }

    /**
     * A long, random password nobody is meant to know or use — this
     * account is only ever authenticated against via its webservice
     * token, never through the login form. user_create_user()/
     * update_internal_user_password() hash it the same way as any other
     * Moodle password.
     *
     * The password must satisfy the site's password policy, which many
     * sites configure strictly (upper case, lower case, digits and special
     * characters, several of each). A plain hex string fails such policies
     * ("Passwords must have at least 1 upper case letter(s)"), which made
     * the automatic setup fail. So the password always contains several
     * characters from every class, then is shuffled.
     */
    protected static function generate_service_password(): string {
        $classes = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '*-#',
        ];
        $chars = [];
        foreach ($classes as $set) {
            for ($i = 0; $i < 6; $i++) {
                $chars[] = $set[random_int(0, strlen($set) - 1)];
            }
        }
        $all = implode('', $classes);
        for ($i = 0; $i < 16; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        // Fisher-Yates shuffle with a cryptographically secure source.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /**
     * Step 4 (assignment): assign the role to the service account at system
     * context, unless it's already assigned.
     */
    protected static function ensure_role_assigned(int $roleid, int $userid): void {
        global $DB;

        $systemcontext = \context_system::instance();

        $alreadyassigned = $DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $userid,
            'contextid' => $systemcontext->id,
        ]);

        if (!$alreadyassigned) {
            role_assign($roleid, $userid, $systemcontext->id);
        }
    }

    /**
     * Authorise the service account to use the External service, unless
     * already authorised (only relevant because the service is created with
     * restrictedusers=1).
     */
    protected static function ensure_authorised_user(int $serviceid, int $userid): void {
        self::require_webservice_libs();
        $webservicemanager = new \webservice();

        $existing = $webservicemanager->get_ws_authorised_user($serviceid, $userid);
        if ($existing) {
            return;
        }

        $authoriseduser = new \stdClass();
        $authoriseduser->externalserviceid = $serviceid;
        $authoriseduser->userid = $userid;

        $webservicemanager->add_ws_authorised_user($authoriseduser);
    }

    /**
     * Step 5: generate a permanent token for the service account, unless one
     * already exists. Deliberately does not regenerate on every run — a
     * fresh token is only created by regenerate_ai_agent_token.php, acting
     * on an explicit admin click.
     */
    protected static function ensure_token(int $serviceid, int $userid): void {
        global $DB;

        self::require_webservice_libs();

        $existing = $DB->record_exists('external_tokens', [
            'externalserviceid' => $serviceid,
            'userid' => $userid,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);

        if ($existing) {
            return;
        }

        $service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);
        $context = \context_system::instance();

        \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $userid, $context);
    }

    /**
     * Whether the AI Agent web service has been set up on this site, that is
     * the external service and its service account both exist. Read-only:
     * never creates or changes anything.
     *
     * @return bool
     */
    public static function is_provisioned(): bool {
        global $DB;

        // Database lookups only: no web service libraries are loaded here, so this is cheap and safe to call anywhere.
        if (!$DB->record_exists('external_services', ['shortname' => self::SERVICE_SHORTNAME])) {
            return false;
        }

        return $DB->record_exists('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);
    }

    /**
     * Brings an existing AI Agent setup up to date (new functions and
     * capabilities added by plugin upgrades) but never creates one: a site
     * where the administrator has not set the feature up is left untouched.
     * Never throws.
     */
    public static function refresh_if_provisioned(): void {
        try {
            if (self::is_provisioned()) {
                self::ensure_provisioned();
            }
        } catch (\Throwable $e) {
            debugging('local_chronifysupport AI Agent refresh failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Read the current token for the service account directly from
     * external_tokens, if one exists. Used by settings.php at render time so
     * the token is never cached/duplicated in plugin config.
     *
     * @return string|null
     */
    public static function get_current_token(): ?string {
        global $DB;

        self::require_webservice_libs();
        $webservicemanager = new \webservice();
        $service = $webservicemanager->get_external_service_by_shortname(self::SERVICE_SHORTNAME);
        if (!$service) {
            return null;
        }

        $user = $DB->get_record('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);
        if (!$user) {
            return null;
        }

        $token = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);

        return $token ? $token->token : null;
    }

    /**
     * Resolve the service and user ids needed by
     * regenerate_ai_agent_token.php, provisioning them first if somehow
     * missing (e.g. an admin deleted the service account by hand).
     *
     * @return array{0: int, 1: int} [serviceid, userid]
     */
    public static function get_or_provision_service_and_user(): array {
        self::ensure_webservices_enabled();
        $serviceid = self::ensure_external_service();
        self::ensure_functions_attached($serviceid);
        $roleid = self::ensure_role();
        self::ensure_optional_functions_and_capabilities($serviceid, $roleid);
        $userid = self::ensure_service_account();
        self::ensure_role_assigned($roleid, $userid);
        self::ensure_authorised_user($serviceid, $userid);

        return [$serviceid, $userid];
    }

    /**
     * Delete any existing permanent token for the service account and
     * generate a fresh one. Used by regenerate_ai_agent_token.php.
     */
    public static function regenerate_token(): void {
        global $DB;

        [$serviceid, $userid] = self::get_or_provision_service_and_user();

        $DB->delete_records('external_tokens', [
            'externalserviceid' => $serviceid,
            'userid' => $userid,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);

        self::ensure_token($serviceid, $userid);

        unset_config('ai_agent_provision_error', 'local_chronifysupport');
    }
}
