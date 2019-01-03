<?php

namespace Hiraeth;

use Closure;
use Dotink\Jin;
use SlashTrace\SlashTrace;
use Psr\Log\LoggerInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;

/**
 *
 */
class Application extends AbstractLogger
{
	/**
	 *
	 */
	protected $aliases = array();


	/**
	 *
	 */
	protected $broker = NULL;


	/**
	 *
	 */
	protected $config = NULL;


	/**
	 *
	 */
	protected $debugging = NULL;


	/**
	 *
	 */
	protected $environment = NULL;


	/**
	 *
	 */
	protected $id = NULL;


	/**
	 *
	 */
	protected $loader = NULL;


	/**
	 *
	 */
	protected $logger = NULL;


	/**
	 *
	 */
	protected $parser = NULL;


	/**
	 *
	 */
	protected $release = NULL;


	/**
	 *
	 */
	protected $root = NULL;


	/**
	 *
	 */
	protected $tracer = NULL;


	/**
	 *
	 */
	public function __construct($root_path, $loader, $env_file = '.env', $release_file = '.release')
	{
		if (!class_exists('Hiraeth\Broker')) {
			class_alias('Auryn\Injector', 'Hiraeth\Broker');
		}

		$this->root   = $root_path;
		$this->loader = $loader;
		$this->tracer = new SlashTrace();
		$this->parser = new Jin\Parser([
			'app' => $this
		]);

		if ($this->hasFile($release_file)) {
			$this->release = $this->parser->parse(file_get_contents($this->getFile($release_file)));
		}

		if ($this->hasFile($env_file)) {
			$this->environment = $this->parser->parse(file_get_contents($this->getFile($env_file)));

			foreach ($this->environment->flatten() as $name => $value) {
				$name        = str_replace('.', '_', $name);
				$_ENV[$name] = $value;
				@putenv("$name=$value");
			}
		}

		if ($this->isDebugging()) {
			$this->tracer->addHandler(new DebuggingHandler());
		} else {
			$this->tracer->addHandler(new ProductionHandler($this));
		}

		$this->tracer->setRelease($this->release ? $this->release->get() : NULL);
		$this->tracer->setApplicationPath($this->root);
		$this->tracer->register();

		$this->broker = new Broker();
		$this->config = new Configuration(
			$this->parser,
			$this->getEnvironment('CACHING', TRUE)
				? $this->getDirectory('writable/cache', TRUE)
				: NULL
		);

		$this->broker->share($this);
		$this->broker->share($this->parser);
		$this->broker->share($this->broker);
		$this->broker->share($this->config);
		$this->broker->share($this->tracer);
		$this->broker->share($this->loader);

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
		if (!isset($this->debugging)) {
			$this->debugging = $this->hasFile('.debug');
		}

		return $this->debugging;
	}


	/**
	 *
	 */
	public function getConfig($collection_name, $key, $default, $strict = FALSE)
	{
		$value = $this->config->get($collection_name, $key, $default);

		if (!$strict && is_array($default)) {
			$value = $value + $default;
		}

		return $value;
	}


	/**
	 *
	 */
	public function getDirectory($path = NULL, $create = FALSE)
	{
		$exists = $this->hasDirectory($path);
		$path   = $this->root . DIRECTORY_SEPARATOR . $path;

		if (!$exists && $create) {
			mkdir($path, 0777, TRUE);
		}

		return new \SplFileInfo(rtrim($path, '\\/'));
	}


	/**
	 *
	 */
	public function getEnvironment($name, $default)
	{
		return $this->environment->get($name, $default);
	}


	/**
	 *
	 */
	public function getFile($path, $create = FALSE)
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

		return new \SplFileInfo($path);
	}


	/**
	 *
	 */
	public function getId()
	{
		if (!$this->id) {
			$this->id = Uuid::uuid4();
		}

		return $this->id;
	}


	/**
	 * Logs with an arbitrary level.
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
			if (in_array(strtolower(LoggerInterface::class), $this->aliases)) {
				$this->logger = $this->broker->make(LoggerInterface::class);
			}

			if ($this->logger) {
				$this->broker->share($this->logger);
			}
		}

		$this->record('Booting Completed');

		return $this->broker->execute(Closure::bind($post_boot, $this, $this));
	}


	/**
	 *
	 */
	protected function registerDelegate($delegate)
	{
		$class = $delegate::getClass();

		$this->broker->delegate($class, $delegate);
	}


	/**
	 *
	 */
	protected function registerProvider($provider)
	{
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
	}
}
