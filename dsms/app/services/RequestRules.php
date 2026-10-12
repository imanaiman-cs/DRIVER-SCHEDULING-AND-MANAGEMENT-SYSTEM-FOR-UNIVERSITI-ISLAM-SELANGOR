<?php
/**
 * RequestRules - the pure (no database) business rules for a new request.
 * Kept free of SQL on purpose so they can be unit-tested and explained easily.
 *
 *  R1  Two-day rule: the trip must start at least MIN_DAYS_AHEAD days from today.
 *  R2  One request = one trip: one vehicle, one departure and one return.
 *  R3  (see AvailabilityService) one vehicle is held for each calendar day of the trip.
 */
final class RequestRules
{
    /** The paper form for urgent trips; named in the R1 message so the user knows what to do. */
    public const URGENT_FORM = 'BPTK/BPI/2025/PIN.5';

    /** Safety limit: a longer "trip" would hold a vehicle for weeks and is almost certainly a typing mistake. */
    public const MAX_TRIP_DAYS = 14;

    /** A leg (departure or return) occupies the driver for this long, not for the whole trip. */
    public const LEG_HOURS = 2;

    /** Build a DateTimeImmutable from "2026-05-01" + "08:30", or null if either is invalid. */
    public static function parse(string $date, string $time): ?DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($date) . ' ' . trim($time));
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return $dt->format('Y-m-d H:i') === trim($date) . ' ' . trim($time) ? $dt : null;
    }

    /**
     * R1. Counted in calendar days, not hours: with a 2-day rule a request made on Monday
     * at 4 pm is fine for Wednesday 8 am. Returns an error message, or null when OK.
     */
    public static function leadTimeProblem(DateTimeImmutable $departure, DateTimeImmutable $now, int $minDays): ?string
    {
        $earliest = $now->setTime(0, 0)->modify("+$minDays days");
        if ($departure->setTime(0, 0) < $earliest) {
            return "Requests must be made at least $minDays days before the trip (earliest departure date: "
                 . $earliest->format('d/m/Y') . '). For an urgent trip please submit the paper form '
                 . self::URGENT_FORM . ' to the Vehicle Section.';
        }
        return null;
    }

    /** R2 sanity checks on the two times. */
    public static function tripProblem(DateTimeImmutable $departure, DateTimeImmutable $return): ?string
    {
        if ($return <= $departure) {
            return 'The return time must be after the departure time.';
        }
        $days = count(self::days($departure, $return));
        if ($days > self::MAX_TRIP_DAYS) {
            return 'A single request can cover at most ' . self::MAX_TRIP_DAYS . ' days. Please split a longer programme into separate requests.';
        }
        return null;
    }

    /** Every calendar date of the trip, e.g. ["2026-05-01", "2026-05-02"]. These are the days the vehicle is held (R3). */
    public static function days(DateTimeImmutable $departure, DateTimeImmutable $return): array
    {
        $days = [];
        $day  = $departure->setTime(0, 0);
        $last = $return->setTime(0, 0);
        while ($day <= $last) {
            $days[] = $day->format('Y-m-d');
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    /**
     * R6 helper. A trip has two driver legs. The departure leg starts at departure and the
     * return leg ends at return, each LEG_HOURS long, so a driver can be free in between.
     * For a very short trip the legs are clipped so they never fall outside the trip.
     *
     * @return array{departure: array{0:DateTimeImmutable,1:DateTimeImmutable}, return: array{0:DateTimeImmutable,1:DateTimeImmutable}}
     */
    public static function legWindows(DateTimeImmutable $departure, DateTimeImmutable $return): array
    {
        $span = 'PT' . self::LEG_HOURS . 'H';
        $depEnd   = min($departure->add(new DateInterval($span)), $return);
        $retStart = max($return->sub(new DateInterval($span)), $departure);
        return ['departure' => [$departure, $depEnd], 'return' => [$retStart, $return]];
    }
}
