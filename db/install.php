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
 * Install-time hook for local_chronifysupport.
 *
 * Runs once, the first time this plugin is installed on a Moodle site.
 * Does not create the optional AI Agent web service: the administrator
 * sets it up explicitly on the plugin settings page (setup_ai_agent.php).
 *
 * Fills in the connection settings with their defaults (see classes/connection_defaults.php).
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs once when the plugin is first installed.
 *
 * @return bool
 */
function xmldb_local_chronifysupport_install() {
    // Renamed from local_helpdesk: carry settings and AI Agent objects over
    // (best-effort, never throws, no-op on a fresh site).
    \local_chronifysupport\legacy_migration::run();

    // The AI Agent web service is NOT created here: the administrator sets it
    // up on the plugin settings page. If the migration above adopted an
    // existing setup, bring it up to date.
    \local_chronifysupport\ai_agent_provisioner::refresh_if_provisioned();

    // Pre-fill the connection settings (Chronify.Support app address and the
    // service values used by the optional browser features) with their defaults.
    // Values that are already set are never changed.
    \local_chronifysupport\connection_defaults::apply_missing();

    return true;
}
