<?php
/**
 * definitions plugin
 *
 * @author Christoph Mende <mende.christoph@gmail.com>
 * @package foobot
 */

/**
 * Implementation of plugin_interface
 * @package foobot
 * @subpackage plugins
 */
class definitions extends plugin_interface
{
	public function init()
	{
		$trigger = '/^' . preg_quote(settings::$command_char, '/') . '(?<item>\S+)(\?| is (?<definition>.+))$/';
		$this->register_event('text', $trigger, 'define');
		$this->register_event('command', 'forget');

		$this->register_help('forget', 'forget definitions');

		db::get_instance()->query('CREATE TABLE IF NOT EXISTS definitions (item varchar(50) unique, description text)');
	}

	public function define($args)
	{
		$db = db::get_instance();

		$def = $db->query('SELECT * FROM `definitions` WHERE `item` LIKE ?', $args['item'])->fetchObject();
		if (isset ($args['definition'])) {
			if (!$def)
				$db->query('INSERT INTO `definitions` VALUES(?, ?)', $args['item'], $args['definition']);
			else
				$db->query('UPDATE `definitions` SET `description` = ? WHERE `item` LIKE ?', $args['definition'], $args['item']);
			parent::answer('Okay.');
			return;
		}
		if (!$def)
			parent::answer($args['item'] . ' is undefined');
		else
			parent::answer($def->item . ' is ' . $def->description);
	}

	public function forget($args)
	{
		$db = db::get_instance();

		if (empty ($args)) {
			parent::answer('Forget what?');
			return;
		}
		$db->query('DELETE FROM `definitions` WHERE `item` LIKE ?', $args[0]);
		self::answer('Okay');
	}
}

?>
