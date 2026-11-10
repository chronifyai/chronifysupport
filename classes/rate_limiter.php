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
 * Small per-address request throttle for the public endpoints.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chronifysupport;

/**
 * Small per-address request throttle for the public endpoints.
 *
 * Counts recent requests per area and address in a Moodle application cache
 * (see db/caches.php). It fails open: if the cache cannot be used the request
 * is allowed, so an infrastructure problem never blocks a real visitor.
 */
class rate_limiter {
    /**
     * Records a request and tells whether it is within the allowed rate.
     *
     * @param string $area Short name of the endpoint (letters, digits and underscores).
     * @param string $address Address of the client.
     * @param int $maxrequests Maximum requests allowed per window.
     * @param int $windowseconds Length of the window in seconds (at most the cache lifetime, 600).
     * @return bool True if the request is allowed.
     */
    public static function allow(string $area, string $address, int $maxrequests, int $windowseconds = 600): bool {
        try {
            $cache = \cache::make('local_chronifysupport', 'ratelimit');
            $key = $area . '_' . hash('sha256', $address);
            $now = time();

            $timestamps = $cache->get($key);
            if (!is_array($timestamps)) {
                $timestamps = [];
            }

            // Keep only requests that are still inside the window.
            $timestamps = array_values(array_filter($timestamps, function ($t) use ($now, $windowseconds) {
                return is_numeric($t) && ($now - $t) < $windowseconds;
            }));

            if (count($timestamps) >= $maxrequests) {
                return false;
            }

            $timestamps[] = $now;
            $cache->set($key, $timestamps);
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
