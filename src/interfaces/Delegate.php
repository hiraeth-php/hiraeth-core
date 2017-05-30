<?php

namespace Hiraeth;

use Auryn;

/**
 * Delegates are responsible for constructing dependencies for the dependency injector.
 */
interface Delegate
{
	/**
	 * Get the class for which the delegate operates.
	 *
	 * @static
	 * @access public
	 * @return string The class for which the delegate operates
	 */
	static public function getClass();


	/**
	 * Get the interfaces for which the delegate operates.
	 *
	 * @static
	 * @access public
	 * @return array A list of interfaces for which the delegate provides a class
	 */
	static public function getInterfaces();


	/**
	 * Get the instance of the class for which the delegate operates.
	 *
	 * @access public
	 * @param Auryn\Injector $broker The dependency injector instance
	 * @return Object The instance of the class for which the delegate operates
	 */
	public function __invoke(Auryn\Injector $broker);
}
