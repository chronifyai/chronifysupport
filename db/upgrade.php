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
 * Upgrade-time hook for local_chronifysupport.
 *
 * This is the path real, already-installed sites (sites that already
 * have an earlier release installed) actually take: visiting
 * /admin/index.php after copying in an updated plugin runs upgrade.php, not
 * install.php. The AI Agent webservice provisioning added in release 0.2.0
 * is retrofitted here so existing installs get it without any manual
 * webservice/role/account/token setup — see
 * classes/ai_agent_provisioner.php and
 * the Moodle AI Agent setup guide.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the plugin upgrade steps.
 *
 * @param int $oldversion The previously installed version.
 * @return bool
 */
function xmldb_local_chronifysupport_upgrade($oldversion) {
    // Note: the AI Agent steps below only refresh an existing setup
    // (refresh_if_provisioned()); they never create one. Setting it up is an
    // explicit administrator action on the plugin settings page.
    if ($oldversion < 2026100800) {
        // Release 0.2.0: automatic AI Agent webservice provisioning.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100800, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100801) {
        // Release 0.2.1: 0.2.0 created the service account with
        // auth=nologin, which Moodle's webservice layer refuses outright
        // ("Refused web service access for nologin authentication
        // username: chronifysupport-ai-service") regardless of a valid token or
        // correct capabilities. ensure_provisioned() -> ensure_service_account()
        // now rotates any existing nologin/suspended account to
        // auth=manual + a fresh random password, which the *existing*
        // token immediately works with — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100801, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100802) {
        // Release 0.2.2: 0.2.0/0.2.1 granted the AI Agent role only
        // moodle/user:viewdetails, moodle/user:viewhiddendetails and
        // moodle/course:useremail — enough to see the *data*, but not
        // enough to use the REST protocol itself, so every actual call
        // failed with "Access control exception" (debug info: "You are
        // not allowed to use the rest protocol (missing capability:
        // webservice/rest:use)"). ensure_provisioned() -> ensure_role() ->
        // ensure_role_capabilities_current() now grants
        // webservice/rest:use to the existing role too — no token
        // regeneration or re-authorisation needed, the existing token
        // starts working as soon as this runs.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100802, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100803) {
        // Release 0.2.3: the AI Agent role was still missing
        // moodle/site:viewuseridentity and moodle/user:viewalldetails.
        // Without them, core_user_get_users_by_field(field: 'email')
        // doesn't throw anything — it just silently returns an empty
        // array for a real, existing, confirmed user, which the AI Agent
        // then (correctly, given what it was told) reports as "no Moodle
        // account found". ensure_provisioned() -> ensure_role() ->
        // ensure_role_capabilities_current() now grants both to the
        // existing role too — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100803, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100804) {
        // Release 0.2.4: core_enrol_get_users_courses() was silently
        // returning an empty course list for a user who really is
        // enrolled, because the AI Agent role was still missing
        // moodle/course:viewparticipants — that function drops any course
        // from its result where the *calling* service account lacks this
        // capability, with no exception at all (same "silent empty
        // result" shape as the 0.2.3 fix, just for the 2nd of the 3
        // functions this plugin calls). ensure_provisioned() ->
        // ensure_role() -> ensure_role_capabilities_current() now grants
        // it to the existing role too — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100804, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100805) {
        // Release 0.2.5: core_completion_get_course_completion_status()
        // was registered on the service from day one but had never
        // actually been exercised until the 0.2.4 fix stopped course
        // lists from coming back empty — and it enforces
        // report/completion:view with a loud require_capability() call
        // (accessdenied exception), unlike the silent-empty-result bugs
        // fixed in 0.2.1-0.2.4. ensure_provisioned() -> ensure_role() ->
        // ensure_role_capabilities_current() now grants it to the
        // existing role too — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100805, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100806) {
        // Release 0.2.6: added gradereport_user_get_grade_items to
        // FUNCTIONS (so the AI Agent can answer "what's my grade"
        // tickets) plus the gradereport/user:view and
        // moodle/grade:viewall capabilities it needs on the AI Agent
        // role. ensure_provisioned() attaches the new function to the
        // existing service and grants the new capabilities to the
        // existing role — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100806, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100807) {
        // Release 0.2.7: optional certificate support. Added
        // OPTIONAL_FUNCTIONS (mod_customcert_get_issued_certificates and
        // mod_certificate_get_issued_certificates, each with their own
        // capabilities) plus ensure_optional_functions_and_capabilities(),
        // which only attaches/grants whichever of these two certificate
        // plugins is actually installed on this site, so sites with
        // neither plugin are unaffected. ensure_provisioned() now calls
        // it right after ensure_role() — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100807, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100808) {
        // Release 0.2.8: the AI Agent role was still missing
        // moodle/course:view ("View courses without participation").
        // The service account is intentionally never enrolled in any
        // course, so without this capability every course-scoped call
        // fails require_login($course) itself, before capability checks
        // like moodle/course:viewparticipants or report/completion:view
        // even run. Two different failure shapes depending on which
        // function hits it: core_enrol_get_users_courses() treats the
        // require_login() failure the same as "can't see this course"
        // and silently drops it from the result (same silent-empty-
        // result shape as the 0.2.3/0.2.4 fixes — a genuinely enrolled
        // target user still comes back with an empty course list, no
        // exception), while core_completion_get_course_completion_status()
        // lets require_login() throw outright ("require_login_exception
        // / Not enrolled"). Confirmed live: a known-enrolled user came
        // back empty from the first function and threw from the second,
        // on a role already carrying every other capability added in
        // 0.2.2-0.2.7. ensure_provisioned() -> ensure_role() ->
        // ensure_role_capabilities_current() now grants it to the
        // existing role too — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100808, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026100809) {
        // Release 0.2.9: added core_course_get_contents to FUNCTIONS.
        // the AI agent's certificate lookup (built against the 0.2.7 Moodle-side
        // support) needs to find which activity in a course is a
        // mod_customcert/mod_certificate instance before it can call either
        // certificate function, since both key off an activity instance id,
        // not a course id — core_course_get_contents is how it discovers
        // that. It's core itself and exists on every Moodle site regardless
        // of which certificate plugin (if any) is installed, but it had
        // never been attached to our service. Confirmed live: calling it
        // returned "Access to the function core_course_get_contents() is
        // not allowed" (webservice_access_exception/accessexception) even
        // though the function exists in Moodle core — it simply wasn't on
        // the service's function list, which would have silently blocked
        // every certificate lookup the AI agent attempts, on any site, whether
        // or not that site actually has a certificate plugin installed.
        // No new capability needed: moodle/course:view (added in 0.2.8)
        // already covers the require_login($course) check this function
        // makes. ensure_provisioned() -> ensure_functions_attached() now
        // attaches it to the existing service too — no token regeneration
        // needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026100809, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026101000) {
        // Release 0.2.10: the connection settings used to be filled in here.
        // New installations get them from classes/connection_defaults.php instead.
        upgrade_plugin_savepoint(true, 2026101000, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102102) {
        // Release 0.2.13: attach core_course_search_courses to the AI Agent
        // service. Every course-related function attached before this one
        // only works once a course is already known — core_enrol_get_users_
        // courses only ever returns courses the student is already enrolled
        // in, and core_course_get_contents needs a course id you must
        // already have. There was no function available to answer "does a
        // course called X exist on this site at all?", so when a student
        // mentioned a real course by name that they weren't enrolled in yet,
        // the AI Agent had no way to tell it apart from a made-up name and
        // fell back to a generic clarifying question instead of recognising
        // it. core_course_search_courses is core itself (same reasoning as
        // core_course_get_contents in 0.2.9) and respects normal course
        // visibility rules via moodle/course:view, already granted in 0.2.8
        // — no new capability needed. ensure_provisioned() ->
        // ensure_functions_attached() attaches it to the existing service
        // too — no token regeneration needed.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026102102, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102103) {
        // Release 0.2.14: attach a batch of additional core/bundled-module
        // webservice functions to the AI Agent service, all picked for the
        // same reason core_course_search_courses was added in 0.2.13 —
        // closing gaps where the AI Agent could see the general shape of a
        // course but not answer a specific, commonly-asked support
        // question about it: assignment due dates and submission status
        // (mod_assign_get_assignments / mod_assign_get_submission_status),
        // quiz definitions and attempt history
        // (mod_quiz_get_quizzes_by_courses / mod_quiz_get_user_attempts),
        // per-activity (not just per-course) completion status
        // (core_completion_get_activities_completion_status), forum
        // discussions and posts (mod_forum_get_forum_discussions /
        // mod_forum_get_discussion_posts), badges
        // (core_badges_get_user_badges), and group membership
        // (core_group_get_course_user_groups). All are core Moodle itself
        // or bundled activity modules present on every distribution — same
        // reasoning as core_course_get_contents in 0.2.9 — so safe to
        // attach unconditionally via FUNCTIONS rather than the OPTIONAL_
        // FUNCTIONS existence-check path. ensure_provisioned() ->
        // ensure_functions_attached() + ensure_role_capabilities_current()
        // attach the functions and grant the new capabilities they need to
        // the existing service/role — no token regeneration needed, same
        // as every release before this one. None of these functions are
        // actually called by anything yet (the AI Agent's reply logic
        // hasn't been wired up to use them) — this release only grants the
        // *capability* to call them later, with zero effect on current
        // behaviour until that happens.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026102103, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102104) {
        // Release 0.2.15: attach core_webservice_get_site_info,
        // core_course_get_categories, core_course_get_courses and
        // core_course_get_courses_by_field to the AI Agent service, plus
        // the moodle/category:viewhiddencategories capability. These are
        // the functions the Knowledge Base "Analyze Site" pipeline
        // (src/lib/kbAnalysis.server.ts in the web app) calls first, and
        // every single one was failing live with "Access control
        // exception" — same root cause as core_course_get_contents in
        // 0.2.9 and core_course_search_courses in 0.2.13: the functions
        // simply weren't attached to our service, regardless of role
        // capabilities. moodle/course:view (0.2.8) already covers the
        // two course-listing functions; no new capability was needed for
        // those two or for the site-info call. ensure_provisioned() ->
        // ensure_functions_attached() + ensure_role_capabilities_current()
        // attach the functions and grant the new capability to the
        // existing service/role — no token regeneration needed, same as
        // every release before this one.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026102104, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102302) {
        // Release 0.2.17: the Helpdesk base URL used to be filled in here.
        // New installations get it from classes/connection_defaults.php instead.
        upgrade_plugin_savepoint(true, 2026102302, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102304) {
        // Release 0.2.19: 0.2.18 added core_enrol_get_course_enrolment_methods
        // to FUNCTIONS in ai_agent_provisioner.php but — unlike every other
        // function attach in this file (0.2.6/0.2.7/0.2.9/0.2.13/0.2.14/0.2.15)
        // — shipped without its own "if ($oldversion < ...) ensure_provisioned();"
        // step here. Confirmed on a live site: upgrading to 0.2.18 bumped
        // the DB version fine, but ensure_functions_attached() never actually
        // re-ran, so the live service's function list was untouched
        // (available_functions_count stayed at 19) and enrolment_methods calls
        // kept throwing "Access control exception" after the upgrade, exactly
        // like every prior "forgot to attach it" bug in this history
        // (0.2.9/0.2.13/0.2.15). ensure_provisioned() -> ensure_functions_attached()
        // now attaches it to the existing service — no token regeneration
        // needed, same as every release before this one.
        \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();
        upgrade_plugin_savepoint(true, 2026102304, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102900) {
        // Release 0.2.20: licence headers, packaging clean-up and upgrade
        // savepoints only; no functional change.
        upgrade_plugin_savepoint(true, 2026102900, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026102901) {
        // Release 0.2.21: Privacy API provider rewrite and uninstall hook
        // only; no functional change.
        upgrade_plugin_savepoint(true, 2026102901, 'local', 'chronifysupport');
    }

    if ($oldversion < 2026110611) {
        // Release 0.5.0: the public endpoints now run inside Moodle. Remove the
        // settings cache and rate-limit files that older releases generated.
        \local_chronifysupport\cleanup::remove_generated_files();
        upgrade_plugin_savepoint(true, 2026110611, 'local', 'chronifysupport');
    }

    return true;
}
