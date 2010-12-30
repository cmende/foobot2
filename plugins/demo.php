<?php
/**
 * foobot plugin demo
 *
 * @author Christoph Mende <angelos@unkreativ.org>
 * @package foobot
 **/

/**
 * Implementation of plugin_interface
 * @package foobot
 * @subpackage plugins
 **/
class demo extends plugin_interface
{
	/**
	 * Plugin initialization
	 * @see plugin_interface::register_command()
	 **/
	public function load()
	{
		parent::register_command('ping', 'pub_ping');

		// Register help for the plugin
		parent::register_help('demo', 'Plugin demonstration');
		// Register help for the plugin's command 'ping'
		parent::register_help('ping', 'Simple ping command');
	}

	/**
	 * Ping function
	 * @param mixed $dummy unused
	 **/
	public function pub_ping($dummy)
	{
		parent::answer('pong');
	}
}

?>
