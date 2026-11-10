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
 * Version info for local_chronifysupport.
 *
 * Bridges a Moodle site to the Chronify.Support help desk using a Tier-1 /
 * Tier-2 token exchange.
 *
 * @package    local_chronifysupport
 * @copyright  2026 ChronifyAI, LLC (https://chronify.support/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_chronifysupport';
$plugin->version   = 2026110614;       // Version in YYYYMMDDXX format.
$plugin->requires  = 2024042200;       // Moodle 4.4 (needed for the Hooks API used in classes/hook_callbacks.php).
$plugin->supported = [404, 501];
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.5.3';
