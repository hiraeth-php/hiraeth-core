<?php

namespace Hiraeth;

use Dotink\Jin;
use Dotink\Flourish\Collection;
use RuntimeException;

/**
 *
 */
class Configuration
{
	/**
	 *
	 */
	protected $cacheFile = NULL;


	/**
	 *
	 */
	protected $collections = array();


	/**
	 *
	 */
	protected $parser = NULL;


	/**
	 *
	 */
	protected $stale = FALSE;


	/**
	 *
	 */
	public function __construct(Jin\Parser $parser, $cache_file = NULL)
	{
		$this->parser    = $parser;
		$this->cacheFile = $cache_file;
	}


	/**
	 *
	 */
	public function get($collection_name, $key, $default)
	{
		if ($collection_name == '*') {
			$result = array();

			foreach ($this->collections as $name => $collection) {
				$result[$name] = $collection->get($key, $default);
			}

			return $result;

		} elseif (isset($this->collections[$collection_name])) {
			return $this->collections[$collection_name]->get($key, $default);

		} else {

		}
	}


	/**
	 *
	 */
	public function load($directory)
	{
		if ($this->cacheFile && is_readable($this->cacheFile)) {
			$data = include($this->cacheFile);

			if (is_array($data)) {
				foreach ($data as $path => $config) {
					$this->collections[$path] = new Collection($config);
				}

				return TRUE;
			}
		}

		$this->loadFromDirectory($directory, $directory);
	}


	/**
	 *
	 */
	public function save()
	{
		if (!$this->cacheFile) {
			throw new RuntimeException('Cannot save configuration to cache, unspecified file');
		}

		if ($this->stale) {
			$collections = array();

			foreach ($this->collections as $path => $collection) {
				$collections[$path] = $collection->get();
			}

			file_put_contents($this->cacheFile, sprintf(
				'<?php return %s;',
				var_export($collections, TRUE)
			));
		}
	}


	/**
	 *
	 */
	protected function loadFromDirectory($directory, $base)
	{
		$target_files    = glob($directory . DIRECTORY_SEPARATOR . '*.jin');
		$sub_directories = glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

		foreach ($target_files as $target_file) {
			$collection_path = trim(sprintf(
				'%s' . DIRECTORY_SEPARATOR . '%s',
				str_replace($base, '', $directory),
				pathinfo($target_file, PATHINFO_FILENAME)
			), '/\\');

			$this->collections[$collection_path] = $this->parser->parse(
				file_get_contents($target_file),
				TRUE
			);

			$this->stale = TRUE;
		}

		foreach ($sub_directories as $sub_directory) {
			$this->loadFromDirectory($sub_directory, $base);
		}

		return TRUE;
	}
}
