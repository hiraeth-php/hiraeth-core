<?php

namespace Hiraeth;

use SlashTrace\EventHandler\DebugHandler;

/**
 * The debugging error/exception handler for slashtrace
 *
 * This class should be, by and large, simply an extension of the built in slashtrace debugger.
 * It provides a placeholder for overloading some functionality if need be, but should mostly
 * just extend it.
 */
class DebuggingHandler extends DebugHandler
{
	/**
	 * The application instance
	 *
	 * @var Application|null
	 */
	protected $app = NULL;


	/**
	 *
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 * Exception handler
	 *
	 * @access public
	 * @var Exception $exception The exception to be handled
	 * @return int
	 */
	public function handleException($exception)
	{
		if ($this->app->isDebugging()) {
			return parent::handleException($exception);
		}
	}
}
