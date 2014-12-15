<?php

require_once 'sendgrid.civix.php';

// misc filename constants
define('HTACCESS', __DIR__ . '/.htaccess');
define('HTPASSWD', __DIR__ . '/.htpasswd');
define('SETTINGS', __DIR__ . '/sendgrid.ini');
define('INFO', __DIR__ . '/info.xml');

$sendgrid_settings = array();

/*
 * sendgrid_add_url_to_xml
 *
 * adds the notification URL to info.xml
 */
function sendgrid_add_url_to_xml($username = '', $password = '') {
		$url = sendgrid_get_url($username, $password);
		$contents = preg_replace('#(<url desc="SendGrid Notification URL">).*?(</url>)#', '$1' . $url . '$2', file_get_contents(INFO));
		file_put_contents(INFO, $contents);
}

/*
 * hook_civicrm_buildForm
 *
 * add authentication fields to the outgoing email settings page
 * for use with the SendGrid Event Notification app
 */
function sendgrid_civicrm_buildForm($formName, &$form) {
	if ($formName == 'CRM_Admin_Form_Setting_Smtp') {
		$form->add('text', 'sendgrid_username', ts('Username'));
		$form->add('password', 'sendgrid_password', ts('Password'));
		$form->setDefaults(sendgrid_get_settings());
		
		$template = CRM_Core_Smarty::singleton();
		$template->appendValue('nginx', sendgrid_get_nginx());
	}
}

/*
 * hook_civicrm_install
 */
function sendgrid_civicrm_install() {
	sendgrid_add_url_to_xml();
  _sendgrid_civix_civicrm_install();
}

/*
 * hook_civicrm_postProcess
 *
 * save settings and update/create support files
 */
function sendgrid_civicrm_postProcess($formName, &$form) {

	if ($formName == 'CRM_Admin_Form_Setting_Smtp') {
		$vars = $form->getSubmitValues();
		
		if (!isset($vars['sendgrid_username']))
			return true;
		
		$username = $vars['sendgrid_username'];
		$password = $vars['sendgrid_password'];
		if (!$username || !$password)
			$username = $password = '';
			
		sendgrid_save_settings(array(
			'sendgrid_username' => $username,
			'sendgrid_password' => $password
		));
		sendgrid_add_url_to_xml($username, $password);
		sendgrid_htpasswd($username, $password);
	}
	return true;
}

/*
 * sendgrid_get_nginx
 *
 * get the ngnix configuration
 */
function sendgrid_get_nginx() {
	$common = "\nlocation " . __DIR__ . " {\n\tauth_basic";
	$nginx = array(
		'yes' => "$common \"You Shall Not Pass\";\n\tauth_basic_user_file " . HTPASSWD . ";\n}\n\n",
		'no' => "$common off;\n}\n\n"
	);
	return $nginx;
}

/*
 * sendgrid_get_settings
 *
 * return settings from the .ini file, or empty defaults
 */
function sendgrid_get_settings() {
	global $sendgrid_settings;
	
	if (empty($sendgrid_settings)) {
		if (file_exists(SETTINGS))
			$sendgrid_settings = parse_ini_file(SETTINGS);
		else
			$sendgrid_settings = array(
				'sendgrid_username' => '',
				'sendgrid_password' => ''
			);
	}
	return $sendgrid_settings;
}

/*
 * sendgrid_get_url
 *
 * get the notification URL
 */
function sendgrid_get_url($username = '', $password = '') {
	require_once('CRM/Core/Resources.php');
	$url = CRM_Core_Resources::singleton()->getUrl('com.imba.sendgrid') . 'webhook.php';
	if ($username) {
		$p = parse_url($url);
		$url = "{$p['scheme']}://$username:$password@{$p['host']}" . (!empty($p['port']) ? ":{$p['port']}" : '') . "{$p['path']}";
	}
	return $url;
}

/**
 * sendgrid_htpasswd
 *
 * enable / disable HTTP Basic Authentication
 */
function sendgrid_htpasswd($username, $plainpasswd) {  
	if ($username) {
		// begin code I found on Stack Overflow...
		// generates an apr1/md5 password for use in htpasswd files
		$tmp = '';
		$salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
		$len = strlen($plainpasswd);
		$text = $plainpasswd . '$apr1$' . $salt;
		$bin = pack('H32', md5($plainpasswd . $salt . $plainpasswd));
		for($i = $len; $i > 0; $i -= 16) {
			$text .= substr($bin, 0, min(16, $i));
		}
		for($i = $len; $i > 0; $i >>= 1) {
			$text .= ($i & 1) ? chr(0) : $plainpasswd{0};
		}
		$bin = pack('H32', md5($text));
		for($i = 0; $i < 1000; $i++) {
			$new = ($i & 1) ? $plainpasswd : $bin;
			if ($i % 3)
				$new .= $salt;
			if ($i % 7)
				$new .= $plainpasswd;
			$new .= ($i & 1) ? $bin : $plainpasswd;
			$bin = pack('H32', md5($new));
		}
		for ($i = 0; $i < 5; $i++) {
			$k = $i + 6;
			$j = $i + 12;
			if ($j == 16) $j = 5;
				$tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
		}
		$tmp = chr(0) . chr(0) . $bin[11] . $tmp;
		$tmp = strtr(strrev(substr(base64_encode($tmp), 2)),
						'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
						'./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
		$passwd = '$apr1$' . $salt . '$' . $tmp;
		// ...end code I found on Stack Overflow
	
		// create the .htpasswd file; works with both apache and nginx
		file_put_contents(HTPASSWD, "$username:$passwd\n");
		chmod(HTPASSWD, 0644);
		
		// create the .htaccess for use with apache
		file_put_contents(HTACCESS, "Options -Indexes\n\n" .
											"AuthType Basic\n" .
											"AuthName \"You Shall Not Pass\"\n" .
											"AuthUserFile ". HTPASSWD . "\n" .
											"Require valid-user\n");
		chmod(HTACCESS, 0644);
	}
	else {
		// if there is no username, delete the password file and reduce .htaccess to not produce indices
		unlink(HTPASSWD);
		file_put_contents(HTACCESS, "Options -Indexes\n");
		chmod(HTACCESS, 0644);
	}
}

/*
 * sendgrid_save_settings
 *
 * save settings to .ini file
 */
function sendgrid_save_settings($settings) {
	global $sendgrid_settings;
	
	$contents = '';
	foreach($settings as $k => $v) {
		$sendgrid_settings[$k] = $v;
		$contents .= "$k = '$v'\n";
	}
	file_put_contents(SETTINGS, $contents);
}

// *************************************
// THE REST IS JUST STANDARD BOILERPLATE
// *************************************

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function sendgrid_civicrm_config(&$config) {
  _sendgrid_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function sendgrid_civicrm_xmlMenu(&$files) {
  _sendgrid_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_uninstall
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function sendgrid_civicrm_uninstall() {
  _sendgrid_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function sendgrid_civicrm_enable() {
  _sendgrid_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function sendgrid_civicrm_disable() {
  _sendgrid_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function sendgrid_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sendgrid_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function sendgrid_civicrm_managed(&$entities) {
  _sendgrid_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function sendgrid_civicrm_caseTypes(&$caseTypes) {
  _sendgrid_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sendgrid_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sendgrid_civix_civicrm_alterSettingsFolders($metaDataFolders);
}
