<?php

namespace Hiraeth;

class State
{
	/**
	 *
	 */
	protected $_methods = array();


	/**
	 *
	 */
	public function __set($param, $value)
	{
		if (is_callable($value)) {
			$this->_methods[strtolower($param)] = $value;
		} else {
			$this->$value = $value;
		}
	}


	/**
	 *
	 */
	public function __call($method, $args) {
		$method = strtolower($method);

		if (!isset($this->_methods[$method])) {
			throw new RuntimeException(sprintf(
				'No registered callable at "%s"',
				$method
			));
		}

		return $this->_methods[$method](...$args);
	}
}
