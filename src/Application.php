<?php

namespace Hiraeth;

use Auryn;
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
	public function getDirectory($path)
	{
		return rtrim($this->root . DIRECTORY_SEPARATOR . $path, '\\/');
	}


	/**
	 *
	 */
	public function getEnvironment($name, $default)
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
	public function getFile($path)
	{
		return $this->root . DIRECTORY_SEPARATOR . $path;
	}


	/**
	 *
	 */
	public function run(Closure $post_boot)
	{
		if ($this->hasFile('.env')) {
			$dotenv = new Dotenv\Dotenv($this->getFile('.env'));
			$dotenv->load();
		}

		$whoops = new Whoops\Run();

		$whoops->register();

		if ($this->getEnvironment('DEBUG', TRUE)) {
			if (PHP_SAPI == 'cli') {
				$whoops->pushHandler(new Whoops\Handler\PlainTextHandler());
			} else {
				$whoops->pushHandler(new Whoops\Handler\PrettyPageHandler());
			}
		}

		$this->broker = new Auryn\Injector();
		$this->config = new Configuration(new Jin\Parser());

		$this->broker->share($this);
		$this->broker->share($this->broker);
		$this->broker->share($this->config);

		$this->config->load($this->getDirectory($this->getEnvironment('CONFIG_PATH', 'config')));

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

		$aliases = reset($this->broker->inspect(NULL, $this->broker::I_ALIASES));

		if (isset($aliases['psr\log\loggerinterface'])) {
			$logger = $this->broker->make('Psr\Log\LoggerInterface');

			$whoops->pushHandler(function($exception, $inspector, $run) use ($logger) {
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
