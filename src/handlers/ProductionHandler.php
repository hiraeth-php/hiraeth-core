<?php

namespace Hiraeth;

use SlashTrace\Context\User;
use SlashTrace\EventHandler\EventHandler;

/**
 * The production error/exception handler for slashtrace
 *
 * This handler is a last line of defense for outputting a meaningful error to a client
 * or the console.  Hiraeth will use this handler when it is not in debugging mode and will
 * prevent debug information from being exposed.  Additionally, it will provide base level
 * logging for exceptions and breadcrumbs.
 */
class ProductionHandler implements EventHandler
{
	/**
	 * The application instance
	 *
	 * @var Hiraeth\Application
	 */
	protected $app = NULL;


	/**
	 * The path of the application, included in error logging
	 *
	 * @var string
	 */
	protected $path = NULL;


	/**
	 * The release information of the application, included in error logging
	 *
	 * @var array
	 */
	protected $release = NULL;


	/**
	 * The information of the user of the application, included in error logging
	 *
	 * @var array
	 */
	protected $user = NULL;


	/**
	 * Instantiate a Production Handler
	 *
	 * @access public
	 * @var Hiraeth\Application $app The application instance for proxying log calls
	 * @return void
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
	 * @return void
	 */
	public function handleException($exception)
	{
		$this->app->error($exception->getMessage(), [
			'file'    => $exception->getFile(),
			'line'    => $exception->getLine(),
			'release' => $this->release,
			'path'    => $this->path,
			'user'    => $this->user
		]);

		if (!$this->app->isDebugging()) {
			if ($this->app->isCLI()) {
				exit($exception->getCode() ?: 1);

			} else {
				header('HTTP/1.1 500 Internal Server Error');
				echo 'Request cannot be completed at this time, please try again later.';
				exit(500);
			}
		}
	}


	/**
	 * Record a breadcrumb
	 *
	 * @access public
	 * @var string $title The title for the breadcrumb
	 * @var array $data Additional contextual data that is relevant
	 * @return void
	 */
	public function recordBreadcrumb($title, array $data = [])
	{
		$this->app->debug($title, $data);
	}


	/**
	 * Set the application path
	 *
	 * @access public
	 * @var string $path The path to the application
	 * @return void
	 */
	public function setApplicationPath($path)
	{
		$this->path = $path;
	}


	/**
	 * Set the application release
	 *
	 * @access public
	 * @var string $release The release of the application
	 * @return void
	 */
	public function setRelease($release)
	{
		$this->release = $release;
	}


	/**
	 * Set the application information for the user
	 *
	 * @access public
	 * @var User $user The slashtrace user context
	 * @return void
	 */
	public function setUser(User $user)
	{
		$this->user = [
			'id'    => $user->getId(),
			'name'  => $user->getName(),
			'email' => $user->getEmail()
		];
	}
}
