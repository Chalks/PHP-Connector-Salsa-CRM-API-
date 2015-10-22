<?php

class Salsa {
	// API description
	// https://help.salsalabs.com/entries/60648624-Salsa-Application-Program-Interface-API-

	private static $instance;

	const RECEIVE_EMAIL_SUBSCRIBED = 3;
	const RECEIVE_EMAIL_UNSUBSCRIBED = -3;
	const RECEIVE_EMAIL_IMPORT = 1;
	const RECEIVE_EMAIL_DOUBLE_OPT = 10;
	const RECEIVE_EMAIL_NO_ACTIVITY = 15;

	const AUTH_TIMEOUT = 3600; // 1 hour timeout in seconds (docs recommend 'about 2 hours')
	// apparently only PHP 5.6+ can have arrays as constants...
	public static $GROUPS_COLUMNS = array("groups_KEY", "organization_KEY", "chapter_KEY", "Last_Modified", "Date_Created", "Group_Name", "Reference_Name", "parent_KEY", "Description", "Notes", "Display_To_User_BOOLVALUE", "Display_To_User", "Listserve_Type", "Subscription_Type", "Manager", "Moderator_Emails", "Subject_Prefix", "Listserve_Responses", "Append_Header", "Append_Footer", "Custom_Headers", "Listserve_Options", "external_ID", "From_Email", "From_Name", "Reply_To", "Headers_To_Remove", "Confirmation_Message", "Auto_Update_BOOLVALUE", "Auto_Update", "query_KEY", "Smart_Group_Options", "Smart_Group_Error", "enable_statistics_BOOLVALUE", "enable_statistics", "join_email_trigger_KEYS", "add_to_chapter_KEY", "salesforce_id");
	public static $SUPPORTER_COLUMNS = array("supporter_KEY", "organization_KEY", "chapter_KEY", "Last_Modified", "Date_Created", "Title", "First_Name", "MI", "Last_Name", "Suffix", "Email", "Password", "Receive_Email", "Email_Status", "Email_Preference", "Soft_Bounce_Count", "Hard_Bounce_Count", "Last_Bounce", "Receive_Phone_Blasts_BOOLVALUE", "Receive_Phone_Blasts", "Phone", "Cell_Phone", "Phone_Provider", "Work_Phone", "Pager", "Home_Fax", "Work_Fax", "Street", "Street_2", "Street_3", "City", "State", "Zip", "PRIVATE_Zip_Plus_4", "County", "District", "Country", "Latitude", "Longitude", "Organization", "Department", "Occupation", "Instant_Messenger_Service", "Instant_Messenger_Name", "Web_Page", "Alternative_Email", "Other_Data_1", "Other_Data_2", "Other_Data_3", "Notes", "Source", "Source_Details", "Source_Tracking_Code", "Tracking_Code", "Status", "uid", "Timezone", "Language_Code", "salesforce_id", "append_run_date", "salsa_deleted_BOOLVALUE", "salsa_deleted");

	private $salsaNode;
	private $orgKey;
	private $apiHost;

	private $validCredentials;
	private $lastAuth;

	private $format = 'json';
	private $curlHandler;

	public static function getInstance($email, $password, $node, $orgKey) {
		if(null !== static::$instance) {
			static::$instance->setNode($node);
			static::$instance->setOrg($orgKey);
		}
		if(null === static::$instance) {
			static::$instance = new static($email, $password, $node, $orgKey);
		} else if(!static::$instance->isAuthenticated() || (microtime(true) - static::$instance->lastAuthentication()) < Salsa::AUTH_TIMEOUT) {
			static::$instance->authenticate($email, $password);
		}
		return static::$instance;
	}

	protected function __construct($email, $password, $node, $orgKey) {
		$this->setNode($node);
		$this->setOrg($orgKey);
		$this->curlHandler = curl_init();
		curl_setopt($this->curlHandler, CURLOPT_HTTPGET, true);
		curl_setopt($this->curlHandler, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->curlHandler, CURLOPT_TIMEOUT, 100);
		curl_setopt($this->curlHandler, CURLOPT_COOKIESESSION, TRUE);
		curl_setopt($this->curlHandler, CURLOPT_COOKIEFILE, '/tmp/cookies_file');
		curl_setopt($this->curlHandler, CURLOPT_COOKIEJAR, '/tmp/cookies_file');
		$auth = $this->authenticate($email, $password);
		$this->validCredentials = $auth->status == 'success';
	}

	// prevent cloning... singleton only
	private function __clone() {}

	// preven unserializing... singleton only
	private function __wakeup() {}

	/*** GROUP CRUD ***/
	public function createGroup($attr) {
		$params['object'] = "groups";
		foreach($attr AS $k => $v) {
			if(in_array($k, self::$GROUPS_COLUMNS)) {
				$params[$k] = $v;
			} else {
				error_log("Attribute '$k' is not a valid groups column name");
			}
		}
		if(isset($params['groups_KEY'])) {
			error_log('Use updateGroup when you know the group key.');
			return json_decode('{"result":"error","messages":["There was an internal error, please try again"]}');
		} else {
			$params['Group_Name'] = $this->filterGroupName($params['Group_Name']);
			if($this->validGroupName($params['Group_Name']) !== false) {
				$url = $this->apiHost . "save?" . $this->buildParamString($params) . "&" . $this->format;
				return $this->exec($url);
			} else {
				return json_decode('{"result":"error","messages":["Pre-existing Group_Name or no Group_Name was provided"]}');
			}
		}
	}

	public function getGroup($key) {
		$params['object'] = "groups";
		$params['key'] = $key;
		$url = $this->apiHost . "api/getObject.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function getGroupByName($name) {
		$params['object'] = "groups";
		$params['condition'] = "Group_Name=" . $name;
		$params['limit'] = 1;
		$url = $this->apiHost . "api/getObjects.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		$groups = $this->exec($url);
		if(count($groups) > 0) {
			return $groups[0];
		}
		return null;
	}

	public function getGroupChildren($key) {
		$params['object'] = "groups";
		$params['condition'] = "parent_KEY=" . $key;
		$params['orderBy'] = "-Date_Created";
		$url = $this->apiHost . "api/getObjects.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}


	public function updateGroup($key, $attr) {
		if($key == 0) {
			return $this->createGroup($attr);
		}
		$params['object'] = "groups";
		$params['key'] = $key;
		foreach($attr AS $k => $v) {
			if(in_array($k, self::$GROUPS_COLUMNS)) {
				$params[$k] = $v;
			} else {
				error_log("Attribute '$k' is not a valid groups column name");
			}
		}
		if(isset($params['Group_Name'])) {
			$params['Group_Name'] = $this->filterGroupName($params['Group_Name']);
		}
		if(!isset($params['Group_Name']) || $this->validGroupName($params['Group_Name'], $key) !== false) {
			$url = $this->apiHost . "save?" . $this->buildParamString($params) . "&" . $this->format;
			return $this->exec($url);
		} else {
			return json_decode('{"result":"error","messages":["Can not rename group to pre-existing Group_Name"]}');
		}
	}

	public function deleteGroup($key) {
		$params['object'] = "groups";
		$params['key'] = $key;
		$url = $this->apiHost . "delete?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function validGroupName($name, $key=0, $force=false) {
		//returns false if invalid name.  Otherwise returns the name
		if($key != 0) {
			$g = $this->getGroup($key);
			if(!empty($name) && $g->Group_Name == $name) {
				return $name; //if you're not trying to change the group's name, it's valid
			}
		}
		if($force) {
			// guarantee unique name if you're changing it and forcing it
			$i = 0;
			$tmp = $name;
			while($this->validGroupName($tmp, 0, false) === false) {
				$i++;
				$tmp = $name . '_' . $i;
			}
			return $tmp;
		} else {
			// simple check to see if it's a unique name
			if(!empty($name) && $this->getGroupByName($name) == null) {
				return $name;
			} else {
				return false;
			}
		}
	}

	public function filterGroupName($name) {
		return preg_replace('/[^a-zA-Z0-9]/', '_', $name);
	}

	/*** END GROUP CRUD ***/

	/*** SUPPORTER CRUD ***/
	public function createSupporter($attr, $requireEmail = true, $allowDuplicateEmails = false) {
		$params['object'] = "supporter";
		foreach($attr AS $k => $v) {
			if(in_array($k, self::$SUPPORTER_COLUMNS)) {
				$params[$k] = $v;
			} else {
				error_log("Attribute '$k' is not a valid groups column name");
			}
		}
		if(isset($params['supporter_KEY'])) {
			error_log('Use updateSupporter when you know the supporter key.');
			return json_decode('{"result":"error","messages":["There was an internal error, please try again"]}');
		} else if($requireEmail && !isset($params['Email'])) {
			return json_decode('{"result":"error","messages":["Please input an email address"]}');
		} else if (isset($params['Email']) && !$this->isValidEmail($params['Email'])) {
			return json_decode('{"result":"error","messages":["Please input a valid email address"]}');
		} else if (isset($params['Email']) && !$allowDuplicateEmails && !$this->isUniqueEmail($params['Email'])) {
			return json_decode('{"result":"error","messages":["That email address already exists"]}');
		} else if (!isset($params['First_Name']) || !isset($params['Last_Name']) || empty($params['First_Name']) || empty($params['Last_Name'])) {
			return json_decode('{"result":"error","messages":["Please input both a first and last name"]}');
		} else {
			$url = $this->apiHost . "save?" . $this->buildParamString($params) . "&" . $this->format;
			return $this->exec($url);
		}
	}

	public function getSupporter($supporterKey) {
		$params['object'] = "supporter";
		$params['key'] = $supporterKey;
		$url = $this->apiHost . "api/getObject.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function getSupporterByEmail($email) {
		$params['object'] = "supporter";
		$params['condition'] = "Email=" . $email;
		$params['limit'] = 1;
		$url = $this->apiHost . "api/getObjects.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		$groups = $this->exec($url);
		if(count($groups) > 0) {
			return $groups[0];
		}
		return null;
	}

	public function getAllSupporters() {
		$params['object'] = "supporter";
		$url = $this->apiHost . "api/getObjects.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function getSupportersByGroup($groupKey) {
		$params['object'] = "supporter_groups(supporter_KEY)supporter";
		$params['condition'] = "groups_KEY=" . $groupKey;
		$url = $this->apiHost . "api/getLeftJoin.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function getSupportersWithPicturesByGroup($groupKey) {
		$params['object'] = "supporter_groups(supporter_KEY)supporter(supporter_KEY)supporter_picture";
		$params['condition'] = "groups_KEY=" . $groupKey;
		$url = $this->apiHost . "api/getLeftJoin.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function getSupporterPicture($supporterKey) {
		// TODO make this return a supporter object like getSupportersWithPicturesByGroup
		$params['object'] = "supporter_picture";
		$params['condition'] = "supporter_KEY=" . $supporterKey;
		$url = $this->apiHost . "api/getObjects.sjs?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function updateSupporter($key, $attr) {
		if($key == 0) {
			return $this->createSupporter($attr);
		}
		$params['object'] = "supporter";
		$params['key'] = $key;
		foreach($attr AS $k => $v) {
			if(in_array($k, self::$SUPPORTER_COLUMNS)) {
				$params[$k] = $v;
			} else {
				error_log("Attribute '$k' is not a valid supporter column name");
			}
		}
		if(isset($params['Email']) && (!$this->isValidEmail($params['Email']) || !$this->isUniqueEmail($params['Email']))) {
			return json_decode('{"result":"error","messages":["This an invalid email address or the address already exists"]}');
		} else {
			$url = $this->apiHost . "save?" . $this->buildParamString($params) . "&" . $this->format;
			return $this->exec($url);
		}
	}

	public function deleteSupporter($key) {
		$params['object'] = "supporter";
		$params['key'] = $key;
		$url = $this->apiHost . "delete?" . $this->buildParamString($params) . "&" . $this->format;
		return $this->exec($url);
	}

	public function deleteAllSupporters($iAmVerySure = false) {
		if($iAmVerySure === true) {
			$supporters = $this->getAllSupporters();
			foreach($supporters AS $supporter) {
				$this->deleteSupporter($supporter->supporter_KEY);
			}
		}
	}

	public function isValidEmail($email) {
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	public function isUniqueEmail($email, $supporterKey = 0) {
		$supporter = $this->getSupporterByEmail($email);
		if(null === $supporter || $supporter->supporter_KEY == $supporterKey) {
			return true;
		} else {
			return false;
		}
	}

	/*** END SUPPORTER CRUD ***/

	/***** GETTERS/SETTERS *****/
	public function getHost($stripSlash = true) {
		if($stripSlash) {
			return substr($this->apiHost, 0, -1);
		} else {
			return $this->apiHost;
		}

	}
	// setHost is done via setting the Node.

	public function getNode() {
		return $this->salsaNode;
	}

	public function setNode($node) {
		$this->salsaNode = $node;
		$this->apiHost = "https://$node.salsalabs.com/";
	}

	public function getOrg() {
		return $this->orgKey;
	}

	public function setOrg($orgKey) {
		$this->orgKey = $orgKey;
	}

	public function getFormat() {
		return $this->format;
	}

	public function setFormat($format) {
		if($format == 'json' || $format == 'xml') {
			$this->format = $format;
			return true;
		}
		return false;
	}

	public function isAuthenticated() {
		return $this->validCredentials;
	}

	public function lastAuthentication() {
		return $this->lastAuth;
	}
	/***** END GETTERS/SETTERS *****/

	/***** PRIVATE HELPERS *****/
	private function exec($url) {
		curl_setopt($this->curlHandler, CURLOPT_URL, $url);
		$obj = curl_exec($this->curlHandler);
		return json_decode($obj);
	}

	private function buildParamString($params) {
		$str = "";
		foreach($params AS $k => $v) {
			$str .= urlencode($k) . "=" . $v . "&";
		}
		return substr($str, 0, -1);
	}

	private function authenticate($email, $password) {
		$this->lastAuth = microtime(true);
		$url = $this->apiHost . "api/authenticate.sjs?email=$email&password=$password&" . $this->format;
		return $this->exec($url);
	}
	/***** END PRIVATE HELPERS *****/

	public function __destruct() {
		curl_close($this->curlHandler);
	}
}

?>
