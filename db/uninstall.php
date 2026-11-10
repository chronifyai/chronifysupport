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
 * Uninstall hook for local_chronifysupport.
 *
 * Removes the objects this plugin created for the optional AI Agent web
 * service: the external service (with its function list, authorised users
 * and tokens), the dedicated role and the dedicated service account. Plugin
 * settings are removed by Moodle itself. Site-wide web service settings
 * (enablewebservices / enabled protocols) are deliberately left as they are,
 * because other services may depend on them.
 *
 * Every step is best-effort and only touches objects that match this
 * plugin's exact identifiers, so a failure never blocks the uninstall.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Cleans up everything the plugin created outside its own tables.
 *
 * @return bool
 */
function xmldb_local_chronifysupport_uninstall() {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/webservice/lib.php');
    require_once($CFG->dirroot . '/user/lib.php');

    $serviceshortname = \local_chronifysupport\ai_agent_provisioner::SERVICE_SHORTNAME;
    $roleshortname = \local_chronifysupport\ai_agent_provisioner::ROLE_SHORTNAME;
    $serviceusername = \local_chronifysupport\ai_agent_provisioner::SERVICE_USERNAME;

    // 1. External service (also removes its functions, authorised users and tokens).
    try {
        $webservicemanager = new \webservice();
        $service = $webservicemanager->get_external_service_by_shortname($serviceshortname);
        if ($service) {
            $webservicemanager->delete_service($service->id);
        }
    } catch (\Throwable $e) {
        debugging(
            'local_chronifysupport uninstall: could not remove the AI Agent web service: ' . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    // 2. Dedicated role (delete_role also removes its assignments).
    try {
        $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshortname]);
        if ($roleid) {
            delete_role($roleid);
        }
    } catch (\Throwable $e) {
        debugging(
            'local_chronifysupport uninstall: could not remove the AI Agent role: ' . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    // 3. Dedicated service account. Only the account this plugin created is
    // removed: exact username plus the placeholder name it was created with.
    // (Objects that still carry the old local_helpdesk names belong to the old
    // plugin and are deliberately left alone.)
    try {
        $user = $DB->get_record('user', [
            'username' => $serviceusername,
            'firstname' => \local_chronifysupport\ai_agent_provisioner::SERVICE_FIRSTNAME,
            'lastname' => \local_chronifysupport\ai_agent_provisioner::SERVICE_LASTNAME,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if ($user) {
            delete_user($user);
        }
    } catch (\Throwable $e) {
        debugging(
            'local_chronifysupport uninstall: could not remove the AI Agent service account: ' . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }

    // 4. Files that older plugin versions generated inside the plugin folder,
    // in case the folder is left on disk.
    \local_chronifysupport\cleanup::remove_generated_files();

    return true;
}
