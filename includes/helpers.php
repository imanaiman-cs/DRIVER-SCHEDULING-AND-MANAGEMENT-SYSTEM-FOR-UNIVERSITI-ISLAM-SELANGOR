<?php
// ============================================================
// includes/helpers.php  -  small shared functions used by every layer (no database access here)
// ============================================================

/** A value from config/settings.php. */
function setting(string $key)
{
    static $s = null;
    $s ??= require __DIR__ . '/../config/settings.php';
    return $s[$key] ?? null;
}

/** Strict date / time checks (2026-02-31 and 25:61 are not valid). */
function isValidDate(string $d): bool
{
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t !== false && $t->format('Y-m-d') === $d;
}

function isValidTime(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
}

/** 'Y-m-d' + 'H:i' -> 'Y-m-d H:i:00', or null when either part is not valid. */
function joinDateTime(string $date, string $time): ?string
{
    if (!isValidDate($date) || !isValidTime($time)) { return null; }
    return $date . ' ' . substr($time . ':00', 0, 8);
}

/** "Mon, 24 Nov 2026, 9:00 am" */
function fmtDT(?string $dt): string
{
    return $dt ? date('D, d M Y, g:i a', strtotime($dt)) : '-';
}

/** "24 Nov 2026" */
function fmtD(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '-';
}

/** "Mon, 24 Nov 2026, 9:00 am - 5:00 pm" (the date is repeated only when the day changes). */
function fmtPeriod(string $start, string $end): string
{
    $s = strtotime($start); $e = strtotime($end);
    return date('D, d M Y, g:i a', $s) . ' - ' . (date('Y-m-d', $s) === date('Y-m-d', $e) ? date('g:i a', $e) : date('D, d M Y, g:i a', $e));
}

function isWeekend(string $dt): bool
{
    return (int)date('N', strtotime($dt)) >= 6;
}

/** Monday of the week of $date (Y-m-d). */
function weekMonday(string $date): string
{
    $t = strtotime($date);
    return date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
}

function money(float $v): string
{
    return 'RM ' . number_format($v, 2);
}

function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** The licence class a vehicle type needs: D for cars, MPVs and vans, GDL for lorries, PSV for buses. */
function licenceForVehicleType(string $type): string
{
    return match ($type) { 'Lorry' => 'GDL', 'Bus' => 'PSV', default => 'D' };
}

/** The answer of a domain method: ['ok' => bool, 'message' => string] + extra. */
function wfOk(string $message, array $extra = []): array { return ['ok' => true, 'message' => $message] + $extra; }
function wfFail(string $message, array $extra = []): array { return ['ok' => false, 'message' => $message] + $extra; }

/** Writes a line to logs/<name>.log (e-mail and WhatsApp failures; the SDD keeps no log table). */
function appLog(string $name, string $line): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/' . preg_replace('/[^a-z_]/', '', $name) . '.log', date('Y-m-d H:i:s') . ' ' . str_replace(["\r", "\n"], ' ', $line) . "\n", FILE_APPEND | LOCK_EX);
}
