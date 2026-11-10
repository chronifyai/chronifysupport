# Chronify.Support for Moodle (local_chronifysupport)

Adds a **Get Help** button to your Moodle site. It opens the Chronify.Support
help desk widget in a side panel, so learners can message your support team,
read your knowledge base and follow their requests without leaving the page.
Logged-in users are identified automatically; the button can optionally also
be shown to visitors on the login page.

This plugin is the Moodle half of the integration. You also need a
Chronify.Support account. The help desk itself is a hosted service and is not
part of this plugin.

## Requirements

- Moodle 4.4 or later (the plugin uses the Hooks API). Tested on Moodle 5.1.3 and 5.1.4.
- A Chronify.Support account with this Moodle site registered as an
  installation. Registering the site gives you a **Client ID** and a
  **Client secret** (shown once).
- Your Moodle site must be able to make outgoing HTTPS requests to your
  Chronify.Support address.

## Installation

1. Copy the plugin folder to `local/chronifysupport` in your Moodle directory
   (`public/local/chronifysupport` on Moodle 5.1 and later; the folder name
   must be `chronifysupport`), or install the ZIP file from
   **Site administration > Plugins > Install plugins**.
2. Open **Site administration > Notifications** and complete the upgrade.
3. Go to **Site administration > Plugins > Local plugins > Chronify.Support**
   and fill in:
   - **Helpdesk base URL**: the published address of your Chronify.Support
     app, for example `https://app.chronify.support`.
   - **Client ID** and **Client secret** from your Chronify.Support account.
4. Make sure **Show Get Help button** is enabled, then purge caches
   (**Site administration > Development > Purge caches**).
5. Log in as a normal user. The Get Help button appears in the bottom right
   corner of the page.

## Settings

| Setting | What it does |
| --- | --- |
| Show Get Help button | Shows the button to logged-in users. |
| Show Get Help button on the login page | Lets visitors who are not logged in start a conversation (they enter a name and email). Off by default. |
| Live page tracking | Lets agents see the last pages a user visited while their request is open. Off by default. |
| Site-wide visitor list | Lets agents see who is browsing the site and start a conversation with them. Off by default. |
| Faster widget start-up | Starts loading the widget while the secure session is prepared. Off by default. |
| Supabase project URL and anon key | Connection details for the browser features listed under Privacy. Pre-filled when the plugin is installed. |
| AI Agent course-completion check | Optional integration, see below. |

## AI Agent course-completion check

When enabled in Chronify.Support, the help desk can look up a Moodle account
by email and check course enrolment, completion, grades and similar data in
order to draft replies to support requests. It uses Moodle's own web service
API.

The feature is optional and is **not** set up automatically. To enable it, go
to **Site administration > Plugins > Local plugins > Chronify.Support**, find
the AI Agent section and click **Set up AI Agent access**. A confirmation page
lists the changes, and nothing is created until you confirm. The setup:

- enables web services and the REST protocol;
- creates an external service with a fixed list of read-only functions:
  `core_user_get_users_by_field`, `core_enrol_get_users_courses`,
  `core_completion_get_course_completion_status`,
  `core_completion_get_activities_completion_status`,
  `gradereport_user_get_grade_items`, `core_course_get_contents`,
  `core_course_search_courses`, `core_course_get_courses`,
  `core_course_get_courses_by_field`, `core_course_get_categories`,
  `core_enrol_get_course_enrolment_methods`, `mod_assign_get_assignments`,
  `mod_assign_get_submission_status`, `mod_quiz_get_quizzes_by_courses`,
  `mod_quiz_get_user_attempts`, `mod_forum_get_forum_discussions`,
  `mod_forum_get_discussion_posts`, `core_badges_get_user_badges`,
  `core_group_get_course_user_groups` and `core_webservice_get_site_info`,
  plus the issued-certificates functions of `mod_customcert` and
  `mod_certificate` when one of those plugins is installed;
- creates a dedicated role and a service account `chronifysupport-ai-service`
  (authentication `manual`, with a long random password nobody is given);
- creates a token for that account, shown on the plugin settings page.

The role only contains view capabilities (for example
`moodle/user:viewdetails`, `moodle/user:viewalldetails`,
`moodle/course:view`, `moodle/course:viewparticipants`,
`moodle/grade:viewall`, `gradereport/user:view`, `report/completion:view`,
`mod/assign:viewgrades`, `mod/quiz:viewreports` and
`webservice/rest:use`). Because it can read user, grade and course data, only
use this feature if you want the help desk to have that access. You paste the token into the
Chronify.Support admin console. The **Regenerate token** button on the
settings page replaces the token immediately. Plugin upgrades keep an
existing setup up to date (new functions or capabilities) but never create one. Uninstalling the plugin removes
the service, role and service account it created. Site-wide web service
settings are left unchanged.

## Privacy

The plugin stores no personal data in the Moodle database. When a user opens
the widget, the following is sent to the Chronify.Support service (see the
plugin's privacy provider for the complete list):

- the Moodle user ID, name and email address (for visitors on the login
  page: the name and email they type into the form);
- the current course name, page title and page address.

If you enable live page tracking or the visitor list, visitors' browsers
also send browsing context (current page, browser and device type) directly
to the Supabase project configured in the settings. The Supabase URL and
anon (publishable) key are pre-filled with the Chronify.Support hosted
project. The key is a public client key; access is limited by row-level
security on the service side.

The widget uses these browser items: localStorage keys
`local_chronifysupport_session`, `local_chronifysupport_visitor_key` and
`local_chronifysupport_chat_active_*`; the sessionStorage key
`local_chronifysupport_panel_open`; and the cookies
`local_chronifysupport_visitor_key` and `local_chronifysupport_pending_anon`
(the latter is a session cookie, valid for at most 30 minutes for merging, and
is only set for visitors who use the login page form).

Requests for access to or deletion of this data are handled by the
Chronify.Support operator, using the user's email address.

## Tests

PHPUnit tests are in the `tests` directory and run with the standard Moodle
PHPUnit setup (`vendor/bin/phpunit --testsuite local_chronifysupport_testsuite`).

Every push and pull request also runs the
[moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) workflow in
`.github/workflows/moodle-plugin-ci.yml`: PHP lint, the Moodle code checker,
the PHPDoc checker, plugin validation, upgrade savepoint checks and the PHPUnit
suite, against Moodle 4.4 through 5.1 on PostgreSQL and MariaDB.

## Troubleshooting

- **Button does not appear**: check that Show Get Help button is enabled,
  purge caches, and make sure the page is not one of the plugin's own pages.
- **Configuration or network error in the panel**: check the base URL (it
  must be the published production address, not a preview link), the Client
  ID and secret, and that the installation is active in Chronify.Support.
- **Upgrading from `local_helpdesk`**: install this plugin and run the
  upgrade. Settings and the AI Agent service are carried over; then
  uninstall the old plugin. While both are present the new plugin stays
  silent so the widget never appears twice.

## Support

Contact your Chronify.Support operator.

## License

GNU GPL v3 or later. See the `LICENSE` file.

Version history: see `CHANGES.md`.
