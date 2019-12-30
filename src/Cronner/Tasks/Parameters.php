<?php

declare(strict_types=1);

namespace stekycz\Cronner\Tasks;

use DateTimeInterface;
use Nette;
use Nette\Application\UI\MethodReflection;
use Nette\Utils\Strings;
use stekycz\Cronner\Exceptions\InvalidArgumentException;

final class Parameters
{

	use \Nette\SmartObject;

	public const TASK = 'cronner-task';
	public const PERIOD = 'cronner-period';
	public const DAYS = 'cronner-days';
	public const TIME = 'cronner-time';

	/** @var array */
	private $values;


	/**
	 * @param array $values
	 */
	public function __construct(array $values)
	{
		$values[static::TASK] = isset($values[static::TASK]) && is_string($values[static::TASK]) ? Strings::trim($values[static::TASK]) : '';
		$this->values = $values;
	}


	public function getName(): string
	{
		return $this->values[static::TASK];
	}


	public function isTask(): bool
	{
		return Strings::length($this->values[static::TASK]) > 0;
	}


	/**
	 * Returns true if today is allowed day of week.
	 */
	public function isInDay(DateTimeInterface $now): bool
	{
		if (($days = $this->values[static::DAYS]) !== null) {
			return in_array($now->format('D'), $days, true);
		}

		return true;
	}


	/**
	 * Returns true if current time is in allowed range.
	 */
	public function isInTime(DateTimeInterface $now): bool
	{
		if ($times = $this->values[static::TIME]) {
			foreach ($times as $time) {
				if ($time['to'] && $time['to'] >= $now->format('H:i') && $time['from'] <= $now->format('H:i')) {
					// Is in range with precision to minutes
					return true;
				} elseif ($time['from'] == $now->format('H:i')) {
					// Is in specific minute
					return true;
				}
			}

			return false;
		}

		return true;
	}


	/**
	 * Returns true if current time is next period of invocation.
	 */
	public function isNextPeriod(DateTimeInterface $now, DateTimeInterface $lastRunTime = null): bool
	{
		if (
						$lastRunTime !== null && !$lastRunTime instanceof \DateTimeImmutable && !$lastRunTime instanceof \DateTime
		) {
			throw new InvalidArgumentException;
		}

		if (isset($this->values[static::PERIOD]) && $this->values[static::PERIOD]) {
			// Prevent run task on next cronner run because of a few seconds shift
			$now = Nette\Utils\DateTime::from($now)->modifyClone('+5 seconds');

			return $lastRunTime === null || $lastRunTime->modify('+ ' . $this->values[static::PERIOD]) <= $now;
		}

		return true;
	}


	/**
	 * Parse cronner values from annotations.
	 */
	public static function parseParameters(MethodReflection $method): array
	{
		$taskName = null;
		if ($method->hasAnnotation(self::TASK)) {
			$className = $method->getDeclaringClass()->getName();
			$methodName = $method->getName();
			$taskName = $className . ' - ' . $methodName;
		}

		$taskAnnotation = $method->getAnnotation(self::TASK);

		$parameters = [
				static::TASK => is_string($taskAnnotation) ? Parser::parseName($taskAnnotation) : $taskName,
				static::PERIOD => $method->hasAnnotation(self::PERIOD) ? Parser::parsePeriod((string) $method->getAnnotation(self::PERIOD)) : null,
				static::DAYS => $method->hasAnnotation(self::DAYS)
				? Parser::parseDays(self::getAnnotationA(self::DAYS, $method)) : null,
				static::TIME => $method->hasAnnotation(self::TIME)
				? Parser::parseTimes(self::getAnnotationA(self::TIME, $method)) : null,
		];

		return $parameters;
	}


	/**
	 * Returns an annotation value.
	 * @return string
	 */
	public static function getAnnotationA(string $name, MethodReflection $method)
	{
		$res = Nette\Application\UI\ComponentReflection::parseAnnotation($method, $name);
		return implode(', ', $res);
	}
}
