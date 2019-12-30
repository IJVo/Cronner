<?php

declare(strict_types=1);

namespace stekycz\Cronner\DI;

use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Json;
use Nette\Utils\Validators;
use stekycz\CriticalSection\CriticalSection;
use stekycz\CriticalSection\Driver\FileDriver;
use stekycz\CriticalSection\Driver\IDriver;
use stekycz\Cronner\Bar\Tasks;
use stekycz\Cronner\Cronner;
use stekycz\Cronner\ITimestampStorage;
use stekycz\Cronner\TimestampStorage\FileStorage;

class CronnerExtension extends CompilerExtension
{

	public const TASKS_TAG = 'cronner.tasks';
	public const DEFAULT_STORAGE_CLASS = FileStorage::class;
	public const DEFAULT_STORAGE_DIRECTORY = '%tempDir%/cronner';


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
								'timestampStorage' => Expect::mixed($default = null),
								'maxExecutionTime' => Expect::int(),
								'criticalSectionTempDir' => Expect::string('%tempDir%/critical-section'),
								'criticalSectionDriver' => Expect::mixed($default = null),
								'tasks' => Expect::array(),
								'bar' => Expect::string('%debugMode%'),
		]);
	}


	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();

		$config = (array) $this->config;

		Validators::assert($config['timestampStorage'], 'string|object|null', 'Timestamp storage definition');
		Validators::assert($config['maxExecutionTime'], 'integer|null', 'Script max execution time');
		Validators::assert($config['criticalSectionTempDir'], 'string|null', 'Critical section files directory path (for critical section files driver only)');
		Validators::assert($config['criticalSectionDriver'], 'string|object|null', 'Critical section driver definition');

		$storage = $this->createServiceByConfig(
						$container,
						$this->prefix('timestampStorage'),
						$config['timestampStorage'],
						ITimestampStorage::class,
						self::DEFAULT_STORAGE_CLASS,
						[
								self::DEFAULT_STORAGE_DIRECTORY,
						]
		);

		$criticalSectionDriver = $this->createServiceByConfig(
						$container,
						$this->prefix('criticalSectionDriver'),
						$config['criticalSectionDriver'],
						IDriver::class,
						FileDriver::class,
						[
								$config['criticalSectionTempDir'],
						]
		);

		$criticalSection = $container->addDefinition($this->prefix('criticalSection'))
						->setFactory(CriticalSection::class, [
								$criticalSectionDriver,
						])
						->setAutowired(false);

		$runner = $container->addDefinition($this->prefix('runner'))
						->setFactory(Cronner::class, [
				$storage,
				$criticalSection,
				$config['maxExecutionTime'],
				array_key_exists('debugMode', $config) ? !$config['debugMode'] : true,
		]);

		if (isset($config['tasks'])) {
			Validators::assert($config['tasks'], 'array');
			foreach ($config['tasks'] as $task) {
				$def = $container->addDefinition($this->prefix('task.' . md5(is_string($task) ? $task : sprintf('%s-%s', $task->getEntity(), Json::encode($task)))));
				[$def->factory] = Helpers::filterArguments([
										is_string($task) ? new Statement($task) : $task,
				]);

				if (class_exists($def->factory->entity)) {
					$def->setFactory($def->factory->entity);
				}

				$def->setAutowired(false);
				$def->addTag(self::TASKS_TAG);
			}
		}

		if (isset($config['bar']) && class_exists('Tracy\Bar')) {
			$container->addDefinition($this->prefix('bar'))
							->setFactory(Tasks::class, [
									$this->prefix('@runner'),
									$this->prefix('@timestampStorage'),
			]);
		}
	}


	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		/** @var \Nette\DI\Definitions\ServiceDefinition */
		$runner = $builder->getDefinition($this->prefix('runner'));
		foreach (array_keys($builder->findByTag(self::TASKS_TAG)) as $serviceName) {
			$runner->addSetup('addTasks', ['@' . $serviceName]);
		}
	}


	public function afterCompile(ClassType $class)
	{
		$builder = $this->getContainerBuilder();
		$init = $class->getMethod('initialize');

		if ($builder->hasDefinition($this->prefix('bar'))) {
			$init->addBody('$this->getByType(?)->addPanel($this->getService(?));', [
					'Tracy\Bar',
					$this->prefix('bar'),
			]);
		}
	}


	public static function register(Configurator $configurator)
	{
		$configurator->onCompile[] = function (Configurator $config, Compiler $compiler) {
			$compiler->addExtension('cronner', new self());
		};
	}


	private function createServiceByConfig(
					ContainerBuilder $container,
					string $serviceName,
					$config,
					string $fallbackType,
					string $fallbackClass,
					array $fallbackArguments
	): ServiceDefinition
	{
		if (is_string($config) && $container->findByType($config)) {
			$definition = $container->addFactoryDefinition($serviceName)
							->getResultDefinition()
							->setFactory($config);
		} elseif ($config instanceof Statement) {
			$definition = @$container->addDefinition($serviceName)
											->setFactory($config->entity, $config->arguments);
		} else {
			$foundServiceName = @$container->getByType($fallbackType);
			if ($foundServiceName) {
				$definition = $container->addDefinition($serviceName)
								->setFactory('@' . $foundServiceName);
			} else {
				$definition = @$container->addDefinition($serviceName)
												->setFactory($fallbackClass, Helpers::expand($fallbackArguments, $container->parameters));
			}
		}

		return $definition->setAutowired(false);
	}
}
