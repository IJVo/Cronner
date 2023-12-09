<?php

declare(strict_types=1);

namespace stekycz\Cronner\tests\objects;

class AnotherSimpleTestObjectWithDependency
{

	/** @var FooService */
	private $service;


	public function __construct(FooService $service)
	{
		$this->service = $service;
	}


	/**
	 * @cronner-task
	 */
	public function run()
	{

	}
}
