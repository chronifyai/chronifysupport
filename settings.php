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
 * Admin settings for local_chronifysupport.
 *
 * client_id / client_secret are the Tier-1 credentials issued when this
 * Moodle site was registered as an integration_installation row in the
 * helpdesk. client_secret is shown to the admin exactly once by the
 * helpdesk's own admin console at installation-creation time — paste it
 * here and it is never displayed by the helpdesk again.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_chronifysupport', get_string('pluginname', 'local_chronifysupport'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_chronifysupport/helpdesk_base_url',
        get_string('helpdesk_base_url', 'local_chronifysupport'),
        get_string('helpdesk_base_url_desc', 'local_chronifysupport'),
        \local_chronifysupport\connection_defaults::HELPDESK_BASE_URL,
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_chronifysupport/client_id',
        get_string('client_id', 'local_chronifysupport'),
        get_string('client_id_desc', 'local_chronifysupport'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_chronifysupport/client_secret',
        get_string('client_secret', 'local_chronifysupport'),
        get_string('client_secret_desc', 'local_chronifysupport'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_chronifysupport/enable_button',
        get_string('enable_button', 'local_chronifysupport'),
        get_string('enable_button_desc', 'local_chronifysupport'),
        1
    ));

    // Off by default: this opens a new, unauthenticated entry point into
    // the helpdesk (see get_help_anonymous.php), so it requires an explicit admin
    // opt-in rather than inheriting enable_button's default-on behaviour.
    $settings->add(new admin_setting_configcheckbox(
        'local_chronifysupport/enable_anonymous_button',
        get_string('enable_anonymous_button', 'local_chronifysupport'),
        get_string('enable_anonymous_button_desc', 'local_chronifysupport'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_chronifysupport/live_tracking_heading',
        get_string('live_tracking_heading', 'local_chronifysupport'),
        get_string('live_tracking_heading_desc', 'local_chronifysupport')
    ));

    // Only needed for the optional live page-tracking feature: the
    // always-on script injected by hook_callbacks.php calls the Supabase
    // REST API directly (browser -> Supabase, no Moodle server involved)
    // to record page_view events, exactly the same way the embedded widget
    // itself already talks to Supabase from the browser. The anon key is
    // Supabase's public/publishable client key (RLS enforces what it can
    // actually do), not a secret — safe to store as plain config text, same
    // trust level as client_id above (client_secret is the only value here
    // that actually needs to stay hidden).
    //
    // The defaults below are the real connection values of the Chronify.Support
    // service, not placeholders (see classes/connection_defaults.php). Site
    // administrators have no access to that service and could not obtain them
    // themselves. They are also written when the plugin is installed, so a
    // new installation comes pre-filled. They are not a secret: the anon key is
    // the public client key of the service.
    $settings->add(new admin_setting_configtext(
        'local_chronifysupport/supabase_url',
        get_string('supabase_url', 'local_chronifysupport'),
        get_string('supabase_url_desc', 'local_chronifysupport'),
        \local_chronifysupport\connection_defaults::SUPABASE_URL,
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_chronifysupport/supabase_anon_key',
        get_string('supabase_anon_key', 'local_chronifysupport'),
        get_string('supabase_anon_key_desc', 'local_chronifysupport'),
        \local_chronifysupport\connection_defaults::SUPABASE_ANON_KEY,
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_chronifysupport/enable_live_tracking',
        get_string('enable_live_tracking', 'local_chronifysupport'),
        get_string('enable_live_tracking_desc', 'local_chronifysupport'),
        0
    ));

    // Off by default: loads the chat widget in parallel with the (slower)
    // token exchange instead of after it. Requires the matching widget
    // release (deferred-token start-up) to be live on the helpdesk app.
    $settings->add(new admin_setting_configcheckbox(
        'local_chronifysupport/fast_widget_load',
        \local_chronifysupport\hook_callbacks::string_or('fast_widget_load', 'Faster chat widget start-up'),
        \local_chronifysupport\hook_callbacks::string_or(
            'fast_widget_load_desc',
            'Starts the chat widget loading at the same time as the secure session is prepared, ' .
                'so the first open is faster. Off by default.'
        ),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_chronifysupport/visitor_tracking_heading',
        get_string('visitor_tracking_heading', 'local_chronifysupport'),
        get_string('visitor_tracking_heading_desc', 'local_chronifysupport')
    ));

    // Off by default, same rationale as enable_anonymous_button above: this
    // unconditionally injects a background script into every single page
    // view on the site (see hook_callbacks::maybe_inject_visitor_beacon()),
    // including for logged-out visitors, and grants the anon Supabase role
    // write access to a new table via record_visitor_heartbeat() — an
    // explicit admin opt-in, not an inherited default. Reuses the same
    // supabase_url/supabase_anon_key settings configured above for the live
    // page-tracking feature; no separate URL/key fields needed here.
    $settings->add(new admin_setting_configcheckbox(
        'local_chronifysupport/enable_visitor_tracking',
        get_string('enable_visitor_tracking', 'local_chronifysupport'),
        get_string('enable_visitor_tracking_desc', 'local_chronifysupport'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_chronifysupport/ai_agent_heading',
        get_string('ai_agent_heading', 'local_chronifysupport'),
        get_string('ai_agent_heading_desc', 'local_chronifysupport')
    ));

    // Everything below is read-only status, built fresh on every page load —
    // nothing here is itself a stored config value (the token lives in
    // Moodle's own external_tokens table, read live via
    // ai_agent_provisioner::get_current_token() so it is never cached or
    // duplicated in plugin config).
    global $OUTPUT;

    $aiagentinfo = '';
    $provisionerror = get_config('local_chronifysupport', 'ai_agent_provision_error');
    $provisioned = \local_chronifysupport\ai_agent_provisioner::is_provisioned();

    if ($provisionerror) {
        $aiagentinfo .= $OUTPUT->notification(
            get_string('ai_agent_status_error', 'local_chronifysupport', s($provisionerror)),
            'notifyproblem'
        );
    }

    if ($provisioned) {
        if (!$provisionerror) {
            $aiagenttoken = \local_chronifysupport\ai_agent_provisioner::get_current_token();
            if ($aiagenttoken) {
                $aiagentinfo .= $OUTPUT->notification(get_string('ai_agent_status_ok', 'local_chronifysupport'), 'notifysuccess');
                $aiagentinfo .= html_writer::tag('p', html_writer::tag('code', s($aiagenttoken)));
            } else {
                $aiagentinfo .= $OUTPUT->notification(
                    get_string('ai_agent_status_pending', 'local_chronifysupport'),
                    'notifymessage'
                );
            }
        }

        $regenerateurl = new moodle_url('/local/chronifysupport/regenerate_ai_agent_token.php', ['sesskey' => sesskey()]);
        $aiagentinfo .= html_writer::link(
            $regenerateurl,
            get_string('ai_agent_regenerate_button', 'local_chronifysupport'),
            ['class' => 'btn btn-secondary']
        );

        $aiagentinfo .= html_writer::tag('p', get_string('ai_agent_manual_steps_note', 'local_chronifysupport'));
    } else {
        // Opt-in: nothing is created until the administrator asks for it.
        if (!$provisionerror) {
            $aiagentinfo .= $OUTPUT->notification(
                get_string('ai_agent_status_not_set_up', 'local_chronifysupport'),
                'notifymessage'
            );
        }
        $aiagentinfo .= html_writer::link(
            new moodle_url('/local/chronifysupport/setup_ai_agent.php'),
            get_string('ai_agent_setup_button', 'local_chronifysupport'),
            ['class' => 'btn btn-primary']
        );
    }

    $settings->add(new admin_setting_description(
        'local_chronifysupport/ai_agent_token_display',
        get_string('ai_agent_token_label', 'local_chronifysupport'),
        $aiagentinfo
    ));
}
