<?php

namespace Hiraeth;

use Auryn\Injector;
use Psr\Container\ContainerInterface;

/**
 *
 */
class Broker extends Injector implements ContainerInterface
{
	/**
	 *
	 */
	public function get($alias)
	{
		return $this->make($alias);
	}


	/**
	 *
	 */
	public function has($alias)
	{
		return isset($this->inspect(NULL, Broker::I_ALIASES)[Broker::I_ALIASES][strtolower($alias)]);
	}
}
