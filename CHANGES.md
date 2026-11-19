# Changes

## 0.5.20
- The chat on the login page is now dropped after 30 minutes in which that page was not seen in the tab, for example when the computer slept or the browser stayed open for a long time. Before, it was dropped only when the browser was closed, so after sleep a visitor could find an old chat that had started earlier. The check runs when the login page loads and again when the page wakes up, and it works on Moodle versions that clear localStorage on every page load (the time is kept in sessionStorage). Signed-in users and the short sign-out continuation (5 minutes) are not affected.

## 0.5.19
- Signing out of Moodle no longer drops the chat at once: for up to 5 minutes (idle) the login page of the same tab continues the conversation, as in 0.5.17. Unlike before, the chat is kept only while the login page is being used: if the tab is closed and restored, the browser is reopened or the login page was not seen for 10 seconds, the previous user's chat is dropped and a clean chat starts. The window is 5 minutes instead of 20. The login page now also records its last-seen time per tab (`local_chronifysupport_anon_seen_tab`), which works on Moodle versions that clear localStorage on every page load.

## 0.5.18
- Intermediate build (chat always reset after sign-out); superseded by 0.5.19.

## 0.5.17
- When the chat panel is opened again automatically while a page loads (for example right after logging in), the page content and the fixed header are now narrowed at once, without the short animation, and the theme is told to re-measure after the page has loaded. Before, some themes kept a header that was too wide, so its right-hand items (profile menu, notifications) were hidden under the panel. Opening the panel with the button works as before.

## 0.5.16
- A chat panel that the visitor left open on the login page now stays open after logging in, also when a different user was signed in earlier in the same browser (on Moodle versions that keep the browser's local storage between page loads). The previous user's stored chat session and chat state are still dropped as before.

## 0.5.15
- On the login page the support chat panel no longer closes when the visitor clicks into the username or password field. On screens 900 px wide or more the page stays usable next to the panel (no dimming layer, clicks reach the form) and the panel closes only when the visitor minimizes it. On narrower screens a click outside the panel still closes it, as before. After logging in, the open panel continues as before.

## 0.5.14
- The chat started from the login page now survives page reloads on Moodle versions that empty the browser's local storage while a page loads. The chat session is also kept for the current tab (sessionStorage) and restored from there; it is cleared in the same situations as before (browser closed, new tab, signed-in user, idle or expired session).
- The temporary console diagnostics of 0.5.12 and 0.5.13 and the reload exception of 0.5.11 are removed again.

## 0.5.13
- Temporary diagnostics: on the login page the browser console now also reports, with a call stack, whenever the stored chat session is changed or removed (`[chronify] storage ...`). No change in behaviour. Removed again in the next release.

## 0.5.12
- Temporary diagnostics: the login page script that decides whether the support chat is kept now writes a few `[chronify]` lines to the browser console. No change in behaviour. Removed again in the next release.

## 0.5.11
- On the login page, reloading the page (F5) several times in a row no longer drops the support chat. Closing and reopening the browser, opening a new tab and the other existing rules still reset it as before.

## 0.5.10
- Security: the chat started from the login page is now bound to the browser as well as to the e-mail address. Entering someone else's e-mail address no longer opens that person's conversation; the same visitor using the same browser still resumes the chat. A visitor who uses another browser or device starts a new chat.
- Security: the cookie that remembers a login-page chat until the visitor logs in is now signed, and only cookies signed by this site are merged into the Moodle account. The old `local_helpdesk_pending_anon` cookie is no longer read. The e-mail address is no longer stored in that cookie.
- A random signing secret is created in the plugin settings on upgrade (or on first use). It is never sent to the browser.
- Open login-page chats that were started before this update continue as a new chat; their tickets stay visible to agents.

## 0.5.9
- On the login page, when the browser has no stored chat, any conversation still attached to this browser on the service side (for example left over from an older session) is now detached once per tab, so the visitor no longer shows as "currently served" without a visible chat.

## 0.5.8
- When the support chat on the login page is dropped (new tab, browser reopened, 20 minutes without activity, or support session expiry), the browser is now also detached from that conversation on the service side. The visitor then no longer appears as "currently served" for a chat they cannot see, and agent replies reach them by email. This uses the new service function `clear_visitor_conversation`.

## 0.5.7
- The chat kept on the login page after logout is dropped immediately in any other tab (a new tab, or after the browser is reopened), even within the first 10 seconds.

## 0.5.6
- After logging out of Moodle, the support chat now stays visible on the login page in the same browser tab, so the person can keep talking to the agent. It is cleared after 20 minutes without mouse or keyboard activity, when the support session expires (it is not renewed after logout), and never survives closing the tab or browser (it only uses sessionStorage).
- If a different Moodle user logs in, the previous user's stored widget session is dropped, so a conversation is never carried over to another person on a shared computer.
- A copy of the support session is now kept in the tab (sessionStorage) even when live page tracking is off.

## 0.5.4
- For logged-out visitors, the previous widget conversation is now reset when the login page is reopened more than 10 seconds after it was closed (was 20 seconds), in browsers that restore the session after closing.

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
