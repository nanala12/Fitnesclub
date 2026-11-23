<?php

use App\Support\MembershipPeriod;

it('calculates inclusive calendar periods without end of month anchoring', function (string $start, int $months, string $expiry) {
    $period = MembershipPeriod::startingOn($start, $months);

    expect($period->startsOn)->toBe($start)->and($period->expiresOn)->toBe($expiry);
})->with([
    'September requirement' => ['2027-09-28', 1, '2027-10-27'],
    'April requirement' => ['2027-04-30', 1, '2027-05-29'],
    'January common year' => ['2027-01-31', 1, '2027-02-28'],
    'January leap year' => ['2028-01-31', 1, '2028-02-29'],
    'February is not an end of month anchor' => ['2027-02-28', 1, '2027-03-27'],
    'leap February is not an end of month anchor' => ['2028-02-29', 1, '2028-03-28'],
    'ordinary day' => ['2027-09-15', 1, '2027-10-14'],
    'first day' => ['2027-01-01', 1, '2027-01-31'],
    'February target day exists' => ['2027-01-28', 1, '2027-02-27'],
    'February target day 29 missing' => ['2027-01-29', 1, '2027-02-28'],
    'February target day 30 missing' => ['2027-01-30', 1, '2027-02-28'],
    'leap February target day exists' => ['2028-01-29', 1, '2028-02-28'],
    'thirty day target' => ['2027-03-31', 1, '2027-04-30'],
    'equal length months' => ['2027-07-31', 1, '2027-08-30'],
    'year boundary' => ['2027-12-31', 1, '2028-01-30'],
    'two months use original day' => ['2027-01-31', 2, '2027-03-30'],
    'three months missing day' => ['2027-01-31', 3, '2027-04-30'],
    'annual leap day' => ['2028-02-29', 12, '2029-02-28'],
    'annual common date' => ['2027-02-28', 12, '2028-02-27'],
    'thirteen months' => ['2027-01-31', 13, '2028-02-29'],
    'four year leap cycle' => ['2028-02-29', 48, '2032-02-28'],
    'century not leap' => ['2100-01-31', 1, '2100-02-28'],
    'four hundred year leap' => ['2000-01-31', 1, '2000-02-29'],
    'maximum catalog duration' => ['2026-01-31', 65535, '7487-04-30'],
    'upper database year' => ['9999-11-30', 1, '9999-12-29'],
    'last supported expiry' => ['9999-12-01', 1, '9999-12-31'],
]);

it('rejects invalid or ambiguous start dates', function (string $date) {
    expect(fn () => MembershipPeriod::startingOn($date, 1))->toThrow(InvalidArgumentException::class);
})->with(['', '2027-02-29', '2028-02-30', '2027-04-31', '2027-13-01', '2027-00-01', '2027-01-00', '2027-1-01', '28.09.2027', '2027-01-01 00:00:00', '2027-01-01Z', '2027-01-01 ', '0999-12-31', '0000-01-01', '10000-01-01', "2027-01-01\0"]);

it('rejects unsupported durations and out of range results', function (string $date, int $months) {
    expect(fn () => MembershipPeriod::startingOn($date, $months))->toThrow(InvalidArgumentException::class);
})->with([
    ['2027-01-01', 0], ['2027-01-01', -1], ['2027-01-01', 65536],
    ['9999-12-02', 1], ['9999-01-02', 12], ['9999-01-01', PHP_INT_MAX],
]);

it('uses the Warsaw visit date for activation instants including DST boundaries', function (string $instant, string $start, string $expiry) {
    $source = new DateTimeImmutable($instant);
    $before = $source->format('c');
    $period = MembershipPeriod::fromActivation($source, 1);

    expect($period->startsOn)->toBe($start)->and($period->expiresOn)->toBe($expiry)
        ->and($source->format('c'))->toBe($before);
})->with([
    'winter UTC previous day' => ['2027-01-30T23:30:00+00:00', '2027-01-31', '2027-02-28'],
    'summer UTC previous day' => ['2027-07-30T22:30:00+00:00', '2027-07-31', '2027-08-30'],
    'spring before jump' => ['2027-03-28T00:30:00+00:00', '2027-03-28', '2027-04-27'],
    'spring after jump' => ['2027-03-28T01:30:00+00:00', '2027-03-28', '2027-04-27'],
    'autumn first repeated hour' => ['2027-10-31T00:30:00+00:00', '2027-10-31', '2027-11-30'],
    'autumn second repeated hour' => ['2027-10-31T01:30:00+00:00', '2027-10-31', '2027-11-30'],
    'UTC year boundary' => ['2026-12-31T23:30:00+00:00', '2027-01-01', '2027-01-31'],
    'explicit other zone' => ['2027-01-31T23:30:00-05:00', '2027-02-01', '2027-02-28'],
]);

it('does not modify mutable activation input', function () {
    $input = new DateTime('2027-07-30T22:30:00+00:00');
    $period = MembershipPeriod::fromActivation($input, 1);
    expect($input->format('c'))->toBe('2027-07-30T22:30:00+00:00')
        ->and($period->startsOn)->toBe('2027-07-31');
});

it('is independent of runtime timezone and current clock', function () {
    $original = date_default_timezone_get();
    try {
        date_default_timezone_set('Pacific/Honolulu');
        $period = MembershipPeriod::fromActivation(new DateTimeImmutable('2027-01-30T23:30:00Z'), 1);
        expect($period->startsOn)->toBe('2027-01-31')->and($period->expiresOn)->toBe('2027-02-28');
        expect(MembershipPeriod::startingOn('2027-03-28', 1)->expiresOn)->toBe('2027-04-27');
    } finally {
        date_default_timezone_set($original);
    }
});

it('allows consecutive periods to start the day after the inclusive expiry', function () {
    $period = MembershipPeriod::startingOn('2027-01-31', 1);
    foreach ([['2027-03-01', '2027-03-31'], ['2027-04-01', '2027-04-30'], ['2027-05-01', '2027-05-31']] as [$start, $end]) {
        $nextStart = (new DateTimeImmutable($period->expiresOn, new DateTimeZone('Europe/Warsaw')))->modify('+1 day')->format('Y-m-d');
        $period = MembershipPeriod::startingOn($nextStart, 1);
        expect($period->startsOn)->toBe($start)->and($period->expiresOn)->toBe($end);
    }
});
