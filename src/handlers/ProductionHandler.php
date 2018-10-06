<?php

namespace Hiraeth;

use SlashTrace\Context\User;
use SlashTrace\EventHandler\EventHandler;

/**
 *
 */
class ProductionHandler implements EventHandler
{
	/**
	 *
	 */
	protected $app = NULL;


	/**
	 *
	 */
	protected $path = NULL;


	/**
	 *
	 */
	protected $release = NULL;


	/**
	 *
	 */
	protected $user = NULL;


	/**
	 *
	 */
	public function __construct(Application $app)
	{
		$this->app = $app;
	}


	/**
	 *
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

		if ($this->app->isCLI()) {
			exit($exception->getCode() ?: 1);

		} else {
			header('HTTP/1.1 500 Internal Server Error');
			echo 'Request cannot be completed at this time, please try again later.';
			exit(500);
		}
	}


	/**
	 *
	 */
	public function recordBreadcrumb($title, array $data = [])
	{
		$this->app->debug($title, $data);
	}


	/**
	 *
	 */
	public function setApplicationPath($path)
	{
		$this->path = $path;
	}


	/**
	 *
	 */
	public function setRelease($release)
	{
		$this->release = $release;
	}


	/**
	 *
	 */
	public function setUser(User $user)
	{
		$this->user = [
			'id'    => $user->getId(),
			'name'  => $user->getname(),
			'email' => $user->getEmail()
		];
	}
}
