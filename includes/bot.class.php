<?php
/**
 * bot class
 *
 * Low-level bot interaction
 * @author Christoph Mende <mende.christoph@gmail.com>
 * @package foobot
 */

/**
 * Logging level: Debug output
 */
define('DEBUG', 0);
/**
 * Logging level: Notices
 */
define('INFO', 1);
/**
 * Logging level: Warnings
 */
define('WARNING', 2);
/**
 * Logging level: Errors
 */
define('ERROR', 3);

/**
 * bot class
 * @package foobot
 * @subpackage classes
 */
class bot
{
	/**
	 * The bot's internal userlist
	 * @var array
	 */
	private $userlist = array();

	/**
	 * Is the bot connected?
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Current channel for events
	 * @var string
	 */
	public $channel = '';

	/**
	 * Current user for events
	 * @var user
	 */
	public $usr = NULL;

	/**
	 * The protocol to use (e.g. IRC)
	 * @var communication
	 */
	private $protocol = NULL;

	/**
	 * Global instance of this class
	 * @access private
	 * @var bot
	 */
	private static $instance = NULL;

	/**
	 * List of joined channels
	 * @access private
	 * @var array
	 */
	private $channels = array();

	/**
	 * Socket for the connection
	 * @access private
	 * @var resource
	 */
	private $socket;

	/**
	 * Resource for the log file
	 * @access private
	 * @var resource
	 */
	private $log_fp;

	/**
	 * Command aliases
	 * @access private
	 * @var array
	 */
	private $aliases = array();

	/**
	 * Ignore list, users in this array are ignored
	 * @access private
	 * @var array
	 */
	private $ignore_list = array();

	/**
	 * Timestamp of last connection attempt
	 * @access private
	 * @var int
	 */
	private $last_connect = 0;

	/**
	 * Returns the global instance for the bot class
	 * @return bot The instance
	 */
	public static function get_instance()
	{
		if (self::$instance == NULL)
			self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Constructor, creates log dir if neccessary and opens the socket
	 * and log resource handle.
	 * @access private
	 */
	private function __construct()
	{
		if (!file_exists('logs'))
			mkdir('logs');

		$this->open_log();
	}

	/**
	 * @ignore
	 */
	private function __clone() {}

	/**
	 * Open log file
	 * @access private
	 */
	private function open_log()
	{
		$filename = 'logs/' . settings::$network . '.log';
		$this->log_fp = fopen($filename, 'a+');
		if (!$this->log_fp)
			die ('Failed to open log file');
	}

	/**
	 * Is the bot connected to a network?
	 * @return bool result
	 */
	public function is_connected() {
		return $this->connected;
	}

	/**
	 * Wrapper function to access the internal userlist
	 * @param string $nick entry to access
	 * @return user empty object if now such nick is in the userlist or the
	 *              userlist object
	 **/
	public function get_userlist($nick)
	{
		if (isset ($this->userlist[$nick]))
			return $this->userlist[$nick];
		return new user();
	}

	/**
	 * Register command alias
	 * @param string $alias name of the alias
	 * @param string $function name of the function
	 * @param array $args arguments passed to the function
	 * @param int $id id of the alias (only used to load aliases from sql)
	 */
	public function register_alias($alias, $function, $args = NULL, $id = 0)
	{
		$function = strtolower($function);

		if ($id == 0) {
			$db = db::get_instance();
			$db->query('INSERT INTO `aliases` (`alias`, `function`, `args`)
					VALUES(?, ?, ?)', $alias, $function, serialize($args));

			$id = $db->lastInsertId();
		}

		$this->aliases[$alias] = array('function' => $function,
				'args' => $args,
				'id' => $id);
	}

	/**
	 * Load aliases from sql
	 */
	public function load_aliases()
	{
		$aliases = db::get_instance()->query('SELECT * FROM `aliases`');
		while ($alias = $aliases->fetchObject())
			$this->register_alias($alias->alias, $alias->function, unserialize($alias->args), $alias->id);
	}

	/**
	 * Remoev alias from db and array
	 * @param string $alias name of the alias
	 */
	public function remove_alias($alias)
	{
		$alias = strtolower($alias);
		$db = db::get_instance();
		$db->query('DELETE FROM `aliases` WHERE `id` = ?', $this->aliases[$alias]['id']);
		unset ($this->aliases[$alias]);
	}

	/**
	 * Get alias
	 * @return mixed false or the function
	 * @param string $alias name of the alias
	 */
	public function get_alias($alias)
	{
		$alias = strtolower($alias);
		if (isset ($this->aliases[$alias]))
			return $this->aliases[$alias];
		else
			return false;
	}

	/**
	 * Ignore user
	 * @param string $nick nickname of the user
	 */
	public function add_ignore($nick) {
		if (!in_array($nick, $this->ignore_list))
			$this->ignore_list[] = $nick;
	}

	/**
	 * Unignore user
	 * @param string $nick nickname of the user
	 */
	public function remove_ignore($nick) {
		$key = array_search($nick, $this->ignore_list);
		if ($key !== false)
			unset ($this->ignore_list[$key]);
	}

	/**
	 * Connect the bot
	 * @return bool
	 */
	public function connect()
	{
		$this->last_connect = time();
		$this->protocol = new settings::$protocol;
		$this->log(DEBUG, 'Connecting');
		$this->socket = fsockopen(settings::$server, settings::$port);
		if (!$this->socket)
			return false;
		$retval = $this->protocol->connect();
		if ($retval) {
			$this->log(DEBUG, 'Connected');
			$this->connected = true;
			stream_set_blocking($this->socket, 0);
		}
		return $retval;
	}

	/**
	 * Stuff that needs to be done after connecting
	 */
	public function post_connect()
	{
		$this->protocol->post_connect();

		if (!empty (settings::$debug_channel))
			$this->join(settings::$debug_channel['channel'], settings::$debug_channel['key']);

		$this->log(DEBUG, 'Joining channels');
		foreach (settings::$channels as $channel => $key) {
			$this->log(DEBUG, 'Joining ' . $channel);
			$this->join($channel, $key);
		}
	}

	private function disconnect() {
		$this->channels = array();
		$this->connected = false;
	}

	/**
	 * Wrapper around bot::connect() and bot::post_connect()
	 */
	public function reconnect()
	{
		if ($this->last_connect > time() - 60)
			return;

		$this->connect();
		if ($this->connected)
			$this->post_connect();
	}

	/**
	 * Join a channel
	 * @param string $channel name of the channel
	 * @param string $key key of the channel
	 */
	public function join($channel, $key = NULL)
	{
		if (in_array($channel, $this->channels))
			return;
		$this->channels[] = $channel;
		$this->protocol->join($channel, $key);
	}

	/**
	 * Part a channel
	 * @param string $channel name of the channel
	 */
	public function part($channel)
	{
		if (!in_array($channel, $this->channels))
			return;

		$key = array_search($channel, $this->channels);
		unset($this->channels[$key]);
		$this->protocol->part($channel);
	}

	/**
	 * Send raw protocol data
	 * @param string $raw data
	 */
	public function send($raw)
	{
		$this->protocol->send($raw);
	}

	/**
	 * Write data to the socket
	 * @param string $data data
	 */
	public function write($data)
	{
		if (fputs($this->socket, $data) === false)
			$this->disconnect();
	}

	/**
	 * Read data from the socket
	 */
	public function read()
	{
		$buf = fgets($this->socket);
		if ($buf === false)
			$this->disconnect();
		return $buf;
	}

	/**
	 * Write a log entry
	 * @param int $level log level
	 * @param string $msg text to log
	 */
	public function log($level, $msg)
	{
		$logstring = date('Y-m-d H:i') . ': ' . $msg . LF;
		fputs($this->log_fp, $logstring);
	}

	/**
	 * Log execution of commands
	 * @param string $nick who executed the command
	 * @param string $origin where was it executed
	 * @param string $cmd which command
	 * @param array $args args to the command (will be json encoded)
	 */
	public function log_cmd($nick, $origin, $cmd, $args)
	{
		$logstring = 'Command "' . $cmd . '" executed by "' . $nick . '" with arguments ' . json_encode($args);
		if ($origin != $nick)
			$logstring .= ' (in ' . $origin . ')';
		else
			$logstring .= ' (via query)';
		$this->log(INFO, $logstring);
	}

	/**
	 * Wait for action on the socket and execute timed and recurring events
	 */
	public function wait()
	{
		$this->protocol->tick();

		$nul = NULL;
		$sock = array($this->socket);
		if (stream_select($sock, $nul, $nul, 1)) {
			$line = $this->read();
			if (!$line) {
				fclose($this->socket);
				$this->disconnect();
			} else {
				$this->parse($line);
			}
		}

		plugins::run_recurring();
		plugins::run_timed();
	}

	/**
	 * Parse incoming messages
	 * @access private
	 * @param string $line
	 */
	private function parse($line)
	{
		$db = db::get_instance();

		$line = trim($line);

		if (!strncmp($line, 'PING :', 6))
			$this->send('PONG ' . strstr($line, ':'));

		// Update userlist on JOIN, NICK and WHO events
		if (preg_match('/:\S+ 352 ' . settings::$nick . ' \S+ (?<ident>\S+) (?<host>\S+) \S+ (?<nick>\S+) \S+ :\d+( (?<realname>.+))?/', $line, $whoinfo) ||
			preg_match('/:(?<nick>.+)!(?<ident>.+)@(?<host>.+) JOIN :(?<channel>\S+)/', $line, $whoinfo) ||
			preg_match('/:(?<oldnick>\S+)!(?<ident>\S+)@(?<host>\S+) NICK :(?<nick>\S+)/', $line, $whoinfo)) {
			$this->userlist[$whoinfo['nick']] = new user($whoinfo['nick'], $whoinfo['ident'], $whoinfo['host']);
			if (isset ($whoinfo['realname']))
				$this->userlist[$whoinfo['nick']]->realname = $whoinfo['realname'];
			if (!isset ($whoinfo['realname']) && !isset ($whoinfo['oldnick']))
				$this->send('WHO ' . $whoinfo['nick']);
			if (isset ($whoinfo['oldnick']))
				unset ($this->userlist[$whoinfo['oldnick']]);
			if (isset ($whoinfo['channel'])) {
				$args = array('nick' => $whoinfo['nick'],
						'channel' => $whoinfo['channel']);
				plugins::run_event('join', NULL, $args);
			}
		}

		// Parse PRIVMSG
		if (preg_match('/:(?<nick>\S+)!(?<ident>\S+)@(?<host>\S+) PRIVMSG (?<target>\S+) :(?<text>.+)/', $line, $matches)) {
			$nick = $matches['nick'];
			$target = $matches['target'];
			$text = $matches['text'];

			$this->usr = $this->get_userlist($nick);
			if (!$this->usr->nick)
				$this->send('WHO ' . $nick);

			if (in_array($nick, $this->ignore_list))
				return;

			// Set channel to the origin's nick if the PRIVMSG was
			// sent directly to the bot
			if ($target == settings::$nick)
				$this->channel = $nick;
			else
				$this->channel = $target;

			$return = false;
			if (strncasecmp($text, settings::$command_char, strlen(settings::$command_char)) == 0 ||
					strncasecmp($text, settings::$nick . ': ', strlen(settings::$nick) + 2) == 0 ||
					$this->channel == $nick) {
				if (strncasecmp($text, settings::$nick . ': ', strlen(settings::$nick) + 2) == 0)
					$text = substr($text, strlen(settings::$nick) + 2);

				if (strncasecmp($text, settings::$command_char, strlen(settings::$command_char)) == 0)
					$text = substr($text, strlen(settings::$command_char));

				$args = explode(' ', trim($text));
				$cmd = strtolower(array_shift($args));
				$return = plugins::run_event('command', $cmd, $args);
				$alias = $this->get_alias($cmd);
				if ($alias) {
					$alias['args'] = array_merge($alias['args'], $args);
					$return = plugins::run_event('command', $alias['function'], $alias['args']);
				}
				if (!$return && $this->channel == $nick)
					$this->say(settings::$main_channel['channel'], $text, "<$nick> ");
			}
			if (!$return)
				plugins::run_event('text', $matches['text']);
		}
	}

	/**
	 * Send text to target
	 * @param string $target where to send text to
	 * @param string $text text to send
	 */
	public function say($target, $text, $prefix = "")
	{
		$this->protocol->say($target, $text, $prefix);
	}

	/**
	 * Send text to target
	 * @param string $target where to send text to
	 * @param string $text text to send
	 */
	public function notice($target, $text)
	{
		$this->protocol->notice($target, $text);
	}

	/**
	 * Send an action to target
	 * @param string $target where to send the action to
	 * @param string $text what to send
	 */
	public function act($target, $text)
	{
		$this->protocol->act($target, $text);
	}

	/**
	 * Shut the bot down
	 * @param string $msg Quitmsg
	 */
	public function shutdown($msg = '')
	{
		$this->protocol->quit($msg);
		plugins::run_event('shutdown');
		exit;
	}
}

?>
