<?php

namespace Hiraeth;

use Closure;
use SplFileInfo;
use RuntimeException;

use Dotink\Jin;

use Defuse\Crypto\Key;

use Composer\Autoload\ClassLoader;

use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;
use Psr\Container\ContainerInterface;


use SlashTrace\SlashTrace;
use SlashTrace\EventHandler\EventHandler;

/**
 * Hiraeth application
 *
 * The application handles essentially all low level functionality that is central to any Hiraeth
 * application.  This includes:
 *
 * - Application relative file/folder requisition
 * - Application logging (proxy for registered PSR-3 logger)
 * - Environment / Configuration Setup
 * - Bootstrapping and hand-off
 *
 * This class probably does too much, or not enough.
 */
class Application extends AbstractLogger implements ContainerInterface
{
	/**
	 * A constant regex for absolute path matching
	 *
	 * @var string
	 */
	const REGEX_ABS_PATH = '#^(/|[a-z]+://).*$#';


	/**
	 * A list of interface and/or class aliases
	 *
	 * @access protected
	 * @var array
	 */
	protected $aliases = array();


	/**
	 * An instance of our dependency injector/broker
	 *
	 * @access protected
	 * @var Broker|null
	 */
	protected $broker = NULL;


	/**
	 * An instance of our configuration
	 *
	 * @access protected
	 * @var Configuration|null
	 */
	protected $config = NULL;


	/**
	 * A dot collection containing environment data
	 *
	 * @access protected
	 * @var array
	 */
	protected $environment = array();


	/**
	 * Unique application ID
	 *
	 * @access protected
	 * @var string|null
	 */
	protected $id = NULL;


	/**
	 *
	 * @access protected
	 * @var Key|null
	 */
	protected $key = NULL;


	/**
	 * The instance of our PSR-3 Logger
	 *
	 * @access protected
	 * @var LoggerInterface|null
	 */
	protected $logger = NULL;


	/**
	 * The instance of our JIN Parser
	 *
	 * @access protected
	 * @var Jin\Parser|null
	 */
	protected $parser = NULL;


	/**
	 * The dot collection containing release data
	 *
	 * @access protected
	 * @var Jin\Collection|null
	 */
	protected $release = NULL;


	/**
	 * Absolute path to the application root
	 *
	 * @access protected
	 * @var string|null
	 */
	protected $root = NULL;


	/**
	 * Shared boot / application state
	 *
	 * @access protected
	 * @var object|null
	 */
	protected $state = NULL;


	/**
	 * The instance of tracer
	 *
	 * @access protected
	 * @var SlashTrace|null
	 */
	protected $tracer = NULL;


	/**
	 * Construct the application
	 *
	 * @access public
	 * @param string $root_path The absolute path to the application root
	 * @param string $env_file The relative path to the .env file
	 * @param string $release_file The relative path to the .release file
	 * @return void
	 */
	public function __construct(string $root_path, string $env_file = '.env', string $release_file = 'local/.release')
	{
		$this->root   = $root_path;
		$this->state  = new State();
		$this->broker = new Broker();
		$this->tracer = new SlashTrace();
		$this->parser = new Jin\Parser([
			'app' => $this
		], [
			'env'  => [$this, 'getEnvironment'],
			'dir'  => function($path) { return $this->getDirectory($path, TRUE)->getPathname(); },
			'file' => function($path) { return $this->getFile($path)->getPathname(); }
		]);

		$this->broker->share($this);
		$this->broker->share($this->broker);

		$this->tracer->prependHandler(new DebuggingHandler($this));
		$this->tracer->prependHandler(new ProductionHandler($this));
		$this->tracer->register();

		if (!$this->hasDirectory(NULL)) {
			throw new RuntimeException(sprintf(
				'Invalid root path "%s" specified, not a directory.',
				$this->root
			));
		}

		if ($this->hasFile($release_file)) {
			$this->release = $this->parser->parse(file_get_contents($this->getFile($release_file)));
		} else {
			$this->release = $this->parser->parse('NAME = Unknown Release');
		}

		if ($this->hasFile($env_file)) {
			$this->environment = $_ENV = $_ENV + $this->parser
				->parse(file_get_contents($this->getFile($env_file)))
				->flatten('_');

			foreach ($this->environment as $name => $value) {
				@putenv(sprintf('%s=%s', $name, $value));
			}
		}

		umask($this->getEnvironment('UMASK', 0002));
		date_default_timezone_set($this->getEnvironment('TIMEZONE', 'UTC'));
	}


	/**
	 *
	 */
	public function __invoke() {
		return $this->state;
	}


	/**
	 *
	 */
	public function exec(Closure $post_boot = NULL)
	{
		ini_set('display_errors', 0);
		ini_set('display_startup_errors', 0);

		$bootables = array();

		$this->config = $this->get(Configuration::class, [
			':parser'    => $this->parser,
			':cache_dir' => $this->getEnvironment('CACHING', TRUE)
				? $this->getDirectory('storage/cache', TRUE)
				: NULL
		]);

		$this->config->load(
			$this->getEnvironment('CONFIG_DIR', $this->getDirectory('config')),
			$this->getEnvironment('CONFIG_SRC', NULL)
		);

		foreach ($this->getConfig('*', 'application.aliases', array()) as $aliases) {
			foreach ($aliases as $interface => $target) {
				if (!interface_exists($interface) && !class_exists($interface)) {
					class_alias($target, $interface);
				}

				$this->broker->alias($interface, $target);
			}
		}

		foreach ($this->getConfig('*', 'application.delegates', array()) as $delegates) {
			foreach ($delegates as $delegate) {
				if (!isset(class_implements($delegate)[Delegate::class])) {
					throw new RuntimeException(sprintf(
						'Cannot register delegate "%s", does not implemented Hiraeth\Delegate',
						$delegate
					));
				}

				$this->broker->delegate($delegate::getClass(), $delegate);
			}
		}

		foreach ($this->getConfig('*', 'application.providers', array()) as $providers) {
			foreach ($providers as $provider) {
				if (!isset(class_implements($provider)[Provider::class])) {
					throw new RuntimeException(sprintf(
						'Cannot register provider "%s", does not implemented Hiraeth\Provider',
						$provider
					));
				}

				foreach ($provider::getInterfaces() as $interface) {
					if ($interface == __CLASS__) {
						$bootables[] = $provider;
						continue;
					}

					$this->broker->prepare($interface, function($obj, Broker $broker) use ($provider) {
						return $broker->execute($provider, [$obj]);
					});
				}
			}
		}

		while($provider = array_shift($bootables)) {
			$this->broker->execute($provider, [$this->state]);
		}

		$this->tracer->setApplicationPath($this->getDirectory()->getRealPath());
		$this->tracer->setRelease($this->release->toJson());

		$this->record('Booting Completed', (array) $this());

		if ($post_boot) {
			exit($this->broker->execute(Closure::bind($post_boot, $this, $this)));
		}
	}


	/**
	 *
	 */
	public function get($alias, $args = array())
	{
		return $this->broker->make($alias, $args);
	}


	/**
	 * Get configuration data from the a configuration collection
	 *
	 * @access public
	 * @var string $path The collection path from which to fetch data
	 * @var string $key The value to retrieve from the collection (dot separated)
	 * @var mixed $default The default value, should the data not exist
	 * @return mixed The value/array of values as retrieved from collection(s), or default
	 */
	public function getConfig(string $path, string $key, $default = NULL)
	{
		if ($path == '*') {
			$value = array();

			foreach ($this->config->getCollectionPaths() as $path) {
				if (!$this->config->getCollection($path)->has($key)) {
					continue;
				}

				$value[$path] = $this->getConfig($path, $key, $default);
			}

		} else {
			$value = $this->config->get($path, $key, $default);

			if (!is_null($default) && !is_object($default)) {
				settype($value, gettype($default));
			}

			if (is_array($default)) {
				$value = $value + $default;
			}

		}

		return $value;
	}


	/**
	 * Get a directory for an app relative path
	 *
	 * @access public
	 * @var string $path The relative path for the directory, e.g. 'writable/public'
	 * @var bool $create Whether or not the directory should be created if it does not exist
	 * @return SplFileInfo An SplFileInfo object referencing the directory
	 */
	public function getDirectory(string $path = NULL, bool $create = FALSE): SplFileInfo
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		$info   = new SplFileInfo($path);
		$exists = @file_exists($info->getPathname());

		if (!$exists && $create) {
			mkdir($info->getPathname(), 0777, TRUE);
		}

		return $info;
	}


	/**
	 * Get a value or all values from the environment
	 *
	 * If no arguments are supplied, this method will return all environment data as an
	 * array.
	 *
	 * @access public
	 * @var string $name The name of the environment variable
	 * @var mixed $default The default data, should the data not exist in the environment
	 * @return mixed The value as retrieved from the environment, or default
	 */
	public function getEnvironment(string $name = NULL, $default = NULL)
	{
		if (array_key_exists($name, $this->environment)) {
			$value = $this->environment[$name];
		} else {
			$value = $default;
		}

		if (!is_null($default) && !is_object($default)) {
			settype($value, gettype($default));
		}

		if (is_array($default)) {
			$value = $value + $default;
		}

		return $value;
	}


	/**
	 * Get a file for an app relative path
	 *
	 * @access public
	 * @var string $path The relative path for the file, e.g. 'writable/public/logo.jpg'
	 * @var bool $create Whether or not the file should be created if it does not exist
	 * @return SplFileInfo An SplFileInfo object referencing the directory
	 */
	public function getFile(string $path, bool $create = FALSE): SplFileInfo
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		$info   = new SplFileInfo($path);
		$exists = @file_exists($info->getPathname());

		if (!$exists && $create) {
			$this->getDirectory($info->getPath(), TRUE);
			$info->openfile('w')->fwrite('');
		}


		return $info;
	}


	/**
	 *
	 */
	public function getId(): string
	{
		if (!isset($this->id)) {
			$this->id = md5(uniqid(static::class));
		}

		return $this->id;
	}


	/**
	 *
	 */
	public function getKey(): Key
	{
		if (!$this->key) {
			if (!$this->hasFile('storage/key')) {
				$this->key = Key::createNewRandomKey();

				$this->getFile('storage/key', TRUE)->openFile('w')->fwrite(sprintf(
					'<?php return %s;',
					var_export($this->key->saveToAsciiSafeString(), TRUE)
				));

			} else {
				$this->key = Key::loadFromAsciiSafeString(
					include($this->getFile('storage/key')->getRealPath())
				);
			}
		}

		return $this->key;
	}


	/**
	 *
	 */
	public function getRelease($name = NULL, $default = NULL)
	{
		return $this->release->get($name, $default);
	}


	/**
	 *
	 */
	public function has($alias)
	{
		return class_exists($alias) || $this->broker->has($alias);
	}


	/**
	 *
	 */
	public function hasDirectory($path)
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		return is_readable($path) && is_dir($path);
	}


	/**
	 *
	 */
	public function hasFile($path)
	{
		if (!$path || !preg_match(static::REGEX_ABS_PATH, $path)) {
			$path = $this->root . DIRECTORY_SEPARATOR . $path;
		}

		return is_readable($path) && is_file($path);
	}


	/**
	 *
	 */
	public function isCLI()
	{
		return (
			defined('STDIN')
			|| php_sapi_name() === 'cli'
			|| !array_key_exists('REQUEST_METHOD', $_SERVER)
		);
	}


	/**
	 *
	 */
	public function isDebugging(): bool
	{
		if (!$this->environment) {
			return FALSE;
		}

		return $this->getEnvironment('DEBUG', FALSE);
	}


	/**
	 * Logs a message with an arbitrary level.
	 *
	 * @param mixed $level
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function log($level, $message, array $context = array()): void
	{
		if (isset($this->logger)) {
			$this->logger->log($level, $message, $context);
		}
	}


	/**
	 *
	 */
	public function record($message, array $context = array()): Application
	{
		$this->tracer->recordBreadcrumb($message, $context);

		return $this;
	}


	/**
	 *
	 */
	public function run($target, array $parameters = array())
	{
		return $this->broker->execute($target, $parameters);
	}


	/**
	 *
	 */
	public function setLogger(string $logger): Application
	{
		$this->logger = $this->get($logger);

		return $this;
	}


	/**
	 *
	 */
	public function setHandler(string $handler): Application
	{
		$this->tracer->prependHandler($this->get($handler));

		return $this;
	}


	/**
	 *
	 */
	public function share(object $instance): object
	{
		$this->broker->share($instance);

		return $instance;
	}
}
