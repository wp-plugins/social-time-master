<?php

/*
	Plugin Name: Social Time Master
	Plugin URI: http://www.socialtimemaster.com
	Description: Social Time Master is a social autoposter, which brings the power of one click scheduling, posting to unlimited social accounts, bit.ly integration with click stats, visual management of the posts with a Timeline component.
	Version: 1.0.5
	Author: Stanil Dobrev
	Author URI: http://www.wiziva.com
    Copyright 2014 wiziva.com (email : support@wiziva.com)
*/


// get wordpress version number and fill it up to 9 digits
$int_wp_version = preg_replace('#[^0-9]#', '', get_bloginfo('version'));
while(strlen($int_wp_version) < 9) $int_wp_version .= '0'; 

// get php version number and fill it up to 9 digits
$int_php_version = preg_replace('#[^0-9]#', '', phpversion());
while(strlen($int_php_version) < 9) $int_php_version .= '0'; 

if ($int_wp_version >= 300000000 && 		// Wordpress version > 3.0
	$int_php_version >= 520000000 && 		// PHP version > 5.2
	defined('ABSPATH') && 					// Plugin is not loaded directly
	defined('WPINC')) {						// Plugin is not loaded directly
	define('STM_DIR', dirname(__FILE__));
	define('STM_URL', plugins_url('/', __FILE__));
	define('STM_URL_Encoded', urlencode(plugins_url('/', __FILE__)));
	define('STM_PLUGIN_NAME' , 'Social Time Master');
	define('STM_PLUGIN_SLUG' , 'stm');
	define('STM_PLUGIN_VERSION' , '1.0.5');
	define('STM_WIZIVA_ID' , 264);
	require_once(dirname(__FILE__).'/class.main.php');
	$stmplugin = new STMPlugin();
}
else add_action('admin_notices', 'stm_incomp');

function stm_incomp(){
	echo '<div id="message" class="error">
	<p><b>The "Social Time Master" Plugin does not work on this WordPress installation!</b></p>
	<p>Please check your WordPress installation for following minimum requirements:</p>
	<p>
	- WordPress version 3.0 or higer<br />
	- PHP version 5.2 or higher<br />
	</p>
	<p>Do you need help? Contact <a href="mailto:support@wiziva.com">Support</a></p>
	</div>';
}

register_activation_hook(__FILE__, 'STMActivate');

function STMActivate() {
	global $wpdb;
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_accounts` (
		  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
		  `parentid` int(10) unsigned NOT NULL,
		  `atype` varchar(20) NOT NULL,
		  `username` varchar(255) NOT NULL,
		  `auth1` varchar(255) NOT NULL,
		  `auth2` varchar(255) NOT NULL,
		  `auth3` varchar(255) NOT NULL,
		  `auth4` varchar(255) NOT NULL,
		  `info` mediumtext NOT NULL,
		  `paused` tinyint(1) unsigned NOT NULL DEFAULT '0',
		  `prefstart` varchar(5) NOT NULL DEFAULT '14:00',
		  `prefend` varchar(5) NOT NULL DEFAULT '18:00',
		  PRIMARY KEY (`id`),
		  KEY `atype` (`atype`),
		  KEY `parentid` (`parentid`),
		  KEY `atype_2` (`atype`),
		  KEY `paused` (`paused`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_postlog` (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `postid` bigint(20) unsigned NOT NULL,
		  `variationid` bigint(20) unsigned NOT NULL,
		  `atype` varchar(10) NOT NULL,
		  `aname` varchar(255) NOT NULL,
		  `userid` varchar(50) NOT NULL,
		  `rpostid` varchar(50) NOT NULL,
		  `url` varchar(255) NOT NULL,
		  `bitly` varchar(50) NOT NULL,
		  `posturl` varchar(255) NOT NULL,
		  `content` text NOT NULL,
		  `ptime` int(10) unsigned NOT NULL,
		  `numvar` tinyint(3) unsigned NOT NULL,
		  PRIMARY KEY (`id`),
		  KEY `postid` (`postid`),
		  KEY `variationid` (`variationid`),
		  KEY `atype` (`atype`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_schedule` (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `templid` smallint(5) unsigned NOT NULL,
		  `postid` bigint(20) unsigned NOT NULL,
		  `variationid` bigint(20) unsigned NOT NULL,
		  `numschedule` tinyint(3) unsigned NOT NULL,
		  `numvar` tinyint(3) unsigned NOT NULL,
		  `rotatenum` tinyint(3) unsigned NOT NULL,
		  `intnum` smallint(5) unsigned NOT NULL,
		  `inttype` enum('m','h','d') NOT NULL,
		  `repcount` tinyint(3) unsigned NOT NULL,
		  `repdone` tinyint(3) unsigned NOT NULL,
		  `repnum` smallint(5) unsigned NOT NULL,
		  `reptype` enum('m','h','d') NOT NULL,
		  `intsec` int(10) unsigned NOT NULL,
		  `repsec` int(10) unsigned NOT NULL,
		  `lastposttime` int(10) unsigned NOT NULL,
		  `nextposttime` int(10) unsigned NOT NULL,
		  `accountid` int(10) unsigned NOT NULL,
		  `fordel` tinyint(1) unsigned NOT NULL,
		  PRIMARY KEY (`id`),
		  KEY `templid` (`templid`),
		  KEY `postid` (`postid`),
		  KEY `variationid` (`variationid`),
		  KEY `numschedule` (`numschedule`),
		  KEY `numvar` (`numvar`),
		  KEY `accountid` (`accountid`),
		  KEY `fordel` (`fordel`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_templates` (
		  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
		  `numvars` tinyint(3) unsigned NOT NULL,
		  `numaccounts` tinyint(3) unsigned NOT NULL,
		  `numschedules` tinyint(3) unsigned NOT NULL,
		  `numposts` smallint(5) unsigned NOT NULL,
		  `title` varchar(30) NOT NULL,
		  PRIMARY KEY (`id`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_timeline` (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `scheduleid` bigint(20) unsigned NOT NULL,
		  `variationid` bigint(20) unsigned NOT NULL,
		  `posttime` int(10) unsigned NOT NULL,
		  `accountid` int(10) unsigned NOT NULL,
		  `isnew` tinyint(1) unsigned NOT NULL,
		  PRIMARY KEY (`id`),
		  KEY `scheduleid` (`scheduleid`),
		  KEY `variationid` (`variationid`),
		  KEY `accountid` (`accountid`),
		  KEY `isnew` (`isnew`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_urls` (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `url` varchar(255) NOT NULL,
		  `bitly` varchar(50) NOT NULL,
		  `clickcount` smallint(5) unsigned NOT NULL,
		  `numshares` smallint(5) unsigned NOT NULL,
		  PRIMARY KEY (`id`)
		);
	");
    $wpdb->query("
		CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}stm_variations` (
		  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		  `postid` bigint(20) unsigned NOT NULL,
		  `numvar` tinyint(3) unsigned NOT NULL,
		  `title` text NOT NULL,
		  `content` text NOT NULL,
		  `url` text NOT NULL,
		  `imgurl` text NOT NULL,
		  `starttm` int(10) unsigned NOT NULL,
		  PRIMARY KEY (`id`),
		  KEY `postid` (`postid`),
		  KEY `numvar` (`numvar`)
		);
	");
}

?>