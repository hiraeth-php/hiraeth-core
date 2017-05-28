<?php

namespace Hiraeth;

use Auryn;

/**
 * Providers add additional dependencies or configuration for objects of certain interfaces.
 */
interface Provider
{
	/**
	 * Get the interfaces for which the provider operates.
	 *
	 * @access public
	 * @return array A list of interfaces for which the operates
	 */
	static public function getInterfaces();


	/**
	 * Prepare the instance.
	 *
	 * @access public
	 * @return Object The prepared instance
	 */
	public function __invoke($instance, Aurny\Injector $broker);
}
