# Changes

## 0.5.3
- The temporary cookie that links a logged-out visitor's chat to their Moodle account after login is now a session cookie and is only used within 30 minutes. On shared computers, a different person who logs in later no longer inherits the previous visitor's conversation.

## 0.5.2
- The floating button label ("Get Help" / "Continue chat") is checked again once the page has loaded, so it no longer depends on the order in which page scripts run. After a logged-out visitor's previous conversation is reset, the button now shows "Get Help" straight away.

## 0.5.1
- The connection values (Chronify.Support app address, service address and public client key) are kept in one place, `classes/connection_defaults.php`, and used as the defaults of the plugin settings. They are filled in on installation if empty, and no longer written by upgrade steps.

## 0.5.0
- The public endpoints for visitors who are not logged in (`get_help_anonymous.php`, `refresh_token_anonymous.php`, `accept_invite.php`) now run inside Moodle as AJAX scripts without a session. Themes that force users to log in skip AJAX scripts, so the endpoints stay reachable.
- Settings are read with `get_config()`; the generated `classes/anon_config_cache.php` file and the stand-alone token class are gone, and the plugin no longer writes into its own folder.
- Rate limiting uses a Moodle application cache (`ratelimit`) instead of files.
- Leftover generated files from older releases are removed on upgrade.
- Error messages of these endpoints go to `debugging()` instead of `error_log()`.

## 0.4.4
- PHPUnit: coverage is declared with `CoversClass` attributes instead of `@covers` annotations.

## 0.4.3
- Tokens are created with `\core_external\util::generate_token()`; the deprecated `lib/externallib.php` is no longer included (this also makes the PHPUnit tests runnable on Moodle 5.1).

## 0.4.2
- The check for an existing AI Agent setup now reads the database only (no web service libraries are loaded on install or upgrade).
- PHPUnit: test file loads the web service libraries up front.

## 0.4.1
- Coding style fixes (Moodle code checker): formatting, docblocks, comment style, language string order. `error_log()` replaced by `debugging()`. No functional changes.

## 0.4.0
- The AI Agent web service is now opt-in: it is no longer created on install
  or upgrade. An administrator sets it up with **Set up AI Agent access** on
  the settings page (confirmation page lists what is created). Sites that
  already have the setup keep working, and upgrades still keep it up to date.
- Added PHPUnit tests (token client, privacy provider, AI Agent setup).

## 0.3.1
- Documentation: new README for administrators, version history moved here.
- Added `@package`, `@copyright` and `@license` tags to all PHP files.
- Added the missing `MOODLE_INTERNAL` guard to `classes/legacy_migration.php`.
- Maturity changed to beta; added the `supported` Moodle range.
- Language strings now refer to Chronify.Support; clearer descriptions of the
  Supabase settings.

## 0.3.0
- Plugin renamed from `local_helpdesk` to `local_chronifysupport`
  (Chronify.Support). Settings and the AI Agent service/role/account of an
  existing `local_helpdesk` install are carried over on first install.

## 0.2.x (AI Agent web service)
- 0.2.0: automatic provisioning of the AI Agent web service (web services and
  REST enabled, external service, service account, token).
- 0.2.1: service account uses `auth=manual` with a random password (Moodle
  refuses web service access for `nologin` accounts).
- 0.2.2: role gets `webservice/rest:use`.
- 0.2.3: role gets `moodle/site:viewuseridentity` and `moodle/user:viewalldetails`
  (email lookup returned empty results without them).
- 0.2.4: role gets `moodle/course:viewparticipants` (course list was filtered).
- 0.2.5: role gets `report/completion:view` (course completion status).
- 0.2.6: grade items function and `gradereport/user:view`, `moodle/grade:viewall`.
- 0.2.7: optional certificate functions (`mod_customcert`, `mod_certificate`),
  attached only when the function exists on the site.
- 0.2.8: role gets `moodle/course:view` (service account is not enrolled in
  courses).
- 0.2.9: `core_course_get_contents` added to the service.
- 0.2.10: Supabase URL and anon key pre-filled on install and upgrade.
- 0.2.13 to 0.2.18: more read-only functions for the AI Agent (course
  search, course categories and enrolment methods, assignments, quizzes,
  forums, badges, groups, site info).

## Earlier releases
- Initial versions: Get Help button, Tier-1/Tier-2 token exchange, floating
  panel with the embedded widget, silent token refresh.
