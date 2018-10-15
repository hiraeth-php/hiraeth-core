<?php

namespace Hiraeth;

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
	 * Get the instance of the class for which the delegate operates.
	 *
	 * @access public
	 * @param Broker $broker The dependency injector instance
	 * @return Object The instance of the class for which the delegate operates
	 */
	public function __invoke(Broker $broker);
}
