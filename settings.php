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
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Remote-Learner.net Inc (http://www.remote-learner.net)
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new \admin_settingpage('local_o365', get_string('pluginname', 'local_o365'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new \admin_setting_configtext('local_o365/tenant', get_string('settings_tenant', 'local_o365'),
                   get_string('settings_tenant_details', 'local_o365'), '', PARAM_ALPHANUMEXT));

    $settings->add(new \local_o365\form\adminsetting\systemapiuser('local_o365/systemapiuser', get_string('settings_systemapiuser', 'local_o365'),
                   get_string('settings_systemapiuser_details', 'local_o365'), '', PARAM_RAW));

    $settings->add(new \admin_setting_configtext('local_o365/parentsiteuri', get_string('settings_parentsiteuri', 'local_o365'),
                   get_string('settings_parentsiteuri_details', 'local_o365'), 'moodle', PARAM_ALPHANUMEXT));

    $settings->add(new \local_o365\form\adminsetting\sharepointinit('local_o365/initialize', get_string('settings_sharepointinit', 'local_o365'),
                   get_string('settings_sharepointinit_details', 'local_o365'), '', PARAM_RAW));

    $settings->add(new \admin_setting_configcheckbox('local_o365/aadsync', get_string('settings_aadsync', 'local_o365'),
                   get_string('settings_aadsync_details', 'local_o365'), '0'));

    $settings->add(new \local_o365\form\adminsetting\healthcheck('local_o365/healthcheck', get_string('settings_healthcheck', 'local_o365'),
                   get_string('settings_healthcheck_details', 'local_o365'), '0'));
}
