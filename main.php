#!/usr/bin/env php
<?php
/**
 * Main file
 *
 * This file calls all other functions required to run the bot
 *
 * @author Christoph Mende <mende.christoph@gmail.com>
 * @package foobot
 */

if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50300)
	die ('This script needs PHP 5.3 or higher!');

require_once 'includes/settings.php';
require_once 'includes/misc_functions.php';
require_once 'includes/plugins.php';

// Load settings
settings::load($argc, $argv);

// Set default timezone
date_default_timezone_set(settings::$timezone);

// Load plugins
foreach (glob('plugins/*.php') as $file) {
	$file = basename($file);
	$file = substr($file, 0, -4);
	if (!in_array($file, settings::$plugin_blacklist))
		plugins::load($file);
}
plugins::load_timed();

$bot = bot::get_instance();
$bot->load_aliases();
$bot->usr = new user();
$bot->connect();
if (!$bot->is_connected())
	die ('Failed to connect');
$bot->post_connect();

for (;;) {
	if (!$bot->is_connected())
		$bot->reconnect();

	if ($bot->is_connected())
		$bot->wait();
}

?>
