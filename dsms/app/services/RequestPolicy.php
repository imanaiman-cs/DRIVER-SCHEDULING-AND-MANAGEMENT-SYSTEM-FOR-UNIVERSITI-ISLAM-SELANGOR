<?php
/**
 * RequestPolicy - "who may do what to this request?" in one place.
 * Controllers ask here (role check on EVERY action), and views use the same answers to
 * decide which buttons to show, so a hidden button and a refused URL always agree.
 *
 * $user    : the session user (id, role, ptj_id)
 * $request : a row from TripRequest::find() (with requester_ptj_id)
 */
final class RequestPolicy
{
    public static function isAdmin(array $user): bool
    {
        return in_array($user['role'], ['admin_assistant', 'head_admin'], true);
    }

    /** Requester, their approver, an HOD of the same PTJ, any admin, and the drivers assigned to it. */
    public static function canView(array $user, array $request, array $driverUserIds = []): bool
    {
        $uid = (int)$user['id'];
        if (self::isAdmin($user) || (int)$request['requester_id'] === $uid || (int)($request['approver_id'] ?? 0) === $uid) {
            return true;
        }
        if ($user['role'] === 'hod' && $user['ptj_id'] !== null && (int)$user['ptj_id'] === (int)($request['requester_ptj_id'] ?? 0)) {
            return true;
        }
        return in_array($uid, $driverUserIds, true)
            && in_array($request['status'], [RequestStatus::ASSIGNED, RequestStatus::CONFIRMED, RequestStatus::COMPLETED], true);
    }

    /**
     * Cancel: the requester (own), the approver or an HOD of the same PTJ, or any admin;
     * only while the request is still active and before the trip has started.
     */
    public static function canCancel(array $user, array $request, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        if (!RequestStatus::isActive($request['status'])) {
            return false;
        }
        if (new DateTimeImmutable($request['departure_datetime']) <= $now) {
            return false;
        }
        $uid = (int)$user['id'];
        if (self::isAdmin($user) || (int)$request['requester_id'] === $uid) {
            return true;
        }
        if ($user['role'] === 'hod') {
            return (int)($request['approver_id'] ?? 0) === $uid
                || ($user['ptj_id'] !== null && (int)$user['ptj_id'] === (int)($request['requester_ptj_id'] ?? 0));
        }
        return false;
    }

    /** Who decides on a Pending request: its approver (an HOD), or a head admin when the requester has no HOD above them. */
    public static function canApprove(array $user, array $request): bool
    {
        if ($request['status'] !== RequestStatus::PENDING) {
            return false;
        }
        $uid = (int)$user['id'];
        if ($user['role'] === 'hod') {
            return (int)($request['approver_id'] ?? 0) === $uid;
        }
        return $user['role'] === 'head_admin' && $request['approver_id'] === null;
    }
}
