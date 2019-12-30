<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

define('TEST_DIR', __DIR__);
define('TEMP_DIR', TEST_DIR . '/../_temp/' . (isset($_SERVER['argv']) ? md5(serialize($_SERVER['argv'])) : getmypid()));
define('TMP_DIR', TEST_DIR . '/../_temp');
Tester\Environment::setup();
date_default_timezone_set('Europe/Prague');


function run(Tester\TestCase $testCase)
{
	$testCase->runTest($_SERVER['argv'][1] ?? null);
}

abstract class TestCase extends Tester\TestCase
{


	protected function tearDown()
	{
		Mockery::close();
	}
}
