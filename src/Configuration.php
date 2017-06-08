<?php

namespace Hiraeth;

use Dotink\Jin;

/**
 *
 */
class Configuration
{
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
	public function __construct(Jin\Parser $parser)
	{
		$this->parser = $parser;
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
	public function load($directory, $base = NULL)
	{
		$base            = $base ?: $directory;
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
		}

		foreach ($sub_directories as $sub_directory) {
			$this->load($sub_directory, $base);
		}
	}
}
