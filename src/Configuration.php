<?php

namespace Hiraeth;

use Dotink\Jin;
use RuntimeException;

/**
 *
 */
class Configuration
{
	/**
	 *
	 */
	protected $cacheDir = NULL;


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
	public function __construct(Jin\Parser $parser, $cache_dir = NULL)
	{
		$this->parser   = $parser;
		$this->cacheDir = $cache_dir;
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
			return $default;

		}
	}


	/**
	 *
	 */
	public function load($directory, array $sources = NULL)
	{
		$cache_hash = md5($directory);
		$cache_path = $this->cacheDir . '/' . $cache_hash;

		if ($this->cacheDir && is_readable($cache_path)) {
			$data = include($cache_path);

			if (is_array($data)) {
				$this->collections = $data;

				return TRUE;
			}
		}

		if ($sources) {
			foreach ($sources as $source) {
				$this->loadFromDirectory($directory . '/' . $source);
			}

		} else {
			$this->loadFromDirectory($directory);
		}

		if ($this->cacheDir && is_writable($cache_path)) {
			$this->save($cache_hash);
		}
	}


	/**
	 *
	 */
	protected function loadFromDirectory($directory, $base = NULL)
	{
		if (!$base) {
			$base = $directory;
		}

		if (!is_dir($directory)) {
			throw new \RuntimeException(sprintf(
				'Failed to load from configuration directory "%s", does not exist.',
				$directory
			));
		}

		$target_files    = glob($directory . DIRECTORY_SEPARATOR . '*.jin');
		$sub_directories = glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

		foreach ($target_files as $target_file) {
			$collection_data = $this->parser->parse(
				file_get_contents($target_file),
				TRUE
			);

			$collection_path = trim(sprintf(
				'%s' . DIRECTORY_SEPARATOR . '%s',
				str_replace($base, '', $directory),
				pathinfo($target_file, PATHINFO_FILENAME)
			), '/\\');

			if (isset($this->collections[$collection_path])) {
				$this->collections[$collection_path]->setArray(
					array_replace_recursive(
						$this->collections[$collection_path]->get(),
						$collection_data->get()
					)
				);

			} else {
				$this->collections[$collection_path] = $collection_data;
			}

			$this->stale = TRUE;
		}

		foreach ($sub_directories as $sub_directory) {
			$this->loadFromDirectory($sub_directory, $base);
		}

		return TRUE;
	}


	/**
	 *
	 */
	protected function save($hash)
	{
		if ($this->stale) {
			file_put_contents($this->cacheDir . '/' . $hash, sprintf(
				'<?php return %s;',
				var_export($this->collections, TRUE)
			));
		}
	}
}
