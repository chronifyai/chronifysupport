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
 * Privacy provider for local_chronifysupport.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for local_chronifysupport.
 *
 * This plugin keeps no personal data in the Moodle database. It sends the
 * data listed in get_metadata() to the external help desk service when a
 * user opens the help widget, and (when the administrator enables the
 * optional tracking features) to that service's data API. Requests to
 * access or erase that data are therefore handled by the help desk
 * operator, keyed by the user's email address, not through the Moodle
 * Privacy API; the request-level methods below intentionally report that no
 * Moodle-held data exists for any user.
 *
 * Browser-side items used by the widget (not Moodle database data):
 * localStorage keys local_chronifysupport_session, local_chronifysupport_visitor_key and
 * local_chronifysupport_chat_active_*, sessionStorage key local_chronifysupport_panel_open,
 * and the cookies local_chronifysupport_visitor_key and local_chronifysupport_pending_anon
 * (the latter is a session cookie, used for at most 30 minutes, and is only
 * set for logged-out visitors who use the login page form).
 *
 * The plugin also creates one service account (username chronifysupport-ai-service)
 * for the optional AI Agent web service. It does not represent a person and
 * holds no personal data beyond placeholder name and email.
 *
 * @package    local_chronifysupport
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the data this plugin sends to external locations.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'local_chronifysupport_external',
            [
                'external_user_id' => 'privacy:metadata:local_chronifysupport_external:external_user_id',
                'email' => 'privacy:metadata:local_chronifysupport_external:email',
                'full_name' => 'privacy:metadata:local_chronifysupport_external:full_name',
                'course_context' => 'privacy:metadata:local_chronifysupport_external:course_context',
            ],
            'privacy:metadata:local_chronifysupport_external'
        );

        $collection->add_external_location_link(
            'local_chronifysupport_tracking',
            [
                'visitor_key' => 'privacy:metadata:local_chronifysupport_tracking:visitor_key',
                'page' => 'privacy:metadata:local_chronifysupport_tracking:page',
                'course' => 'privacy:metadata:local_chronifysupport_tracking:course',
                'role' => 'privacy:metadata:local_chronifysupport_tracking:role',
                'device' => 'privacy:metadata:local_chronifysupport_tracking:device',
            ],
            'privacy:metadata:local_chronifysupport_tracking'
        );

        return $collection;
    }

    /**
     * No Moodle contexts hold personal data for this plugin.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Nothing to export from Moodle (data is held by the external service).
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * Nothing to delete from Moodle.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * Nothing to delete from Moodle.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

    /**
     * No users have data in any Moodle context for this plugin.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
    }

    /**
     * Nothing to delete from Moodle.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }
}
