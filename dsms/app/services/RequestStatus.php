<?php
/**
 * RequestStatus - the ONE place that says which status changes are allowed.
 *
 *   Pending Approval --> Approved | Rejected | Lapsed
 *   Approved         --> Driver Assigned | Confirmed (self-drive, no driver needed)
 *   Driver Assigned  --> Confirmed
 *   Confirmed        --> Completed
 *   any active state --> Cancelled (a reason is required)
 *
 * Every controller and job asks this class before it changes a status, so a
 * rule cannot be forgotten in one screen and remembered in another.
 */
final class RequestStatus
{
    public const PENDING   = 'Pending Approval';
    public const APPROVED  = 'Approved';
    public const REJECTED  = 'Rejected';
    public const LAPSED    = 'Lapsed';
    public const ASSIGNED  = 'Driver Assigned';
    public const CONFIRMED = 'Confirmed';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED, self::LAPSED,
                        self::ASSIGNED, self::CONFIRMED, self::COMPLETED, self::CANCELLED];

    /** Statuses in which the request still needs its vehicle (so it keeps the day holds, R3). */
    public const ACTIVE = [self::PENDING, self::APPROVED, self::ASSIGNED, self::CONFIRMED];

    private const TRANSITIONS = [
        self::PENDING   => [self::APPROVED, self::REJECTED, self::LAPSED, self::CANCELLED],
        self::APPROVED  => [self::ASSIGNED, self::CONFIRMED, self::CANCELLED],
        self::ASSIGNED  => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::COMPLETED, self::CANCELLED],
        self::REJECTED  => [],
        self::LAPSED    => [],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** Which timestamp column records the moment of entering a status. */
    public const TIMESTAMP_COLUMN = [
        self::APPROVED  => 'approved_at',
        self::ASSIGNED  => 'assigned_at',
        self::CONFIRMED => 'confirmed_at',
        self::COMPLETED => 'completed_at',
        self::CANCELLED => 'cancelled_at',
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertMove(string $from, string $to): void
    {
        if (!self::canMove($from, $to)) {
            throw new DomainException("A request that is \"$from\" cannot become \"$to\".");
        }
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    /** Leaving the active states gives the vehicle back (the day holds are deleted). */
    public static function releasesVehicle(string $to): bool
    {
        return !self::isActive($to);
    }
}
