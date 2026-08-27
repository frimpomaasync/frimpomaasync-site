<?php
declare(strict_types=1);

namespace SoftAppeals\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time, in one place.
 *
 * Every stored timestamp is UTC in 'Y-m-d H:i:s'. Every displayed timestamp is
 * her business timezone. Mixing those two is how a deadline clock ends up a day
 * out, and a deadline clock that is a day out is worse than no clock at all
 * when the thing it counts down to is a statutory appeal window.
 *
 * The class is instantiable and the current time is injectable so a test can
 * pin "now" and assert on an expiry boundary without sleeping.
 */
final class Clock
{
    private ?DateTimeImmutable $frozen;
    private DateTimeZone $businessZone;

    public function __construct(string $businessTimezone = 'America/New_York', ?DateTimeImmutable $frozen = null)
    {
        $this->businessZone = new DateTimeZone($businessTimezone);
        $this->frozen = $frozen;
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** The storage format. Always UTC. */
    public function nowUtc(): string
    {
        return $this->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function utc(DateTimeImmutable $when): string
    {
        return $when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** A UTC instant $seconds from now, in storage format. */
    public function utcPlusSeconds(int $seconds): string
    {
        return $this->utc($this->now()->modify(sprintf('%+d seconds', $seconds)));
    }

    public function parseUtc(string $stored): ?DateTimeImmutable
    {
        $when = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $stored,
            new DateTimeZone('UTC')
        );
        return $when === false ? null : $when;
    }

    public function hasPassed(string $storedUtc): bool
    {
        $when = $this->parseUtc($storedUtc);
        return $when !== null && $when <= $this->now();
    }

    /**
     * Whole days from now until a stored UTC instant. Negative once it is past.
     * This is what the deadline pills count.
     */
    public function daysUntil(string $storedUtc): ?int
    {
        $when = $this->parseUtc($storedUtc);
        if ($when === null) {
            return null;
        }
        return (int) $this->now()->setTime(0, 0)->diff($when->setTime(0, 0))->format('%r%a');
    }

    /** "27 August 2026", in her timezone. For anything a person reads. */
    public function displayDate(string $storedUtc): string
    {
        $when = $this->parseUtc($storedUtc);
        return $when === null ? '' : $when->setTimezone($this->businessZone)->format('j F Y');
    }

    /** "2:41pm on 27 August", in her timezone. Matches the existing confirmation emails. */
    public function displayDateTime(string $storedUtc): string
    {
        $when = $this->parseUtc($storedUtc);
        if ($when === null) {
            return '';
        }
        $local = $when->setTimezone($this->businessZone);
        return strtolower($local->format('g:ia')) . ' on ' . $local->format('j F Y');
    }

    /** "Wednesday, August 5, 2026 at 4:27 PM". The signing timestamp wording. */
    public function displaySigningStamp(string $storedUtc): string
    {
        $when = $this->parseUtc($storedUtc);
        return $when === null ? '' : $when->setTimezone($this->businessZone)->format('l, F j, Y \a\t g:i A');
    }
}
