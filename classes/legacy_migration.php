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
 * One-time migration from the previous local_helpdesk plugin name.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * One-time, best-effort migration from the plugin's previous name (local_helpdesk).
 *
 * The plugin used to be installed as local_helpdesk. Moodle treats a renamed
 * plugin as a brand-new one, so on a site that already ran local_helpdesk this
 * class (called once from db/install.php) carries everything over so the site
 * keeps working without any manual re-configuration:
 *
 *  - every plugin setting (client ID, client secret, switches, Supabase
 *    values, ...) is copied from the old component, never overwriting a value
 *    that is already set under the new component;
 *  - the AI Agent web service, its role and its service account are renamed in
 *    place (same database rows, so the existing token and its authorisation
 *    keep working and nothing has to be pasted into the Chronify.Support
 *    admin console again).
 *
 * Nothing here ever throws: any failure is logged and the normal install goes
 * on, because provisioning (see ai_agent_provisioner) can still create the
 * objects from scratch. Nothing is deleted. The old plugin's leftover settings
 * stay in place until an administrator removes the old plugin.
 *
 * @package    local_chronifysupport
 */
class legacy_migration {
    /** @var string Component name before the rename. */
    const OLD_COMPONENT = 'local_helpdesk';

    /** @var string AI Agent web service short name before the rename. */
    const OLD_SERVICE_SHORTNAME = 'local_helpdesk_ai_agent';

    /** @var string AI Agent role short name before the rename. */
    const OLD_ROLE_SHORTNAME = 'local_helpdesk_ai_agent';

    /** @var string AI Agent service account username before the rename. */
    const OLD_SERVICE_USERNAME = 'helpdesk-ai-service';

    /**
     * Runs the whole migration. Safe to call repeatedly.
     */
    public static function run(): void {
        foreach (['copy_settings', 'adopt_ai_agent_objects'] as $step) {
            try {
                self::$step();
            } catch (\Throwable $e) {
                debugging(
                    'local_chronifysupport legacy migration step ' . $step . ' failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * True when the previous plugin's code is still on disk, i.e. both the old
     * and the new plugin would run at once.
     *
     * @return bool
     */
    public static function old_plugin_present(): bool {
        return \core_component::get_component_directory(self::OLD_COMPONENT) !== null;
    }

    /**
     * Copies every setting of the old component that is not yet set here.
     */
    protected static function copy_settings(): void {
        $old = get_config(self::OLD_COMPONENT);
        if (empty($old)) {
            return;
        }
        foreach ((array) $old as $name => $value) {
            // The 'version' row is Moodle's own bookkeeping row for the old plugin.
            if ($name === 'version') {
                continue;
            }
            if (get_config('local_chronifysupport', $name) === false) {
                set_config($name, $value, 'local_chronifysupport');
            }
        }
    }

    /**
     * Renames the old AI Agent service, role and service account in place.
     * Each object is only touched when its exact old identifier exists and
     * no object with the new identifier exists yet.
     */
    protected static function adopt_ai_agent_objects(): void {
        global $DB, $CFG;

        // Web service: keeps its id, so function list, authorised users and tokens stay attached.
        $oldservice = $DB->get_record('external_services', ['shortname' => self::OLD_SERVICE_SHORTNAME]);
        if (
            $oldservice &&
            !$DB->record_exists('external_services', ['shortname' => ai_agent_provisioner::SERVICE_SHORTNAME])
        ) {
            $DB->update_record('external_services', (object) [
                'id' => $oldservice->id,
                'name' => ai_agent_provisioner::SERVICE_NAME,
                'shortname' => ai_agent_provisioner::SERVICE_SHORTNAME,
                'timemodified' => time(),
            ]);
        }

        // Role: keeps its id, so capabilities and assignments stay.
        $oldrole = $DB->get_record('role', ['shortname' => self::OLD_ROLE_SHORTNAME]);
        if ($oldrole && !$DB->record_exists('role', ['shortname' => ai_agent_provisioner::ROLE_SHORTNAME])) {
            $DB->update_record('role', (object) [
                'id' => $oldrole->id,
                'name' => ai_agent_provisioner::ROLE_NAME,
                'shortname' => ai_agent_provisioner::ROLE_SHORTNAME,
            ]);
        }

        // Service account: only the account this plugin created (exact username + placeholder names).
        $olduser = $DB->get_record('user', [
            'username' => self::OLD_SERVICE_USERNAME,
            'firstname' => 'Help Desk',
            'lastname' => 'AI Agent',
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if (
            $olduser &&
            !$DB->record_exists('user', [
                'username' => ai_agent_provisioner::SERVICE_USERNAME,
                'mnethostid' => $CFG->mnet_localhost_id,
            ])
        ) {
            $update = (object) [
                'id' => $olduser->id,
                'username' => ai_agent_provisioner::SERVICE_USERNAME,
                'firstname' => ai_agent_provisioner::SERVICE_FIRSTNAME,
                'lastname' => ai_agent_provisioner::SERVICE_LASTNAME,
                'timemodified' => time(),
            ];
            // The generated e-mail is derived from the username; only follow it when it still has the generated shape.
            if (strpos((string) $olduser->email, self::OLD_SERVICE_USERNAME . '@') === 0) {
                $update->email = ai_agent_provisioner::SERVICE_USERNAME .
                    substr($olduser->email, strlen(self::OLD_SERVICE_USERNAME));
            }
            $DB->update_record('user', $update);
        }
    }
}
