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
 * Removal of files that older plugin versions generated inside the plugin folder.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Removal of files that older plugin versions generated inside the plugin folder.
 *
 * Older releases wrote a settings cache and rate-limit files into classes/.
 * Current releases keep that data in the database and in a Moodle cache, so the
 * leftovers are deleted (best effort) on upgrade and on uninstall.
 */
class cleanup {
    /**
     * Deletes the generated settings cache file and the old rate-limit folders.
     */
    public static function remove_generated_files(): void {
        $classesdir = __DIR__;

        $cachefile = $classesdir . '/anon_config_cache.php';
        if (is_file($cachefile)) {
            @unlink($cachefile);
        }

        foreach (['anon_rate_limit', 'invite_rate_limit', 'anon_refresh_rate_limit'] as $dirname) {
            $dir = $classesdir . '/' . $dirname;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.json') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }
    }
}
