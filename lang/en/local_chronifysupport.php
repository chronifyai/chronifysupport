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
 * English language strings for local_chronifysupport.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ai_agent_heading'] = 'AI Agent course-completion check';
$string['ai_agent_heading_desc'] = 'When a ticket mentions an email address or asks about a course, login or certificate, the Chronify.Support AI agent can look up the Moodle account, check course enrolment, completion and grades, and draft a reply. This is optional and is not set up automatically: use the button below to set it up. The setup enables web services and the REST protocol, creates a read-only external service, a dedicated role and a service account, and generates a token. Afterwards you copy the token into the Chronify.Support admin console and make sure this site\'s address is reachable from the internet.';
$string['ai_agent_manual_steps_note'] = 'Still manual: paste this token into the Chronify.Support Admin Console (Installations → Settings → "Moodle webservice token"), and confirm this site\'s base URL is reachable from the public internet — the helpdesk server cannot verify that from inside Moodle.';
$string['ai_agent_regenerate_button'] = 'Regenerate token';
$string['ai_agent_setup_button'] = 'Set up AI Agent access';
$string['ai_agent_setup_confirm'] = 'This will make the following changes on this site: enable web services and the REST protocol (if they are off); create an external service with read-only functions (user lookup, enrolled courses, course contents, completion status, grade items, assignments, quizzes, forums, badges, groups, site information and, if installed, certificate lookups); create a role with view-only capabilities and a service account named chronifysupport-ai-service; and generate a web service token for that account. The token gives the Chronify.Support AI agent read access to user, course and grade data. Continue?';
$string['ai_agent_setup_done'] = 'AI Agent access was set up. Copy the token shown on this page into the Chronify.Support Admin Console.';
$string['ai_agent_setup_title'] = 'Set up AI Agent access';
$string['ai_agent_status_error'] = 'AI Agent setup failed: {$a}. You can retry it with "Set up AI Agent access" or "Regenerate token".';
$string['ai_agent_status_not_set_up'] = 'AI Agent access has not been set up on this site. It is optional.';
$string['ai_agent_status_ok'] = 'AI Agent access is set up. Copy the token below into the Chronify.Support Admin Console → Installations → Settings → "Moodle webservice token" field.';
$string['ai_agent_status_pending'] = 'AI Agent access is set up, but no token was found. Use "Regenerate token" below to create one.';
$string['ai_agent_token_label'] = 'AI Agent webservice token';
$string['ai_agent_token_regenerated'] = 'A new AI Agent token was generated. The previous token stopped working immediately — remember to update it in the Chronify.Support Admin Console as well.';
$string['cachedef_ratelimit'] = 'Recent requests to the public support endpoints, used to limit abuse';
$string['client_id'] = 'Client ID';
$string['client_id_desc'] = 'Public client_id issued when this Moodle site was registered as an integration installation in the helpdesk admin console.';
$string['client_secret'] = 'Client secret';
$string['client_secret_desc'] = 'Secret issued once at installation-creation time. Store it securely — it is never shown again by the helpdesk after creation.';
$string['debug_signed_in_as'] = 'Signed in to the helpdesk as {$a->name} ({$a->email}). Session token (debug only):';
$string['enable_anonymous_button'] = 'Show "Get Help" button on the login page (anonymous)';
$string['enable_anonymous_button_desc'] = 'When enabled, a floating "Get Help" button is also shown on the Moodle login page (/login/index.php) for visitors who are not logged in — e.g. someone who forgot their password or is locked out. Clicking it shows a small form asking for their first name, last name, and email; the email is used to identify them (repeat visitors using the same email resume the same conversation). The embedded chat widget itself then asks what they need help with. This creates anonymous helpdesk accounts (not linked to any real Moodle user) and is off by default — enable only if you want unauthenticated visitors to be able to start support conversations.';
$string['enable_button'] = 'Show "Get Help" button';
$string['enable_button_desc'] = 'When enabled, a floating "Get Help" button is added to every page footer for logged-in users.';
$string['enable_live_tracking'] = 'Enable live page tracking';
$string['enable_live_tracking_desc'] = 'When enabled (and once a support conversation has started), a lightweight script pings Supabase with the page title/URL on every page load, so agents can see a visitor\'s last 5 visited pages update live on the ticket. Requires the Supabase project URL and anon key above to be set. Off by default.';
$string['enable_visitor_tracking'] = 'Enable site-wide visitor tracking';
$string['enable_visitor_tracking_desc'] = 'When enabled, a lightweight background script runs on every page for every visitor — logged in or not — recording a heartbeat (browser/OS/device, current page, and, once identified, their helpdesk user) so agents can see who is currently online and message them first. Requires the Supabase project URL and anon key above to be set. Off by default.';
$string['error_exchange_failed'] = 'Could not start a helpdesk session: {$a}';
$string['error_not_configured'] = 'The Chronify.Support plugin is not fully configured yet. An administrator needs to set the helpdesk base URL, client ID, and client secret in Site administration > Plugins > Local plugins > Chronify.Support.';
$string['fast_widget_load'] = 'Faster chat widget start-up';
$string['fast_widget_load_desc'] = 'When enabled, the chat widget starts loading at the same time as the secure session is being prepared, instead of waiting for it, so the first open of the widget is noticeably faster. Requires the current version of the helpdesk app. If the widget ever shows "Please open this chat from your course", switch this off. Off by default.';
$string['get_help_anon_articles_back'] = 'Back to knowledge base';
$string['get_help_anon_articles_empty'] = 'No knowledge base articles yet.';
$string['get_help_anon_articles_error'] = 'Could not load the knowledge base. Please try again later.';
$string['get_help_anon_articles_loading'] = 'Loading knowledge base...';
$string['get_help_anon_email_label'] = 'Your email';
$string['get_help_anon_error'] = 'Something went wrong. Please check your details and try again.';
$string['get_help_anon_firstname_label'] = 'First name';
$string['get_help_anon_form_title'] = 'Get help';
$string['get_help_anon_lastname_label'] = 'Last name';
$string['get_help_anon_minimize'] = 'Minimize chat';
$string['get_help_anon_minimize_offline'] = 'Minimize';
$string['get_help_anon_offline_note'] = 'We\'re currently offline. Leave a message and we\'ll reply as soon as we\'re back.';
$string['get_help_anon_offline_status'] = 'Offline';
$string['get_help_anon_online_status'] = 'Online';
$string['get_help_anon_reassure'] = 'We\'ll only use this to follow up on your request.';
$string['get_help_anon_search_back'] = 'Back to search';
$string['get_help_anon_search_empty'] = 'No matching knowledge base articles.';
$string['get_help_anon_search_error'] = 'Knowledge Base search is unavailable right now.';
$string['get_help_anon_search_loading'] = 'Searching...';
$string['get_help_anon_search_placeholder'] = 'Search knowledge base';
$string['get_help_anon_submit'] = 'Start Chat';
$string['get_help_anon_submit_offline'] = 'Send message';
$string['get_help_anon_submitting'] = 'Starting chat...';
$string['get_help_anon_submitting_offline'] = 'Sending...';
$string['get_help_anon_subtitle'] = 'Tell us a bit about yourself so we can get you to the right place.';
$string['get_help_anon_tab_articles'] = 'Knowledge Base';
$string['get_help_anon_tab_chat'] = 'Chat';
$string['get_help_anon_tab_chat_offline'] = 'Message';
$string['get_help_anon_tab_search'] = 'Search';
$string['get_help_button'] = 'Get Help';
$string['get_help_button_active'] = 'Continue chat';
$string['get_help_button_active_offline'] = 'Continue conversation';
$string['helpdesk_base_url'] = 'Helpdesk base URL';
$string['helpdesk_base_url_desc'] = 'Base URL of the deployed Chronify.Support app itself (e.g. https://helpdesk.example.com) — NOT the Supabase project URL. The plugin calls {$a}/api/public/moodle/tier1-token and {$a}/api/public/moodle/tier2-token.';
$string['live_tracking_heading'] = 'Live page tracking (optional)';
$string['live_tracking_heading_desc'] = 'Lets agents see, in near real time, which pages a logged-in visitor is browsing while their support ticket is open (last 5 pages). This requires the plugin to send small page-view pings directly from the visitor\'s browser to your Supabase project\'s REST API, so it needs its own project URL and anon key below (separate from the helpdesk_base_url above, which points at the app itself, not at Supabase directly).';
$string['pluginname'] = 'Chronify.Support';
$string['privacy:metadata:local_chronifysupport_external'] = 'In order to provide support, user profile data is sent to the Chronify.Support service.';
$string['privacy:metadata:local_chronifysupport_external:course_context'] = 'The name of the course and activity the user was viewing when they requested help.';
$string['privacy:metadata:local_chronifysupport_external:email'] = 'The user\'s email address.';
$string['privacy:metadata:local_chronifysupport_external:external_user_id'] = 'The Moodle user ID (or, for anonymous visitors on the login page, an identifier derived from the email address they typed into the "Get Help" form), used to link the account to a helpdesk account.';
$string['privacy:metadata:local_chronifysupport_external:full_name'] = 'The user\'s full name.';
$string['privacy:metadata:local_chronifysupport_tracking'] = 'When the administrator enables live page tracking or the site-wide visitor list, a background script sends limited browsing context to the help desk service so agents can see which page a visitor is on.';
$string['privacy:metadata:local_chronifysupport_tracking:course'] = 'The course and activity being viewed, if any.';
$string['privacy:metadata:local_chronifysupport_tracking:device'] = 'The browser, operating system and device type.';
$string['privacy:metadata:local_chronifysupport_tracking:page'] = 'The title and address of the page being viewed, and the previous (referring) page.';
$string['privacy:metadata:local_chronifysupport_tracking:role'] = 'The visitor\'s Moodle role and the Moodle version of the site.';
$string['privacy:metadata:local_chronifysupport_tracking:visitor_key'] = 'A random identifier stored in the visitor\'s browser (not their Moodle user ID) used to recognise the same browser between page views.';
$string['privacy:metadata:null_reason'] = 'This plugin does not store any personal data locally in Moodle. See the external location entry below for data sent to the helpdesk service when a user clicks "Get Help". The AI Agent course-completion check feature automatically creates one dedicated service account (username chronifysupport-ai-service, auth=manual with a long random password nobody is given) used only to call Moodle\'s own webservice API — it does not represent a real person and is not subject to personal-data export/delete requests.';
$string['supabase_anon_key'] = 'Supabase anon (public) key';
$string['supabase_anon_key_desc'] = 'The Supabase project\'s anon/public API key. This is the same publishable key already embedded in the helpdesk web app\'s own browser bundle — it is not a secret, and Row Level Security on the Supabase side controls what it can actually read or write. It is pre-filled with the Chronify.Support hosted project key when the plugin is installed. Used by the browser features listed under the Supabase project URL setting.';
$string['supabase_url'] = 'Supabase project URL';
$string['supabase_url_desc'] = 'The Supabase project URL used by the Chronify.Support service (e.g. https://xxxxxxxx.supabase.co). It is pre-filled with the Chronify.Support hosted project when the plugin is installed. Visitors\' browsers contact this address directly, only for the optional live page-tracking, visitor list, availability and knowledge base features. Leave blank if those features are not used.';
$string['visitor_tracking_heading'] = 'Site-wide visitor list (optional)';
$string['visitor_tracking_heading_desc'] = 'Lets agents/admins see, in near real time, everyone currently browsing the site — including visitors who have never started a support conversation and are not logged in — and lets an agent proactively start a chat with any of them ("Visitors" page in the helpdesk app). Requires the Supabase project URL and anon key above to be set.';
$string['widget_loading'] = 'Connecting you to support…';
$string['widget_loading_slow'] = 'Still connecting. Thank you for your patience.';
