<?php

namespace Hiraeth;

use Dotenv;
use Whoops;
use Closure;
use Dotink\Jin;

/**
 *
 */
class Application
{
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
	protected $loader = NULL;


	/**
	 *
	 */
	protected $root = NULL;


	/**
	 *
	 */
	public function __construct($root_path, $loader)
	{
		$this->root   = $root_path;
		$this->loader = $loader;
	}



	/**
	 *
	 */
	public function basePath($path, $subpath = NULL)
	{
		return str_replace($this->getDirectory($subpath), '', $path);
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
	public function getDirectory($path = NULL, $create = FALSE)
	{
		$exists = $this->hasDirectory($path);
		$path   = $this->root . DIRECTORY_SEPARATOR . $path;

		if (!$exists && $create) {
			mkdir($path, 0777, TRUE);
		}

		return rtrim($path, '\\/');
	}


	/**
	 *
	 */
	public function getEnvironment($name, $default = NULL)
	{
		$value = getenv($name);

		if ($value === FALSE) {
			return $default;
		} else {
			return $value;
		}
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

		return $path;
	}


	/**
	 *
	 */
	public function run(Closure $post_boot)
	{
		if (!class_exists('Hiraeth\Broker')) {
			class_alias('Auryn\Injector', 'Hiraeth\Broker');
		}

		if ($this->hasFile('.env')) {
			$dotenv = new Dotenv\Dotenv($this->getDirectory());
			$dotenv->load();
		}

		date_default_timezone_set($this->getEnvironment('TIMEZONE', 'UTC'));

		$whoops = new Whoops\Run();

		if ($this->getEnvironment('DEBUG')) {
			if (PHP_SAPI == 'cli') {
				$whoops->register()->pushHandler(new Whoops\Handler\PlainTextHandler());
			} else {
				$whoops->register()->pushHandler(new Whoops\Handler\PrettyPageHandler());
			}
		}

		$config_path = $this->getDirectory($this->getEnvironment('CONFIG_PATH', 'config'));

		if ($this->getEnvironment('CACHING', TRUE)) {
			$cache_file = $this->getFile('writable/cache/' . md5($config_path), TRUE);
		} else {
			$cache_file = NULL;
		}

		$this->broker = new Broker();
		$this->config = new Configuration(new Jin\Parser(), $cache_file);

		$this->config->load($config_path);

		if ($cache_file) {
			$this->config->save();
		}

		$this->broker->share($this);
		$this->broker->share($this->broker);
		$this->broker->share($this->config);

		foreach ($this->config->get('*', 'application.aliases', array()) as $aliases) {
			foreach ($aliases as $target => $alias) {
				if (class_exists($target) && !class_exists($alias)) {
					class_alias($target, $alias);

				} elseif (interface_exists($target)) {
					$this->broker->alias($target, $alias);
				}
			}
		}

		foreach ($this->config->get('*', 'application.delegates', array()) as $delegates) {
			foreach ($delegates as $delegate) {
				$this->registerDelegate($delegate);
			}
		}

		foreach ($this->config->get('*', 'application.providers', array()) as $providers) {
			foreach ($providers as $provider) {
				$this->registerProvider($provider);
			}
		}

		$aliases = $this->broker->inspect(NULL, Broker::I_ALIASES)[Broker::I_ALIASES];

		if (isset($aliases['psr\log\loggerinterface'])) {
			$logger = $this->broker->make('Psr\Log\LoggerInterface');

			$whoops->register()->pushHandler(function($exception, $inspector, $run) use ($logger) {
				$logger->error(sprintf(
					'Message: %s, Trace: %s',
					$exception->getMessage(),
					$exception->getTraceAsString()
				));
			});
		}

		return $this->broker->execute(Closure::bind($post_boot, $this, $this));
	}


	/**
	 *
	 */
	protected function registerDelegate($delegate)
	{
		$class = $delegate::getClass();

		foreach ($delegate::getInterfaces() as $interface) {
			$this->broker->alias($interface, $class);
		}

		$this->broker->delegate($class, $delegate);
	}


	/**
	 *
	 */
	protected function registerProvider($provider)
	{
		foreach ($provider::getInterfaces() as $interface) {
			$this->broker->prepare($interface, $provider);
		}
	}
}
