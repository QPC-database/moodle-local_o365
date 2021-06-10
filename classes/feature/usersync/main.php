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
 * User sync feature.
 *
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @author Lai Wei <lai.wei@enovation.ie>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

namespace local_o365\feature\usersync;

use local_o365\oauth2\clientdata;
use local_o365\httpclient;
use local_o365\oauth2\systemapiusertoken;
use local_o365\oauth2\token;
use local_o365\rest\azuread;
use local_o365\rest\outlook;
use local_o365\rest\unified;
use local_o365\utils;

require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/local/o365/lib.php');

class main {
    protected $clientdata = null;
    protected $httpclient = null;

    /**
     * Constructor
     *
     * @param clientdata|null $clientdata $clientdata The client data to use for API construction.
     * @param httpclient|null $httpclient $httpclient The HTTP client to use for API construction.
     *
     * @throws \moodle_exception
     */
    public function __construct(clientdata $clientdata = null, httpclient $httpclient = null) {
        $this->clientdata = (!empty($clientdata))
            ? $clientdata
            : clientdata::instance_from_oidc();

        $this->httpclient = (!empty($httpclient))
            ? $httpclient
            : new httpclient();
    }

    /**
     * Determine whether any sync-related options are enabled.
     *
     * @return bool Enabled/disabled.
     */
    public static function is_enabled() {
        $aadsyncenabled = get_config('local_o365', 'aadsync');
        if (empty($aadsyncenabled) || $aadsyncenabled === 'photosynconlogin' || $aadsyncenabled === 'tzsynconlogin') {
            return false;
        }
        return true;
    }

    /**
     * Construct a user API client, accounting for Microsoft Graph API presence, and fall back to system api user if desired.
     *
     * @param bool $forcelegacy If true, force using the legacy API.
     * @return \local_o365\rest\o365api|bool A constructed user API client (unified or legacy), or false if error.
     */
    public function construct_user_api($forcelegacy = false) {
        if ($forcelegacy === true) {
            $uselegacy = true;
        } else {
            $uselegacy = (unified::is_configured() === true) ? false : true;
        }

        if ($uselegacy === true) {
            $tokenresource = azuread::get_tokenresource();
            $token = systemapiusertoken::instance(null, $tokenresource, $this->clientdata, $this->httpclient);
            if (empty($token)) {
                throw new \Exception('No token available for usersync');
            }
            return new azuread($token, $this->httpclient);
        } else {
            $tokenresource = unified::get_tokenresource();
            $token = utils::get_app_or_system_token($tokenresource, $this->clientdata, $this->httpclient);
            if (empty($token)) {
                throw new \Exception('No token available for usersync');
            }
            return new unified($token, $this->httpclient);
        }
    }

    /**
     * Construct a outlook API client using the system API user.
     *
     * @param int $muserid The userid to get the outlook token for. Call with null to retrieve system token.
     * @param boolean $systemfallback Set to true to use system token as fall back.
     * @return \local_o365\rest\o365api|bool A constructed calendar API client (unified or legacy), or false if error.
     */
    public function construct_outlook_api($muserid, $systemfallback = true) {
        $unifiedconfigured = unified::is_configured();
        if ($unifiedconfigured === true) {
            $tokenresource = unified::get_tokenresource();
        } else {
            $tokenresource = outlook::get_tokenresource();
        }

        $token = token::instance($muserid, $tokenresource, $this->clientdata, $this->httpclient);
        if (empty($token) && $systemfallback === true) {
            $token = ($unifiedconfigured === true)
                ? utils::get_app_or_system_token($tokenresource, $this->clientdata, $this->httpclient)
                : systemapiusertoken::instance(null, $tokenresource, $this->clientdata, $this->httpclient);
        }
        if (empty($token)) {
            throw new \Exception('No token available for user #'.$muserid);
        }

        if ($unifiedconfigured === true) {
            $apiclient = new unified($token, $this->httpclient);
        } else {
            $apiclient = new outlook($token, $this->httpclient);
        }
        return $apiclient;
    }

    /**
     * Get information on the app.
     *
     * @return array|null Array of app service information, or null if failure.
     */
    public function get_application_serviceprincipal_info() {
        $apiclient = $this->construct_user_api(false);
        return $apiclient->get_application_serviceprincipal_info();
    }

    /**
     * Assign user to application.
     *
     * @param int $muserid The Moodle user ID.
     * @param string $userobjectid Object ID of user.
     * @return array|null Array of user information, or null if failure.
     */
    public function assign_user($muserid, $userobjectid) {
        // Not supported in unit tests at the moment.
        if (PHPUNIT_TEST) {
            return null;
        }
        $this->mtrace('Assigning Moodle user '.$muserid.' (objectid '.$userobjectid.') to application');

        // Get object ID on first call.
        static $appobjectid = null;
        if (empty($objectid)) {
            $appinfo = $this->get_application_serviceprincipal_info();
            if (empty($appinfo)) {
                return null;
            }
            $appobjectid = (unified::is_configured())
                ? $appinfo['value'][0]['id']
                : $appinfo['value'][0]['objectId'];
        }

        $apiclient = $this->construct_user_api();
        $result = $apiclient->assign_user($muserid, $userobjectid, $appobjectid);
        if (!empty($result['odata.error'])) {
            $error = '';
            $code = '';
            if (!empty($result['odata.error']['code'])) {
                $code = $result['odata.error']['code'];
            }
            if (!empty($result['odata.error']['message']['value'])) {
                $error = $result['odata.error']['message']['value'];
            }
            $user = \core_user::get_user($muserid);
            $this->mtrace('Error assigning users "'.$user->username.'" Reason: '.$code.' '.$error);
        } else {
            $this->mtrace('User assigned to application.');
        }
        return $result;
    }

    /**
     * Assign photo to Moodle user account.
     *
     * @param int $muserid
     * @param object $user
     *
     * @return boolean True on photo updated.
     */
    public function assign_photo($muserid, $user) {
        global $DB, $CFG;

        require_once("$CFG->libdir/gdlib.php");
        $record = $DB->get_record('local_o365_appassign', array('muserid' => $muserid));
        $photoid = '';
        if (!empty($record->photoid)) {
            $photoid = $record->photoid;
        }
        $result = false;
        $apiclient = $this->construct_outlook_api($muserid, true);
        if (empty($user)) {
            $o365user = \local_o365\obj\o365user::instance_from_muserid($muserid);
            $user = $o365user->upn;
        }
        $size = $apiclient->get_photo_metadata($user);
        $muser = \core_user::get_user($muserid, 'id, picture', MUST_EXIST);
        $context = \context_user::instance($muserid, MUST_EXIST);
        // If there is no meta data, there is no photo.
        if (empty($size)) {
            // Profile photo has been deleted.
            if (!empty($muser->picture)) {
                // User has no photo. Deleting previous profile photo.
                $fs = \get_file_storage();
                $fs->delete_area_files($context->id, 'user', 'icon');
                $DB->set_field('user', 'picture', 0, array('id' => $muser->id));
            }
            $result = false;
        } else if ($size['@odata.mediaEtag'] !== $photoid) {
            if (!empty($size['height']) && !empty($size['width'])) {
                $image = $apiclient->get_photo($user, $size['height'], $size['width']);
            } else {
                $image = $apiclient->get_photo($user);
            }

            // Check if json error message was returned.
            if ($image && !preg_match('/^{/', $image)) {
                // Update profile picture.
                $tempfile = tempnam($CFG->tempdir.'/', 'profileimage').'.jpg';
                if (!$fp = fopen($tempfile, 'w+b')) {
                    @unlink($tempfile);
                    return false;
                }
                fwrite($fp, $image);
                fclose($fp);
                $newpicture = process_new_icon($context, 'user', 'icon', 0, $tempfile);
                $photoid = $size['@odata.mediaEtag'];
                if ($newpicture != $muser->picture) {
                    $DB->set_field('user', 'picture', $newpicture, array('id' => $muser->id));
                    $result = true;
                }
                @unlink($tempfile);
            }
        }
        if (empty($record)) {
            $record = new \stdClass();
            $record->muserid = $muserid;
            $record->assigned = 0;
        }
        $record->photoid = $photoid;
        $record->photoupdated = time();
        if (empty($record->id)) {
            $DB->insert_record('local_o365_appassign', $record);
        } else {
            $DB->update_record('local_o365_appassign', $record);
        }

        return $result;
    }

    /**
     * Sync timezone of user from Outlook to Moodle.
     *
     * @param $muserid
     * @param $user
     */
    public function sync_timezone($muserid, $user) {
        $tokenresource = unified::get_tokenresource();
        $token = utils::get_app_or_system_token($tokenresource, $this->clientdata, $this->httpclient);
        if (empty($token)) {
            throw new \Exception('No token available for usersync');
        }
        $apiclient = new unified($token, $this->httpclient);
        if (empty($user)) {
            $o365user = \local_o365\obj\o365user::instance_from_muserid($muserid);
            $user = $o365user->upn;
        }
        $remotetimezone = $apiclient->get_user_timezone_by_upn($user);
        if (is_array($remotetimezone) && !empty($remotetimezone['value'])) {
            $remotetimezonesetting = $remotetimezone['value'];
            $moodletimezone = \core_date::normalise_timezone($remotetimezonesetting);
            if ($moodletimezone) {
                $existinguser = \core_user::get_user($muserid);
                $existinguser->timezone = $moodletimezone;
                user_update_user($existinguser, false, true);
            }
        }
    }

    /**
     * Extract a parameter value from a URL.
     *
     * @param string $link A URL.
     * @param string $param Parameter name.
     * @return string|null The extracted deltalink value, or null if none found.
     */
    protected function extract_param_from_link($link, $param) {
        $link = parse_url($link);
        if (isset($link['query'])) {
            $output = [];
            parse_str($link['query'], $output);
            if (isset($output[$param])) {
                return $output[$param];
            }
        }
        return null;
    }

    /**
     * Get AAD data for a single user.
     *
     * @param string $objectid
     * @param bool $guestuser if the user is a guest user in Azure AD
     *
     * @return array|null Array of user information, or null if failure.
     */
    public function get_user(string $objectid, bool $guestuser = false) {
        $apiclient = $this->construct_user_api();
        $result = $apiclient->get_user($objectid, $guestuser);
        if (!empty($result) && is_array($result)) {
            return $result;
        }
        return [];
    }

    /**
     * Get all users in the configured directory.
     *
     * @param string|array $params Requested user parameters.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     *
     * @return array Array of user information.
     */
    public function get_users($params = 'default', $skiptoken = '') {
        if (empty($skiptoken)) {
            $skiptoken = '';
        }

        $apiclient = $this->construct_user_api(false);
        $result = $apiclient->get_users($params, $skiptoken);
        $users = [];
        $skiptoken = null;

        if (!empty($result) && is_array($result)) {
            if (!empty($result['value']) && is_array($result['value'])) {
                $users = $result['value'];
            }

            if (isset($result['odata.nextLink'])) {
                $skiptoken = $this->extract_param_from_link($result['odata.nextLink'], '$skiptoken');
            } else if (isset($result['@odata.nextLink'])) {
                $skiptoken = $this->extract_param_from_link($result['@odata.nextLink'], '$skiptoken');
            }
        }

        return [$users, $skiptoken];
    }

    public function get_users_delta($params = 'default', $skiptoken = null, $deltatoken = null) {
        $tokenresource = unified::get_tokenresource();
        $token = utils::get_app_or_system_token($tokenresource, $this->clientdata, $this->httpclient);
        if (empty($token)) {
            throw new \Exception('No token available for usersync');
        }
        $apiclient = new unified($token, $this->httpclient);
        return $apiclient->get_users_delta($params, $skiptoken, $deltatoken);
    }

    /**
     * Return the name of the manager of the Microsoft 365 user with the given oid.
     *
     * @param $userobjectid
     *
     * @return mixed|string
     */
    public function get_user_manager($userobjectid) {
        $apiclient = $this->construct_user_api();
        $result = $apiclient->get_user_manager($userobjectid);
        if ($result && isset($result['displayName'])) {
            return $result['displayName'];
        } else {
            return '';
        }
    }

    /**
     * Return the names of groups that the Microsoft 365 user with the given oid are in, joined by comma.
     *
     * @param $userobjectid
     *
     * @return string
     */
    public function get_user_groups($userobjectid) {
        $apiclient = $this->construct_user_api();
        $results = $apiclient->get_user_groups($userobjectid);
        $groups = [];
        foreach ($results as $result) {
            $groups[] = $result['displayName'];
        }
        return join(',', $groups);
    }

    /**
     * Return the names of teams that the Microsoft 365 user with the given oid are in, joined by comma.
     *
     * @param $userobjectid
     *
     * @return string
     */
    public function get_user_teams($userobjectid) {
        $apiclient = $this->construct_user_api();
        $results = $apiclient->get_user_teams($userobjectid);
        $teams = [];
        foreach ($results as $result) {
            $teams[] = $result['displayName'];
        }
        return join(',', $teams);
    }

    /**
     * Return the names of roles that the Microsoft 365 user with the given oid has, joined by comma.
     *
     * @param $userobjectid
     *
     * @return string
     */
    public function get_user_roles($userobjectid) {
        $apiclient = $this->construct_user_api();
        $objectsids = $apiclient->get_user_objects($userobjectid);
        $roles = [];
        if ($objectsids) {
            $results = $apiclient->get_directory_objects($objectsids);
            foreach ($results as $result) {
                if (stripos($result['@odata.type'], 'role') !== FALSE) {
                    $roles[] = $result['displayName'];
                }
            }
        }

        return join(',', $roles);
    }

    /**
     * Return the preferred name of the Microsoft 365 user with the given oid.
     *
     * @param $userobjectid
     *
     * @return mixed
     */
    public function get_preferred_name($userobjectid) {
        $apiclient = $this->construct_user_api();
        $result = $apiclient->get_user($userobjectid);
        if (isset($result['preferredName'])) {
            return $result['preferredName'];
        }
    }

    /**
     * Apply the configured field map.
     *
     * @param array $aaddata User data from Azure AD.
     * @param \stdClass $user Moodle user data.
     * @param string $eventtype 'login', or 'create'
     *
     * @return \stdClass Modified Moodle user data.
     */
    public static function apply_configured_fieldmap(array $aaddata, \stdClass $user, $eventtype) {
        $fieldmaps = get_config('local_o365', 'fieldmap');
        if ($fieldmaps === false) {
            $fieldmaps = \local_o365\adminsetting\usersyncfieldmap::defaultmap();
        } else {
            $fieldmaps = @unserialize($fieldmaps);
            if (!is_array($fieldmaps)) {
                $fieldmaps = \local_o365\adminsetting\usersyncfieldmap::defaultmap();
            }
        }
        if (unified::is_configured() && (array_key_exists('id', $aaddata) && $aaddata['id'])) {
            $objectidfieldname = 'id';
            $userobjectid = $aaddata['id'];
        } else {
            $objectidfieldname = 'objectId';
            $userobjectid = $aaddata['objectId'];
        }
        $usersync = new \local_o365\feature\usersync\main();
        foreach ($fieldmaps as $fieldmap) {
            $fieldmap = explode('/', $fieldmap);
            if (count($fieldmap) !== 3) {
                continue;
            }
            list($remotefield, $localfield, $behavior) = $fieldmap;
            if ($remotefield == 'objectId') {
                $remotefield = $objectidfieldname;
            }
            if ($behavior !== 'on' . $eventtype && $behavior !== 'always') {
                // Field mapping doesn't apply to this event type.
                continue;
            }
            if (isset($aaddata[$remotefield])) {
                if ($localfield == "country") {
                    // Update country with two letter country code.
                    $incoming = strtoupper($aaddata[$remotefield]);
                    $countrymap = get_string_manager()->get_list_of_countries();
                    if (isset($countrymap[$incoming])) {
                        $countrycode = $incoming;
                    } else {
                        $countrycode = array_search($aaddata[$remotefield], get_string_manager()->get_list_of_countries());
                    }
                    $user->$localfield = (!empty($countrycode)) ? $countrycode : '';
                } else {
                    $user->$localfield = $aaddata[$remotefield];
                }
            }

            if ($remotefield == "manager") {
                $user->$localfield = $usersync->get_user_manager($userobjectid);
            } else if ($remotefield == "groups") {
                $user->$localfield = $usersync->get_user_groups($userobjectid);
            } else if ($remotefield == "teams") {
                $user->$localfield = $usersync->get_user_teams($userobjectid);
            } else if ($remotefield == "roles") {
                $user->$localfield = $usersync->get_user_roles($userobjectid);
            } else if ($remotefield == "preferredName") {
                if (!isset($aaddata[$remotefield])) {
                    if (stripos($user->username, '_ext_') !== false) {
                        $user->$localfield = $usersync->get_preferred_name($userobjectid);
                    }
                }
            } else if (substr($remotefield, 0, 18) == 'extensionAttribute') {
                $extensionattributeid = substr($remotefield, 18);
                if (ctype_digit($extensionattributeid) && $extensionattributeid >= 1 && $extensionattributeid <= 15) {
                    if (isset($aaddata['onPremisesExtensionAttributes']) &&
                        isset($aaddata['onPremisesExtensionAttributes'][$remotefield])) {
                        $user->$localfield = $aaddata['onPremisesExtensionAttributes'][$remotefield];
                    }
                }
            }
        }

        // Lang cannot be longer than 2 chars.
        if (!empty($user->lang) && strlen($user->lang) > 2) {
            $user->lang = substr($user->lang, 0, 2);
        }

        return $user;
    }

    /**
     * Check if any of the fields in the field map configuration would require calling Graph API function to get user details.
     *
     * @param $eventtype
     *
     * @return bool
     */
    public static function fieldmap_require_graph_api_call($eventtype) {
        $requireapicall = false;

        $fieldmaps = get_config('local_o365', 'fieldmap');
        if ($fieldmaps !== false) {
            $fieldmaps = @unserialize($fieldmaps);
            if (!is_array($fieldmaps)) {
                $fieldmaps = \local_o365\adminsetting\usersyncfieldmap::defaultmap();
            }
        }

        $idtokenfields = ['givenName', 'surname', 'mail', 'objectId', 'userPrincipalName'];

        foreach ($fieldmaps as $fieldmap) {
            $fieldmap = explode('/', $fieldmap);

            if (count($fieldmap) !== 3) {
                continue;
            }

            if (!in_array($fieldmap[0], $idtokenfields)) {
                if ($fieldmap[2] == 'always' || $fieldmap[2] == 'on' . $eventtype) {
                    $requireapicall = true;
                    break;
                }
            }
        }

        return $requireapicall;
    }

    /**
     * Check the configured user creation restriction and determine whether a user can be created.
     *
     * @param array $aaddata Array of user data from Azure AD.
     * @return bool Whether the user can be created.
     */
    protected function check_usercreationrestriction($aaddata) {
        $restriction = get_config('local_o365', 'usersynccreationrestriction');
        if (empty($restriction)) {
            return true;
        }
        $restriction = @unserialize($restriction);
        if (empty($restriction) || !is_array($restriction)) {
            return true;
        }
        if (empty($restriction['remotefield']) || empty($restriction['value'])) {
            return true;
        }
        $useregex = (!empty($restriction['useregex'])) ? true : false;

        if ($restriction['remotefield'] === 'o365group') {
            if (unified::is_configured() !== true) {
                utils::debug('graph api is not configured.', 'check_usercreationrestriction');
                return false;
            }

            $apiclient = $this->construct_user_api();

            try {
                $group = $apiclient->get_group_by_name($restriction['value']);
                if (empty($group) || !isset($group['id'])) {
                    utils::debug('Could not find group (1)', 'check_usercreationrestriction', $group);
                    return false;
                }
                $usersgroups = $apiclient->get_user_groups($aaddata['id']);
                foreach ($usersgroups['value'] as $usergroup) {
                    if ($group['id'] === $usergroup) {
                        return true;
                    }
                }
                return false;
            } catch (\Exception $e) {
                utils::debug('Could not find group (2)', 'check_usercreationrestriction', $e);
                return false;
            }
        } else {
            if (!isset($aaddata[$restriction['remotefield']])) {
                return false;
            }
            $fieldval = $aaddata[$restriction['remotefield']];
            $restrictionval = $restriction['value'];

            if ($useregex === true) {
                $count = @preg_match('/'.$restrictionval.'/', $fieldval, $matches);
                if (!empty($count)) {
                    return true;
                }
            } else {
                if ($fieldval === $restrictionval) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Create a Moodle user from Azure AD user data.
     *
     * @param array $aaddata Array of Azure AD user data.
     * @param array $syncoptions
     * @return \stdClass An object representing the created Moodle user.
     */
    public function create_user_from_aaddata($aaddata, $syncoptions) {
        global $CFG, $DB;

        $creationallowed = $this->check_usercreationrestriction($aaddata);
        if ($creationallowed !== true) {
            mtrace('Cannot create user because they do not meet the configured user creation restrictions.');
            return false;
        }

        // Locate country code.
        if (isset($aaddata['country'])) {
            $countries = get_string_manager()->get_list_of_countries(true, 'en');
            foreach ($countries as $code => $name) {
                if ($aaddata['country'] == $name) {
                    $aaddata['country'] = $code;
                }
            }
            if (strlen($aaddata['country']) > 2) {
                // Limit string to 2 chars to prevent sql error.
                $aaddata['country'] = substr($aaddata['country'], 0, 2);
            }
        }

        $username = $aaddata['userPrincipalName'];
        if (isset($aaddata['convertedupn']) && $aaddata['convertedupn']) {
            $username = $aaddata['convertedupn'];
        }
        $newuser = (object)[
            'auth' => 'oidc',
            'username' => trim(\core_text::strtolower($username)),
            'confirmed' => 1,
            'timecreated' => time(),
            'mnethostid' => $CFG->mnet_localhost_id,
        ];

        // Determine if the newly created user needs to be suspended.
        if ($syncoptions['disabledsync']) {
            if (isset($aaddata['accountEnabled']) && $aaddata['accountEnabled'] == false) {
                $newuser->suspended = 1;
            }
        }

        $newuser = static::apply_configured_fieldmap($aaddata, $newuser, 'create');

        $password = null;
        if (!isset($newuser->idnumber)) {
            $newuser->idnumber = $newuser->username;
        }

        if (!empty($newuser->email)) {
            if (email_is_not_allowed($newuser->email)) {
                unset($newuser->email);
            }
        }

        if (empty($newuser->lang) || !get_string_manager()->translation_exists($newuser->lang)) {
            $newuser->lang = $CFG->lang;
        }

        $newuser->timemodified = $newuser->timecreated;
        $newuser->id = user_create_user($newuser, false, false);

        // Save user profile data.
        profile_save_data($newuser);

        $user = get_complete_user_data('id', $newuser->id);
        // Set the password.
        update_internal_user_password($user, $password);

        // Add o365 object.
        if (unified::is_configured()) {
            $userobjectid = $aaddata['id'];
        } else {
            $userobjectid = $aaddata['objectId'];
        }
        $now = time();
        $userobjectdata = (object)[
            'type' => 'user',
            'subtype' => '',
            'objectid' => $userobjectid,
            'o365name' => $aaddata['userPrincipalName'],
            'moodleid' => $newuser->id,
            'tenant' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $userobjectdata->id = $DB->insert_record('local_o365_objects', $userobjectdata);

        // Trigger event.
        \core\event\user_created::create_from_userid($newuser->id)->trigger();

        return $user;
    }

    /**
     * Updates a Moodle user from Azure AD user data.
     *
     * @param array $aaddata Array of Azure AD user data.
     * @param $fullexistinguser
     *
     * @return \stdClass An object representing the created Moodle user.
     */
    public function update_user_from_aaddata($aaddata, $fullexistinguser) {
        // Locate country code.
        if (isset($aaddata['country'])) {
            $countries = get_string_manager()->get_list_of_countries(true, 'en');
            foreach ($countries as $code => $name) {
                if ($aaddata['country'] == $name) {
                    $aaddata['country'] = $code;
                }
            }
            if (strlen($aaddata['country']) > 2) {
                // Limit string to 2 chars to prevent sql error.
                $aaddata['country'] = substr($aaddata['country'], 0, 2);
            }
        }

        $existinguser = static::apply_configured_fieldmap($aaddata, $fullexistinguser, 'login');

        if (!empty($existinguser->email)) {
            if (email_is_not_allowed($existinguser->email)) {
                unset($existinguser->email);
            }
        } else {
            // Email is originally pulled (optionally) from UPN, so an empty email should not wipe out Moodle email.
            unset($existinguser->email);
        }

        $existinguser->timemodified = time();

        // Update a user with a user object (will compare against the ID).
        user_update_user($existinguser, false, false);

        // Save user profile data.
        profile_save_data($existinguser);

        // Trigger event.
        \core\event\user_updated::create_from_userid($existinguser->id)->trigger();

        return true;
    }

    /**
     * Selectively run mtrace.
     *
     * @param string $msg The message.
     */
    public static function mtrace($msg) {
        if (!PHPUNIT_TEST) {
            mtrace('......... '.$msg);
        }
    }

    /**
     * Get an array of sync options.
     *
     * @return array Sync options
     */
    public static function get_sync_options() {
        $aadsync = get_config('local_o365', 'aadsync');
        $aadsync = array_flip(explode(',', $aadsync));
        return $aadsync;
    }

    /**
     * Determine whether a sync option is enabled.
     *
     * @param string $option The option to check.
     * @return bool Whether the option is enabled.
     */
    public static function sync_option_enabled($option) {
        $options = static::get_sync_options();
        return isset($options[$option]);
    }

    /**
     * Sync Azure AD Moodle users with the configured Azure AD directory.
     *
     * @param array $aadusers Array of Azure AD users from $this->get_users().
     * @return bool Success/Failure
     */
    public function sync_users(array $aadusers = array()) {
        global $DB, $CFG;

        $aadsync = $this->get_sync_options();
        $switchauthminupnsplit0 = get_config('local_o365', 'switchauthminupnsplit0');
        if (empty($switchauthminupnsplit0)) {
            $switchauthminupnsplit0 = 10;
        }

        $usernames = [];
        $upns = [];
        foreach ($aadusers as $i => $user) {
            if (!isset($user['userPrincipalName'])) {
                // User doesn't have userPrincipalName, should be deleted users.
                unset($aadusers[$i]);
                continue;
            }
            $upnlower = \core_text::strtolower($user['userPrincipalName']);
            $aadusers[$i]['upnlower'] = $upnlower;

            $usernames[] = $upnlower;
            $upns[] = $upnlower;

            $upnsplit = explode('@', $upnlower);
            if (!empty($upnsplit[0])) {
                $aadusers[$i]['upnsplit0'] = $upnsplit[0];
                $usernames[] = $upnsplit[0];
            }

            // Convert upn for guest users.
            if (stripos($upnlower, '#ext#') !== false) {
                $upnlower = \core_text::strtolower($user['mail']);

                $usernames[] = $upnlower;
                $upns[] = $upnlower;

                $upnsplit = explode('@', $upnlower);
                if (!empty($upnsplit[0])) {
                    $usernames[] = $upnsplit[0];
                }
            }
        }

        if (!$aadusers) {
            return true;
        }

        if (isset($aadsync['emailsync'])) {
            $select = 'SELECT LOWER(u.email) AS email,
                       LOWER(u.username) AS username,';
            $where = ' WHERE u.email';
        } else {
            $select = 'SELECT LOWER(u.username) AS username,';
            $where = ' WHERE u.username';
        }

        list($usernamesql, $usernameparams) = $DB->get_in_or_equal($usernames);
        $sql = "$select
                       u.id as muserid,
                       u.auth,
                       u.suspended,
                       tok.id as tokid,
                       conn.id as existingconnectionid,
                       assign.assigned assigned,
                       assign.photoid photoid,
                       assign.photoupdated photoupdated,
                       obj.id AS objrecid
                  FROM {user} u
             LEFT JOIN {auth_oidc_token} tok ON tok.userid = u.id
             LEFT JOIN {local_o365_connections} conn ON conn.muserid = u.id
             LEFT JOIN {local_o365_appassign} assign ON assign.muserid = u.id
             LEFT JOIN {local_o365_objects} obj ON obj.type = ? AND obj.moodleid = u.id
                 $where $usernamesql AND u.mnethostid = ? AND u.deleted = ?
              ORDER BY CONCAT(u.username, '~')"; // Sort john.smith@example.org before john.smith.
        $params = array_merge(['user'], $usernameparams, [$CFG->mnet_localhost_id, '0']);
        $existingusers = $DB->get_records_sql($sql, $params);

        // Fetch linked AAD user accounts.
        list($upnsql, $upnparams) = $DB->get_in_or_equal($upns);
        list($usernamesql, $usernameparams) = $DB->get_in_or_equal($usernames, SQL_PARAMS_QM, 'param', false);
        $sql = 'SELECT tok.oidcusername,
                       u.username as username,
                       u.id as muserid,
                       u.auth,
                       tok.id as tokid,
                       conn.id as existingconnectionid,
                       assign.assigned assigned,
                       assign.photoid photoid,
                       assign.photoupdated photoupdated,
                       obj.id AS objrecid
                  FROM {user} u
             LEFT JOIN {auth_oidc_token} tok ON tok.userid = u.id
             LEFT JOIN {local_o365_connections} conn ON conn.muserid = u.id
             LEFT JOIN {local_o365_appassign} assign ON assign.muserid = u.id
             LEFT JOIN {local_o365_objects} obj ON obj.type = ? AND obj.moodleid = u.id
                 WHERE tok.oidcusername '.$upnsql.' AND u.username '.$usernamesql.' AND u.mnethostid = ? AND u.deleted = ? ';
        $params = array_merge(['user'], $upnparams, $usernameparams, [$CFG->mnet_localhost_id, '0']);
        $linkedexistingusers = $DB->get_records_sql($sql, $params);

        $existingusers = $existingusers + $linkedexistingusers;

        foreach ($aadusers as $user) {
            $this->mtrace(' ');

            if (empty($user['upnlower'])) {
                $this->mtrace('Azure AD user missing UPN (' . $user['objectId'] . '); skipping...');
                continue;
            }

            $this->mtrace('Syncing user '.$user['upnlower']);

            if (unified::is_configured()) {
                $userobjectid = $user['id'];
            } else {
                $userobjectid = $user['objectId'];
            }

            // Process guest users.
            $user['convertedupn'] = $user['upnlower'];
            if (stripos($user['userPrincipalName'], '#EXT#') !== false) {
                $user['convertedupn'] = $user['mail'];
            }

            if (!isset($existingusers[$user['upnlower']]) && !isset($existingusers[$user['upnsplit0']]) &&
                !isset($existingusers[$user['convertedupn']])) {
                $this->sync_new_user($aadsync, $user, isset($aadsync['guestsync']));
            } else {
                $existinguser = null;
                if (isset($existingusers[$user['upnlower']])) {
                    $existinguser = $existingusers[$user['upnlower']];
                    $exactmatch = true;
                } else if (isset($existingusers[$user['upnsplit0']])) {
                    $existinguser = $existingusers[$user['upnsplit0']];
                    $exactmatch = strlen($user['upnsplit0']) >= $switchauthminupnsplit0;
                } else if (isset($existingusers[$user['convertedupn']])) {
                    $existinguser = $existingusers[$user['convertedupn']];
                    $exactmatch = true;
                }

                // Process guest users.
                if (stripos($user['upnlower'], '_ext_') !== false) {
                    $this->mtrace('The user is a guest user.');
                    if (!isset($aadsync['guestsync'])) {
                        $this->mtrace('The option to sync guest users is turned off.');
                        $this->mtrace('User is already synced, but not updated.');

                        continue;
                    }
                }

                $this->sync_existing_user($aadsync, $user, $existinguser, $exactmatch);

                if ($existinguser->auth === 'oidc' || empty($existinguser->tokid)) {
                    // Create userobject if it does not exist.
                    if (empty($existinguser->objrecid)) {
                        $this->mtrace('Adding o365 object record for user.');
                        $now = time();
                        $userobjectdata = (object)[
                            'type' => 'user',
                            'subtype' => '',
                            'objectid' => $userobjectid,
                            'o365name' => $user['userPrincipalName'],
                            'moodleid' => $existinguser->muserid,
                            'tenant' => '',
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ];
                        $userobjectdata->id = $DB->insert_record('local_o365_objects', $userobjectdata);
                    }
                    // User already connected.
                    $this->mtrace('User is now synced.');
                }

                // Update existing user on moodle from AD.
                if ($existinguser->auth === 'oidc') {
                    if (isset($aadsync['update'])) {
                        $this->mtrace('Updating Moodle user data from Azure AD user data.');
                        $fullexistinguser = get_complete_user_data('username', $existinguser->username);
                        if ($fullexistinguser) {
                            $existingusercopy = \core_user::get_user_by_username($existinguser->username);
                            $fullexistinguser->description = $existingusercopy->description;
                            $this->update_user_from_aaddata($user, $fullexistinguser);
                            $this->mtrace('User is now updated.');
                        } else {
                            $this->mtrace('Update failed for user with username "' . $existinguser->username . '".');
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Sync a Microsoft 365 that hasn't been synced before - create a new Moodle account.
     *
     * @param $syncoptions
     * @param $aaduserdata
     * @param $syncguestusers
     *
     * @return false|\stdClass|null
     */
    protected function sync_new_user($syncoptions, $aaduserdata, bool $syncguestusers = false) {
        global $DB;

        $this->mtrace('User doesn\'t exist in Moodle');

        $newmuser = null;

        $userobjectid = (unified::is_configured())
            ? $aaduserdata['id']
            : $aaduserdata['objectId'];

        // Create moodle account, if enabled.
        if (!isset($syncoptions['create'])) {
            $this->mtrace('Not creating a Moodle user because that sync option is disabled.');
            return null;
        }

        // Process guest users.
        if (stripos($aaduserdata['upnlower'], '_ext_') !== false) {
            $this->mtrace('The user is a guest user.');
            if (!$syncguestusers) {
                $this->mtrace('The option to sync guest users is turned off.');
                $this->mtrace('User is not created.');
                return null;
            }
        }

        try {
            $newmuser = $this->create_user_from_aaddata($aaduserdata, $syncoptions);
            if (!empty($newmuser)) {
                $this->mtrace('Created user #' . $newmuser->id);
            }
        } catch (\Exception $e) {
            if (isset($syncoptions['emailsync'])) {
                if ($DB->record_exists('user', ['username' => $aaduserdata['userPrincipalName']])) {
                    $this->mtrace('Could not create user "' . $aaduserdata['userPrincipalName'] .
                        '" Reason: user with same username, but different email already exists.');
                } else {
                    $this->mtrace('Could not create user with email "' . $aaduserdata['userPrincipalName'] . '" Reason: ' .
                        $e->getMessage());
                }
            } else {
                $this->mtrace('Could not create user "'.$aaduserdata['userPrincipalName'].'" Reason: '.$e->getMessage());
            }
        }

        // User app assignment.
        if (!empty($syncoptions['appassign'])) {
            try {
                if (!empty($newmuser) && !empty($userobjectid)) {
                    $this->assign_user($newmuser->id, $userobjectid);
                }
            } catch (\Exception $e) {
                $this->mtrace('Could not assign user "'.$aaduserdata['userPrincipalName'].'" Reason: '.$e->getMessage());
            }
        }

        // User photo sync.
        if (!empty($syncoptions['photosync'])) {
            if (!PHPUNIT_TEST) {
                try {
                    if (!empty($newmuser)) {
                        $this->assign_photo($newmuser->id, $aaduserdata['upnlower']);
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not assign photo to user "' . $aaduserdata['userPrincipalName'] . '" Reason: ' .
                        $e->getMessage());
                }
            }
        }

        // User timezone.
        if (!empty($syncoptions['tzsync'])) {
            if (!PHPUNIT_TEST) {
                try {
                    if (!empty($newmuser)) {
                        $this->sync_timezone($newmuser->id, $aaduserdata['upnlower']);
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not sync timezone for user "' . $aaduserdata['userPrincipalName'] . '" Reason: ' .
                        $e->getMessage());
                }
            }
        }

        return $newmuser;
    }

    /**
     * Sync a Moodle user who has been previously connected to a Microsoft 365 account.
     *
     * @param $syncoptions
     * @param $aaduserdata
     * @param $existinguser
     * @param $exactmatch
     *
     * @return bool
     */
    protected function sync_existing_user($syncoptions, $aaduserdata, $existinguser, $exactmatch) {
        $photoexpire = get_config('local_o365', 'photoexpire');
        if (empty($photoexpire) || !is_numeric($photoexpire)) {
            $photoexpire = 24;
        }
        $photoexpiresec = $photoexpire * 3600;

        $userobjectid = (unified::is_configured())
            ? $aaduserdata['id']
            : $aaduserdata['objectId'];

        // Assign user to app if not already assigned.
        if (isset($syncoptions['appassign'])) {
            if (empty($existinguser->assigned)) {
                try {
                    if (!empty($existinguser->muserid) && !empty($userobjectid)) {
                        $this->assign_user($existinguser->muserid, $userobjectid);
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not assign user "'.$aaduserdata['userPrincipalName'].'" Reason: '.$e->getMessage());
                }
            }
        }

        // Perform photo sync.
        if (isset($syncoptions['photosync'])) {
            if (empty($existinguser->photoupdated) || ($existinguser->photoupdated + $photoexpiresec) < time()) {
                try {
                    if (!PHPUNIT_TEST) {
                        $this->assign_photo($existinguser->muserid, $aaduserdata['upnlower']);
                    }
                } catch (\Exception $e) {
                    $this->mtrace('Could not assign profile photo to user "' . $aaduserdata['userPrincipalName'] . '" Reason: ' .
                        $e->getMessage());
                }
            }
        }

        // Perform timezone sync.
        if (isset($syncoptions['tzsync'])) {
            try {
                if (!PHPUNIT_TEST) {
                    $this->sync_timezone($existinguser->muserid, $aaduserdata['upnlower']);
                }
            } catch (\Exception $e) {
                $this->mtrace('Could not sync timezone for user "' . $aaduserdata['userPrincipalName'] . '" Reason: ' .
                    $e->getMessage());
            }
        }

        // Sync disabled status.
        if (isset($syncoptions['disabledsync'])) {
            if (isset($aaduserdata['accountEnabled'])) {
                if ($aaduserdata['accountEnabled']) {
                    if ($existinguser->suspended == 1) {
                        $completeexistinguser = \core_user::get_user($existinguser->muserid);
                        $completeexistinguser->suspended = 0;
                        user_update_user($completeexistinguser, false);
                    }
                } else {
                    if ($existinguser->suspended == 0) {
                        $completeexistinguser = \core_user::get_user($existinguser->muserid);
                        $completeexistinguser->suspended = 1;
                        user_update_user($completeexistinguser, false);
                    }
                }
            }
        }

        // Match user if needed.
        if ($existinguser->auth !== 'oidc') {
            $this->mtrace('Found a user in Azure AD that seems to match a user in Moodle');
            $this->mtrace(sprintf('moodle username: %s, aad upn: %s', $existinguser->username, $aaduserdata['upnlower']));
            return $this->sync_users_matchuser($syncoptions, $aaduserdata, $existinguser, $exactmatch);
        } else {
            $this->mtrace('The user is already using OpenID for authentication.');
            return true;
        }
    }

    protected function sync_users_matchuser($syncoptions, $aaduserdata, $existinguser, $exactmatch) {
        global $DB;

        if (!isset($syncoptions['match'])) {
            $this->mtrace('Not matching user because that sync option is disabled.');
            return true;
        }

        if (isset($syncoptions['matchswitchauth']) && $exactmatch) {
            // Switch the user to OpenID authentication method, but only if this setting is enabled and full username matched.
            // Do not switch Moodle user to OpenID if another Moodle user is already using same Microsoft 365 account for logging
            // in.
            $sql = 'SELECT u.username
                      FROM {user} u
                 LEFT JOIN {local_o365_objects} obj ON obj.type = ? AND obj.moodleid = u.id
                 WHERE obj.o365name = ?
                   AND u.username != ?';
            $params = ['user', $aaduserdata['upnlower'], $existinguser->username];
            $alreadylinkedusername = $DB->get_field_sql($sql, $params);

            if ($alreadylinkedusername !== false) {
                $errmsg = 'This Azure AD user has already been linked with Moodle user %s. Not switching Moodle user %s to OpenID.';
                $this->mtrace(sprintf($errmsg, $alreadylinkedusername, $existinguser->username));
                return true;
            } else {
                if (!empty($existinguser->existingconnectionid)) {
                    // Delete existing connection before linking (in case matching was performed without auth switching previously).
                    $DB->delete_records_select('local_o365_connections', "id = {$existinguser->existingconnectionid}");
                }
                $fullexistinguser = get_complete_user_data('username', $existinguser->username);
                $existinguser->id = $fullexistinguser->id;
                $existinguser->auth = 'oidc';
                user_update_user($existinguser, true);
                // Clear user's password.
                $password = null;
                update_internal_user_password($existinguser, $password);
                $this->mtrace('Switched user to OpenID.');

                // Clear force password change preference.
                unset_user_preference('auth_forcepasswordchange', $existinguser);
            }
        } else if (!empty($existinguser->existingconnectionid)) {
            $this->mtrace('User is already matched.');
            return true;
        } else {
            // Match to o365 account, if enabled.
            $matchrec = [
                'muserid' => $existinguser->muserid,
                'aadupn' => $aaduserdata['upnlower'],
                'uselogin' => isset($syncoptions['matchswitchauth']) ? 1 : 0,
            ];
            $DB->insert_record('local_o365_connections', $matchrec);
            $this->mtrace('Matched user, but did not switch them to OpenID.');
            return true;
        }
    }

    /**
     * Suspend users that have been deleted from Microsoft 365, and optionally delete them.
     * This function will get the list of recently deleted users in the last 30 days first, and suspend their accounts.
     * It will then try to find all remaining users matched with Microsoft 365, and check if a valid user can be found in Azure.
     * If a valid user is not found, it will suspend the user in the first run, and delete it in the next run if the option is set.
     *
     * So in a normal use case, where the option is enabled and not changed, and a Microsoft 365 account is deleted:
     *  - Their matching Moodle account will be suspended on the first task run after Microsoft 365 account deletion;
     *  - The account will be deleted on the first run 30 days after their Microsoft 365 account deletion, if $delete is true.
     *
     * In case the option to delete Moodle users is changed from disabled to enabled:
     *  - If the deletion of the Microsoft 365 account happened before 30 days:
     *    - The matching Moodle account will be suspended on the first task run after the configuration change is made.
     *    - The account will be deleted on the second task run after the configuration change is made, if $delete is true.
     *  - If the deletion of the Microsoft 365 account happened within 30 days:
     *    - The matching Moodle account will be suspended on the first task run after the configuration change is made.
     *    - The account will be deleted on the first run 30 days after their Microsoft 365 account deletion, if $delete is true.
     *
     * Note this will not catch oidc users without matching Microsoft 365 account.
     *
     * @param $aadusers
     * @param bool $delete
     *
     * @return bool
     */
    public function suspend_users(array $aadusers, bool $delete = false) {
        global $CFG, $DB;

        $apiclient = $this->construct_user_api();

        try {
            $deletedusersids = [];
            $deletedusers = $apiclient->list_deleted_users();
            if (is_array($deletedusers) && !empty($deletedusers['value'])) {
                foreach ($deletedusers['value'] as $deleteduser) {
                    if (!empty($deleteduser) && isset($deleteduser['id'])) {
                        // Check for synced user.
                        $sql = 'SELECT u.*
                                  FROM {user} u
                                  JOIN {local_o365_objects} obj ON obj.type = ? AND obj.moodleid = u.id
                                 WHERE u.mnethostid = ?
                                   AND u.deleted = ?
                                   AND u.suspended = ?
                                   AND u.auth = ?
                                   AND obj.objectid = ? ';
                        $params = ['user', $CFG->mnet_localhost_id, '0', '0', 'oidc', $deleteduser['id']];
                        $synceduser = $DB->get_record_sql($sql, $params);
                        if (!empty($synceduser)) {
                            $synceduser->suspended = 1;
                            user_update_user($synceduser, false);
                            $this->mtrace($synceduser->username . ' was deleted in Azure, the matching account is suspended.');
                        }
                        $deletedusersids[] = $deleteduser['id'];
                    }
                }
            }

            $existingsql = 'SELECT u.*, obj.objectid
                              FROM {user} u
                              JOIN {local_o365_objects} obj ON obj.type = ? AND obj.moodleid = u.id
                             WHERE u.mnethostid = ?
                               AND u.deleted = ?
                               AND u.auth = ? ';
            $existingsqlparams = ['user', $CFG->mnet_localhost_id, '0', 'oidc'];
            if ($deletedusersids) {
                // Check if all Moodle users with oidc authentication and matching records are still existing users in Azure.
                list($objectidsql, $objectidparams) = $DB->get_in_or_equal($deletedusersids, SQL_PARAMS_QM, 'param', false);
                $existingsql .= ' AND obj.objectid ' . $objectidsql;
                $existingsqlparams = array_merge($existingsqlparams, $objectidparams);
            }

            $existingusers = $DB->get_records_sql($existingsql, $existingsqlparams);
            $validaaduserids = [];
            foreach ($aadusers as $aaduser) {
                $validaaduserids[] = $aaduser['id'];
            }

            foreach ($existingusers as $existinguser) {
                if (!in_array($existinguser->objectid, $validaaduserids)) {
                    if ($existinguser->suspended) {
                        if ($delete) {
                            $this->mtrace('Could not find suspended user ' . $existinguser->username .
                                ' in Azure AD. Deleting user...');
                            unset($existinguser->objectid);
                            delete_user($existinguser);
                        }
                    } else if (!$existinguser->suspended) {
                        $this->mtrace('Could not find user ' . $existinguser->username . ' in Azure AD. Suspending user...');
                        $existinguser->suspended = 1;
                        unset($existinguser->objectid);
                        user_update_user($existinguser, false);
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            utils::debug('Could not delete users', 'suspend_users', $e);

            return false;
        }
    }

    /**
     * Re-enable suspended users.
     * This function will ensure that for all the users in the array received, if they have a Moodle account that's suspended but
     * not deleted, the account will unsuspended.
     *
     * @param array $aadusers
     *
     * @return bool
     */
    public function reenable_suspsend_users(array $aadusers) {
        global $DB;

        $validaaduserids = [];
        foreach ($aadusers as $aaduser) {
            $validaaduserids[] = $aaduser['id'];
        }

        if ($validaaduserids) {
            list($objectidsql, $objectidparams) = $DB->get_in_or_equal($validaaduserids, SQL_PARAMS_NAMED);
            $query = 'SELECT u.*
                        FROM {user} u
                        JOIN {local_o365_objects} obj ON obj.type = :user AND obj.moodleid = u.id
                       WHERE u.auth = :oidc
                         AND u.deleted = :deleted
                         AND u.suspended = :suspended
                         AND obj.objectid ' . $objectidsql;
            $params = [
                'user' => 'user',
                'oidc' => 'oidc',
                'deleted' => 0,
                'suspended' => 1,
            ];
            $params = array_merge($params, $objectidparams);
            $suspendedusers = $DB->get_records_sql($query, $params);
            foreach ($suspendedusers as $suspendeduser) {
                $this->mtrace('Re-enabling user ' . $suspendeduser->username . '...');
                $suspendeduser->suspended = 0;
                user_update_user($suspendeduser, false);
            }
        }

        return true;
    }
}
