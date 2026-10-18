<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use Carbon\CarbonInterval;
use DateInterval;
use InvalidArgumentException;
use Throwable;

/**
 * Durations of approval steps: '2 days', '36 hours', 'P1DT12H' or a DateInterval.
 */
final class Duration
{
    public static function parse(mixed $value): ?CarbonInterval
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateInterval) {
            return CarbonInterval::instance($value);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('A workflow duration must be a string or a DateInterval.');
        }

        try {
            $interval = str_starts_with(strtoupper($value), 'P') ? new CarbonInterval(strtoupper($value)) : CarbonInterval::fromString($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("The workflow duration [{$value}] could not be parsed.", 0, $exception);
        }

        if ($interval->totalSeconds <= 0) {
            throw new InvalidArgumentException("The workflow duration [{$value}] must be longer than zero.");
        }

        return $interval;
    }
}
