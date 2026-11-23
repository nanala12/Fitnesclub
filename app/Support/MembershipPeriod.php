<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** A date-only inclusive period for time plans; constructing it never activates a membership. */
final readonly class MembershipPeriod
{
    private function __construct(public string $startsOn, public string $expiresOn) {}

    public static function startingOn(string $startsOn, int $durationMonths): self
    {
        $timezone = new DateTimeZone('Europe/Warsaw');
        $start = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $startsOn) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn, $timezone)
            : false;
        if ($start === false || $start->format('Y-m-d') !== $startsOn || (int) $start->format('Y') < 1000) {
            throw new InvalidArgumentException('Start must be a valid MySQL date in YYYY-MM-DD format.');
        }
        if ($durationMonths < 1 || $durationMonths > 65535) {
            throw new InvalidArgumentException('Duration must be between 1 and 65535 months.');
        }

        // Move from the first day to avoid overflow before checking the original day.
        $target = $start->modify('first day of this month')->modify("+{$durationMonths} months");
        $day = (int) $start->format('j');
        $expiry = $day <= (int) $target->format('t')
            ? $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)->modify('-1 day')
            : $target->modify('last day of this month');
        if ((int) $expiry->format('Y') > 9999) {
            throw new InvalidArgumentException('The resulting period exceeds the MySQL date range.');
        }

        return new self($start->format('Y-m-d'), $expiry->format('Y-m-d'));
    }

    public static function fromActivation(DateTimeInterface $activatedAt, int $durationMonths): self
    {
        $localDate = DateTimeImmutable::createFromInterface($activatedAt)
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');

        return self::startingOn($localDate, $durationMonths);
    }
}
