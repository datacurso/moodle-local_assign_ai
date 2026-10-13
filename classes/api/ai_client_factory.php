<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_assign_ai\api;

use aiprovider_datacurso\httpclient\ai_services_api;

/**
 * Factory for the Datacurso HTTP client used by {@see client}.
 *
 * It is resolved through the DI container so tests can replace the HTTP client and inspect
 * the exact outbound payload, while production keeps creating one fresh client per request.
 *
 * @package     local_assign_ai
 * @copyright   2026 Datacurso
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_client_factory {
    /**
     * Create a new Datacurso AI services HTTP client.
     *
     * @return ai_services_api
     */
    public function create(): ai_services_api {
        return new ai_services_api();
    }
}
