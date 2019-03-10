<?php

namespace Hiraeth;

use Closure;
use SplFileInfo;
use RuntimeException;

use Adbar\Dot;

use Dotink\Jin;

use Composer\Autoload\ClassLoader;

use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;

use SlashTrace\SlashTrace;

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
class Application extends AbstractLogger
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
	 * @var Hiraeth\Broker
	 */
	protected $broker = NULL;


	/**
	 * An instance of our configuration
	 *
	 * @access protected
	 * @var Hiraeth\Configuration
	 */
	protected $config = NULL;


	/**
	 * A dot collection containing environment data
	 *
	 * @access protected
	 * @var Adbar\Dot
	 */
	protected $environment = NULL;


	/**
	 * Unique application ID
	 *
	 * @access protected
	 * @var string
	 */
	protected $id = NULL;


	/**
	 * The instance of our PSR-3 Logger
	 *
	 * @access protected
	 * @var LoggerInterface
	 */
	protected $logger = NULL;


	/**
	 * The instance of our JIN Parser
	 *
	 * @access protected
	 * @var Jin\Parser
	 */
	protected $parser = NULL;


	/**
	 * The dot collection containing release data
	 *
	 * @access protected
	 * @var Adbar\Dot
	 */
	protected $release = NULL;


	/**
	 * Absolute path to the application root
	 *
	 * @access protected
	 * @var string
	 */
	protected $root = NULL;


	/**
	 * The instance of tracer
	 *
	 * @access protected
	 * @var SlashTrace
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
		if (!class_exists('Hiraeth\Broker')) {
			class_alias('Auryn\Injector', 'Hiraeth\Broker');
		}

		$this->root   = $root_path;
		$this->tracer = new SlashTrace();
		$this->parser = new Jin\Parser(['app' => $this]);

		$this->tracer->addHandler(new DebuggingHandler($this));
		$this->tracer->addHandler(new ProductionHandler($this));
		$this->tracer->register();

		if ($this->hasDirectory(NULL)) {
			$this->tracer->setApplicationPath($this->getDirectory()->getPathname());
		}

		if ($this->hasFile($release_file)) {
			$this->release = $this->parser->parse(file_get_contents($this->getFile($release_file)));
			$this->tracer->setRelease($this->getRelease());
		}

		if ($this->hasFile($env_file)) {
			$this->environment = $this->parser->parse(file_get_contents($this->getFile($env_file)));

			foreach ($this->environment->flatten() as $name => $value) {
				$name        = str_replace('.', '_', $name);
				$_ENV[$name] = $value;
				@putenv("$name=$value");
			}
		}

		$this->broker = new Broker();
		$this->config = new Configuration(
			$this->parser,
			$this->getEnvironment('CACHING', TRUE)
				? $this->getDirectory('storage/cache', TRUE)
				: NULL
		);

		$this->broker->share($this);
		$this->broker->share($this->parser);
		$this->broker->share($this->broker);
		$this->broker->share($this->config);
		$this->broker->share($this->tracer);

		date_default_timezone_set($this->getEnvironment('TIMEZONE', 'UTC'));
	}


	/**
	 *
	 */
	public function basePath($path, $source, $destination)
	{
		$root = $this->getDirectory()->getPathname();

		if (strpos($path, $root) === 0) {
			$path = str_replace($root, '', $path);

			return str_replace($source, $destination, $path);
		}

		return '/' . $destination . substr($path, strpos($path, $source) + strlen($source));

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
	public function getConfig(string $collection, string $path, $default)
	{
		$value = $this->config->get($collection, $path, $default);

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
		if (!$this->environment) {
			return $default;
		}

		$value = $this->environment->get($name, $default);

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
	public function getId()
	{
		if (!isset($this->id)) {
			$this->id = md5(uniqid(static::class));
		}

		return $this->id;
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
	public function isDebugging()
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
	public function log($level, $message, array $context = array())
	{
		if (isset($this->logger)) {
			$this->logger->log($level, $message, $context);
		}
	}


	/**
	 *
	 */
	public function make(string $alias): object
	{
		if (isset($this->broker)) {
			return $this->broker->make($alias);
		}
	}


	/**
	 *
	 */
	public function record($message, array $context = array())
	{
		$this->tracer->recordBreadcrumb($message, $context);
	}


	/**
	 *
	 */
	public function run(Closure $post_boot)
	{
		$this->record('Booting');

		$this->config->load(
			$this->getDirectory($this->getEnvironment('CONFIG.DIR', 'config')),
			$this->getEnvironment('CONFIG.SOURCES', [])
		);

		foreach ($this->getConfig('*', 'application.aliases', array()) as $aliases) {
			foreach ($aliases as $target => $alias) {
				$this->aliases[strtolower($alias)] = strtolower($target);

				if (class_exists($target) && !class_exists($alias)) {
					class_alias($target, $alias);

				} elseif (interface_exists($target)) {
					$this->broker->alias($target, $alias);
				}
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
			if (!in_array(strtolower(LoggerInterface::class), $this->aliases)) {
				throw new RuntimeException(sprintf(
					'Logging is enabled, but "%s" does not have a registered alias',
					LoggerInterface::class
				));
			}

			$this->logger = $this->broker->make(LoggerInterface::class);

			$this->broker->share($this->logger);
		}

		$this->record('Booting Completed');

		exit($this->broker->execute(Closure::bind($post_boot, $this, $this)));
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
			$this->broker->prepare($interface, $provider);

			//
			// This is a workaround for Auryn which does not resolve class_alias() created aliases
			// for classes/interfaces on prepare.  As such, we must register the provider under
			// the aliases as well.
			//

			foreach (array_keys($this->aliases, $interface) as $alias) {
				$this->broker->prepare($alias, $provider);
			}
		}

		return $this;
	}
}
