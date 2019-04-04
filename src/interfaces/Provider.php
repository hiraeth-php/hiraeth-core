<?php

namespace Hiraeth;

/**
 * Providers add additional dependencies or configuration for objects of certain interfaces.
 *
 * Each provider operates on one or more interfaces and provides the interfaces that it is capable
 * of providing for so that it can be registered easily with the application.
 */
interface Provider
{
	/**
	 * Get the interfaces for which the provider operates.
	 *
	 * @access public
	 * @return array A list of interfaces for which the provider operates
	 */
	static public function getInterfaces(): array;


	/**
	 * Prepare the instance.
	 *
	 * @access public
	 * @var object $instance The unprepared instance of the object
	 * @param Application $app The application instance for which the provider operates
	 * @return object The prepared instance
	 */
	public function __invoke(object $instance, Application $app): object;
}
