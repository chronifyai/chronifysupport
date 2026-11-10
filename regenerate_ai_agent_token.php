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
 * Admin action: regenerate the AI Agent webservice token.
 *
 * Deletes the current permanent token for the dedicated
 * chronifysupport-ai-service account and generates a fresh one, then redirects
 * back to the plugin's settings page where the new token is displayed.
 * Site-admin only, confirmed via sesskey (this is a destructive,
 * irreversible action — the old token stops working immediately).
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

if (!is_siteadmin()) {
    throw new \moodle_exception('nopermissions', 'error', '', 'regenerate AI Agent token');
}

require_sesskey();

\local_chronifysupport\ai_agent_provisioner::regenerate_token();

$settingsurl = new \moodle_url('/admin/settings.php', ['section' => 'local_chronifysupport']);

\core\notification::success(get_string('ai_agent_token_regenerated', 'local_chronifysupport'));

redirect($settingsurl);
