<?php

namespace Hiraeth;

/**
 * Delegates are responsible for constructing dependencies for the dependency injector.
 *
 * Each delegate operates on a single concrete class and provides the class that it is capable
 * of building so that it can be registered easily with the application.
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
	static public function getClass(): string;


	/**
	 * Get the instance of the class for which the delegate operates.
	 *
	 * @access public
	 * @param Broker $broker The dependency injector instance
	 * @return object The instance of the class for which the delegate operates
	 */
	public function __invoke(Broker $broker): object;
}
