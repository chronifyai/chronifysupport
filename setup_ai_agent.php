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
 * Admin action: set up the optional AI Agent web service.
 *
 * Shows a confirmation page that lists exactly what will be created, and only
 * after the administrator confirms (POST with sesskey) runs the setup.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', \context_system::instance());

$confirm = optional_param('confirm', 0, PARAM_BOOL);
$settingsurl = new \moodle_url('/admin/settings.php', ['section' => 'local_chronifysupport']);

if ($confirm) {
    require_sesskey();

    \local_chronifysupport\ai_agent_provisioner::ensure_provisioned();

    $error = get_config('local_chronifysupport', 'ai_agent_provision_error');
    if ($error) {
        \core\notification::error(get_string('ai_agent_status_error', 'local_chronifysupport', s($error)));
    } else {
        \core\notification::success(get_string('ai_agent_setup_done', 'local_chronifysupport'));
    }
    redirect($settingsurl);
}

$PAGE->set_url(new \moodle_url('/local/chronifysupport/setup_ai_agent.php'));
$PAGE->set_context(\context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('ai_agent_setup_title', 'local_chronifysupport'));
$PAGE->set_heading(get_string('ai_agent_setup_title', 'local_chronifysupport'));

echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('ai_agent_setup_confirm', 'local_chronifysupport'),
    new \single_button(
        new \moodle_url($PAGE->url, ['confirm' => 1, 'sesskey' => sesskey()]),
        get_string('ai_agent_setup_button', 'local_chronifysupport'),
        'post',
        \single_button::BUTTON_PRIMARY
    ),
    $settingsurl
);
echo $OUTPUT->footer();
