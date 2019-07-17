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
	 * @var Jin\Collection|null
	 */
	protected $environment = NULL;


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
		$this->broker = new Broker();
		$this->tracer = new SlashTrace();
		$this->parser = new Jin\Parser(['app' => $this]);

		$this->tracer->prependHandler(new DebuggingHandler($this));
		$this->tracer->prependHandler(new ProductionHandler($this));
		$this->tracer->register();

		$this->broker->share($this);

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
			$this->environment = $this->parser->parse(file_get_contents($this->getFile($env_file)));
		}

		date_default_timezone_set($this->getEnvironment('TIMEZONE', 'UTC'));
	}


	/**
	 *
	 */
	public function exec(Closure $post_boot = NULL)
	{
		ini_set('display_errors', 0);
		ini_set('display_startup_errors', 0);

		if ($this->environment) {
			$_ENV = $this->environment->get();

			foreach ($this->environment->flatten() as $name => $value) {
				@putenv(str_replace('.', '_', $name) . '=' . $value);
			}
		}

		$this->config = $this->get(Configuration::class, [
			':parser'    => $this->parser,
			':cache_dir' => $this->getEnvironment('CACHING', TRUE)
				? $this->getDirectory('storage/cache', TRUE)
				: NULL
		]);

		$this->config->load(
			$this->getDirectory($this->getEnvironment('CONFIG.DIR', 'config')),
			$this->getEnvironment('CONFIG.SOURCES', [])
		);

		foreach ($this->getConfig('*', 'application.aliases', array()) as $aliases) {
			foreach ($aliases as $interface => $target) {
				if (!interface_exists($interface)) {
					class_alias($target, $interface);
				}

				$this->broker->alias($interface, $target);
			}
		}

		foreach ($this->getConfig('*', 'application.delegates', array()) as $delegates) {
			foreach ($delegates as $delegate) {
				$this->registerDelegate($delegate);
			}
		}

		foreach ($this->getConfig('*', 'application.providers', array()) as $providers) {
			foreach ($providers as $provider) {
				$this->registerProvider($provider);
			}
		}

		if ($this->getEnvironment('LOGGING', TRUE)) {
			if (!$this->has(LoggerInterface::class)) {
				throw new RuntimeException(sprintf(
					'Logging is enabled, but "%s" does not have a registered alias',
					LoggerInterface::class
				));
			}

			$this->logger = $this->get(LoggerInterface::class);
		}

		foreach (array_reverse($this->getEnvironment('HANDLERS', [])) as $handler) {
			$this->tracer->prependHandler($this->get($handler));
		}

		$this->tracer->setApplicationPath($this->getDirectory()->getPathname());
		$this->tracer->setRelease($this->release->toJson());

		$this->record('Booting Completed');

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
	 * @var string $collection The name of the collection from which to fetch data
	 * @var string $path The dot separated path to the data
	 * @var mixed $default The default value, should the data not exist in the configuration
	 * @return mixed The value as retrieved from the configuration collection, or default
	 */
	public function getConfig(string $collection, string $path, $default = NULL)
	{
		$value = $this->config->get($collection, $path, $default);

		if ($default !== NULL) {
			settype($value, gettype($default));
		}

		if (is_array($default)) {
			$value = $value + $default;
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
		$exists = $this->hasDirectory($path);
		$path   = $this->root . DIRECTORY_SEPARATOR . $path;

		if (!$exists && $create) {
			mkdir($path, 0777, TRUE);
		}

		return new SplFileInfo(rtrim($path, '\\/'));
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
		if ($this->environment && $this->environment->has($name)) {
			$value = $this->environment->get($name);
		} elseif (getenv($name) !== FALSE) {
			$value = getenv($name);
		} else {
			$value = $default;
		}

		if ($default !== NULL) {
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
		$exists = $this->hasFile($path);
		$path   = $this->root . DIRECTORY_SEPARATOR . $path;

		if (!$exists && $create) {
			$directory = dirname($path);

			if (!is_dir($directory)) {
				mkdir($directory, 0777, TRUE);
			}

			file_put_contents($path, '');
		}

		return new SplFileInfo($path);
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
		return $this->broker->has($alias);
	}


	/**
	 *
	 */
	public function hasDirectory($path)
	{
		$path = $this->root . DIRECTORY_SEPARATOR . $path;

		return is_readable($path) && is_dir($path);
	}


	/**
	 *
	 */
	public function hasFile($path)
	{
		$path = $this->root . DIRECTORY_SEPARATOR . $path;

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
		if ($this->environment) {
			return $this->getEnvironment('DEBUG', FALSE);
		}

		return TRUE;
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
	public function share(object $instance): object
	{
		$this->broker->share($instance);

		return $instance;
	}


	/**
	 *
	 */
	protected function registerDelegate($delegate): object
	{
		if (!isset(class_implements($delegate)[Delegate::class])) {
			throw new RuntimeException(sprintf(
				'Cannot register delegate "%s", does not implemented Hiraeth\Delegate',
				$delegate
			));
		}

		$this->broker->delegate($delegate::getClass(), $delegate);

		return $this;
	}


	/**
	 *
	 */
	protected function registerProvider($provider): object
	{
		if (!isset(class_implements($provider)[Provider::class])) {
			throw new RuntimeException(sprintf(
				'Cannot register provider "%s", does not implemented Hiraeth\Provider',
				$provider
			));
		}

		foreach ($provider::getInterfaces() as $interface) {
			$this->broker->prepare($interface, function($obj, Broker $broker) use ($provider) {
				return $broker->execute($provider, [$obj]);
			});
		}

		return $this;
	}
}
