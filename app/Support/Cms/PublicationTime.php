<?php

namespace App\Support\Cms;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicationTime
{
    public function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $wall = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'UTC');
            if ($wall === null || $wall->format('Y-m-d\TH:i') !== $value) {
                throw new \RuntimeException('Invalid calendar date.');
            }
            $zone = new DateTimeZone(config('app.business_timezone'));
            $transitions = $zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
            $matches = [];
            foreach (array_unique(array_column($transitions ?: [], 'offset')) as $offset) {
                $instant = CarbonImmutable::createFromTimestampUTC($wall->getTimestamp() - $offset);
                if ($instant->setTimezone($zone)->format('Y-m-d\TH:i') === $value) {
                    $matches[$instant->getTimestamp()] = $instant;
                }
            }
            if (count($matches) !== 1) {
                throw new \RuntimeException('Ambiguous or nonexistent local time.');
            }

            return array_values($matches)[0];
        } catch (Throwable) {
            throw ValidationException::withMessages(['published_at' => 'Podaj jednoznaczną datę i godzinę czasu polskiego. Godzina przy zmianie czasu może nie istnieć lub występować dwa razy — wybierz inną.']);
        }
    }
}
