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
 * Default connection values of the Chronify.Support service.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Default connection values of the Chronify.Support service.
 *
 * These are the defaults of the plugin settings helpdesk_base_url, supabase_url
 * and supabase_anon_key. There is one Chronify.Support deployment serving every
 * site (each site is told apart by its own client ID and secret), so the values
 * are the same for every installation. The anon key is the public client key
 * of the service: access is limited by row-level security on the service side,
 * not by keeping the key secret. Administrators can change all three values
 * on the plugin settings page.
 */
class connection_defaults {
    /** @var string Address of the Chronify.Support app. */
    const HELPDESK_BASE_URL = 'https://app.chronify.support';

    /** @var string Address of the service used directly by visitors' browsers. */
    const SUPABASE_URL = 'https://lbhxpanaggjhgrmgsmlt.supabase.co';

    /** @var string Public (anon) client key of that service. */
    const SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImxiaHhwYW5hZ2dqaGdyb' .
        'WdzbWx0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODcwNTc0NjgsImV4cCI6MjEwMjYzMzQ2OH0.9t0F7bmyfEmmsolnY_mzL6_iU75Qk0T_xmuuvFbIAbo';

    /**
     * Writes the defaults of the three settings that are still empty.
     *
     * Called when the plugin is installed, so that a new installation works
     * without the administrator having to fill in the values. Values that were
     * already set are never changed.
     */
    public static function apply_missing(): void {
        $defaults = [
            'helpdesk_base_url' => self::HELPDESK_BASE_URL,
            'supabase_url' => self::SUPABASE_URL,
            'supabase_anon_key' => self::SUPABASE_ANON_KEY,
        ];
        foreach ($defaults as $name => $value) {
            if (trim((string) get_config('local_chronifysupport', $name)) === '') {
                set_config($name, $value, 'local_chronifysupport');
            }
        }
    }
}
