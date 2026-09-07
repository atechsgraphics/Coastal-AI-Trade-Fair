<?php
declare(strict_types=1);

/**
 * EFT booking platform — services, availability and slots.
 * ---------------------------------------------------------------------------
 * Everything to do with what can be booked and when. The one rule the whole
 * file exists to enforce: a slot is only offered while it still has room, and
 * the final capacity check happens inside the same write transaction that
 * creates the booking, so two people clicking at once cannot both get in.
 */

/* ============================================================= 1. SERVICES */

/** Every service the public may book, in display order. */
function bk_services(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM services';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    return db_all($sql . ' ORDER BY position, id');
}

function bk_service(int $id): ?array
{
    return db_one('SELECT * FROM services WHERE id = :id', [':id' => $id]);
}

function bk_service_by_slug(string $slug): ?array
{
    return db_one('SELECT * FROM services WHERE slug = :s', [':s' => $slug]);
}

/** A URL-safe, unique slug for a service name. */
function bk_service_slug(string $name, int $ignoreId = 0): string
{
    $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    if ($base === '') {
        $base = 'service';
    }
    $base = mb_substr($base, 0, 60);

    $slug = $base;
    $n = 2;
    while (db_one('SELECT id FROM services WHERE slug = :s AND id != :id', [':s' => $slug, ':id' => $ignoreId])) {
        $slug = $base . '-' . $n;
        $n++;
    }
    return $slug;
}

/** Guests are only asked for when a service allows more than one. */
function bk_service_takes_guests(array $service): bool
{
    return (int) $service['max_guests'] > 1;
}

/** What one booking of this service costs. */
function bk_service_price(array $service, int $guests): float
{
    $price = (float) $service['price'];
    if (($service['price_mode'] ?? 'booking') === 'person') {
        return round($price * max(1, $guests), 2);
    }
    return round($price, 2);
}

/** How much of a slot's capacity one booking uses up. */
function bk_service_seats(array $service, int $guests): int
{
    if (($service['capacity_unit'] ?? 'booking') === 'guest') {
        return max(1, $guests);
    }
    return 1;
}

/** How long the service occupies the diary, including its buffer. */
function bk_service_block_minutes(array $service): int
{
    return max(5, (int) $service['duration_minutes']) + max(0, (int) $service['buffer_minutes']);
}

/* ========================================================= 2. WEEKLY HOURS */

function bk_weekdays(): array
{
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
}

/**
 * Opening hours that apply to a service: its own rules, or the site-wide
 * rules (service_id = 0) when the service has none of its own.
 */
function bk_service_hours(int $serviceId, ?int $weekday = null): array
{
    // A service with any rule of its own does not inherit the site-wide ones.
    $useGlobal = $serviceId > 0
        && !db_one('SELECT id FROM service_hours WHERE service_id = :s AND is_active = 1', [':s' => $serviceId]);

    $params = [':s' => $useGlobal ? 0 : $serviceId];
    $sql = 'SELECT * FROM service_hours WHERE is_active = 1 AND service_id = :s';
    if ($weekday !== null) {
        $sql .= ' AND weekday = :w';
        $params[':w'] = $weekday;
    }

    return db_all($sql . ' ORDER BY weekday, start_time, id', $params);
}

/* ======================================================== 3. BLOCKED DATES */

/**
 * Blocked ranges that cover a date, either for this service or for everything.
 * A block with times covers only part of the day; without them, the whole day.
 */
function bk_blocked_for(int $serviceId, string $date): array
{
    return db_all(
        "SELECT * FROM blocked_dates
         WHERE (service_id = 0 OR service_id = :s)
           AND start_date <= :d
           AND (end_date = '' OR end_date >= :d2)
         ORDER BY start_date, id",
        [':s' => $serviceId, ':d' => $date, ':d2' => $date]
    );
}

/** True when the whole day is closed. */
function bk_date_fully_blocked(int $serviceId, string $date): bool
{
    foreach (bk_blocked_for($serviceId, $date) as $block) {
        if (trim((string) $block['start_time']) === '' && trim((string) $block['end_time']) === '') {
            return true;
        }
    }
    return false;
}

/** The reason a day is closed, for the message shown to the client. */
function bk_block_reason(int $serviceId, string $date): string
{
    foreach (bk_blocked_for($serviceId, $date) as $block) {
        if (trim((string) $block['start_time']) === '' && trim((string) $block['end_time']) === '') {
            return trim((string) $block['reason']);
        }
    }
    return '';
}

/** True when a specific slot falls inside a part-day block. */
function bk_slot_blocked(int $serviceId, string $date, string $start, string $end): bool
{
    foreach (bk_blocked_for($serviceId, $date) as $block) {
        $blockStart = trim((string) $block['start_time']);
        $blockEnd   = trim((string) $block['end_time']);
        if ($blockStart === '' && $blockEnd === '') {
            return true;
        }
        $blockStart = $blockStart !== '' ? $blockStart : '00:00';
        $blockEnd   = $blockEnd !== '' ? $blockEnd : '23:59';
        if ($start < $blockEnd && $end > $blockStart) {
            return true;
        }
    }
    return false;
}

/* ============================================================ 4. THE DIARY */

/** Minutes since midnight for "HH:MM". */
function bk_minutes(string $time): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
        return 0;
    }
    return ((int) $m[1] * 60) + (int) $m[2];
}

/** "HH:MM" from minutes since midnight. */
function bk_clock(int $minutes): string
{
    $minutes = max(0, min(24 * 60, $minutes));
    return str_pad((string) intdiv($minutes, 60), 2, '0', STR_PAD_LEFT) . ':'
        . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
}

/** Now, in the site's configured time zone. */
function bk_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(setting('event_timezone', 'Africa/Windhoek')));
}

/**
 * Seats already taken for one service on one date, keyed by start time.
 * Cancelled, declined and refunded bookings release their seats.
 *
 * @return array<string, int>
 */
function bk_taken_seats(int $serviceId, string $date, int $ignoreBookingId = 0): array
{
    $statuses = bk_active_statuses();
    $in = implode(',', array_fill(0, count($statuses), '?'));

    $params = array_merge([$serviceId, $date], $statuses);
    $sql = "SELECT start_time, SUM(seats) AS seats
            FROM service_bookings
            WHERE service_id = ? AND booking_date = ? AND status IN ({$in})";
    if ($ignoreBookingId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $ignoreBookingId;
    }
    $sql .= ' GROUP BY start_time';

    $taken = [];
    foreach (db_all($sql, $params) as $row) {
        $taken[(string) $row['start_time']] = (int) $row['seats'];
    }
    return $taken;
}

/**
 * Every slot for one service on one date.
 *
 * Each entry is:
 *   start, end, capacity, taken, free, available (bool), reason (why not)
 *
 * @return array<int, array<string, mixed>>
 */
function bk_slots(array $service, string $date, int $ignoreBookingId = 0): array
{
    $serviceId = (int) $service['id'];

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return [];
    }
    $date = date('Y-m-d', $timestamp);
    $weekday = (int) date('w', $timestamp);

    if (bk_date_fully_blocked($serviceId, $date)) {
        return [];
    }

    $rules = bk_service_hours($serviceId, $weekday);
    if (!$rules) {
        return [];
    }

    $block = bk_service_block_minutes($service);
    $duration = max(5, (int) $service['duration_minutes']);
    $taken = bk_taken_seats($serviceId, $date, $ignoreBookingId);

    $now = bk_now();
    $leadUntil = $now->modify('+' . max(0, (int) $service['lead_time_hours']) . ' hours');

    $slots = [];
    $seen = [];

    foreach ($rules as $rule) {
        $from = bk_minutes((string) $rule['start_time']);
        $to   = bk_minutes((string) $rule['end_time']);
        if ($to <= $from) {
            continue;
        }

        $step = (int) $rule['slot_interval'] > 0 ? (int) $rule['slot_interval'] : $block;
        $step = max(5, $step);
        $capacity = (int) $rule['capacity'] > 0 ? (int) $rule['capacity'] : max(1, (int) $service['slot_capacity']);

        for ($start = $from; $start + $duration <= $to; $start += $step) {
            $startClock = bk_clock($start);
            $endClock   = bk_clock($start + $duration);

            if (isset($seen[$startClock])) {
                continue;
            }
            $seen[$startClock] = true;

            $used = $taken[$startClock] ?? 0;
            $free = max(0, $capacity - $used);

            $reason = '';
            if (bk_slot_blocked($serviceId, $date, $startClock, $endClock)) {
                $reason = 'Unavailable';
            } elseif ($free <= 0) {
                $reason = 'Fully booked';
            } else {
                $slotStart = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $date . ' ' . $startClock,
                    new DateTimeZone(setting('event_timezone', 'Africa/Windhoek'))
                );
                if ($slotStart && $slotStart < $leadUntil) {
                    $reason = 'Too soon';
                }
            }

            $slots[] = [
                'start'     => $startClock,
                'end'       => $endClock,
                'capacity'  => $capacity,
                'taken'     => $used,
                'free'      => $free,
                'available' => $reason === '',
                'reason'    => $reason,
            ];
        }
    }

    usort($slots, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

    return $slots;
}

/** Only the slots a client may actually pick. */
function bk_open_slots(array $service, string $date, int $ignoreBookingId = 0): array
{
    return array_values(array_filter(
        bk_slots($service, $date, $ignoreBookingId),
        static fn (array $slot): bool => $slot['available']
    ));
}

/**
 * The next dates that have at least one open slot, starting from today.
 *
 * @return array<int, array{date:string, open:int}>
 */
function bk_open_dates(array $service, int $limit = 60, int $ignoreBookingId = 0): array
{
    $now = bk_now();
    $maxAdvance = max(1, (int) $service['max_advance_days']);
    $found = [];

    for ($day = 0; $day <= $maxAdvance && count($found) < $limit; $day++) {
        $date = $now->modify('+' . $day . ' days')->format('Y-m-d');
        $open = count(bk_open_slots($service, $date, $ignoreBookingId));
        if ($open > 0) {
            $found[] = ['date' => $date, 'open' => $open];
        }
    }

    return $found;
}

/** True when a date is inside the window this service accepts. */
function bk_date_in_window(array $service, string $date): bool
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return false;
    }
    $today = bk_now()->format('Y-m-d');
    $latest = bk_now()->modify('+' . max(1, (int) $service['max_advance_days']) . ' days')->format('Y-m-d');
    $date = date('Y-m-d', $timestamp);

    return $date >= $today && $date <= $latest;
}

/**
 * Final availability check, run inside the booking transaction.
 * Returns null when the slot can take the booking, or the reason it cannot.
 */
function bk_slot_problem(array $service, string $date, string $start, int $seats, int $ignoreBookingId = 0): ?string
{
    if (!bk_date_in_window($service, $date)) {
        return 'That date is outside the period we are taking bookings for.';
    }

    foreach (bk_slots($service, $date, $ignoreBookingId) as $slot) {
        if ($slot['start'] !== $start) {
            continue;
        }
        if (!$slot['available']) {
            return $slot['reason'] === 'Too soon'
                ? 'That time is too close to now. Please choose a later slot.'
                : 'That time has just been taken. Please choose another slot.';
        }
        if ($slot['free'] < $seats) {
            return $slot['free'] === 1
                ? 'Only one place is left in that slot.'
                : 'Only ' . $slot['free'] . ' places are left in that slot.';
        }
        return null;
    }

    return 'That time is not one of the slots we offer for this service.';
}

/** True when a specific slot can still take a booking of this size. */
function bk_slot_open(array $service, string $date, string $start, int $seats = 1, int $ignoreBookingId = 0): bool
{
    return bk_slot_problem($service, $date, $start, max(1, $seats), $ignoreBookingId) === null;
}

/** The end time of a slot, for storing on the booking. */
function bk_slot_end(array $service, string $start): string
{
    return bk_clock(bk_minutes($start) + max(5, (int) $service['duration_minutes']));
}

/* ================================================== 5. CALENDAR OVERVIEWS */

/**
 * Bookings in a date range, for the admin calendar.
 *
 * @return array<string, array<int, array<string, mixed>>> keyed by date
 */
function bk_calendar(string $from, string $to, int $serviceId = 0): array
{
    $params = [':from' => $from, ':to' => $to];
    $sql = 'SELECT * FROM service_bookings WHERE booking_date BETWEEN :from AND :to';
    if ($serviceId > 0) {
        $sql .= ' AND service_id = :s';
        $params[':s'] = $serviceId;
    }
    $sql .= ' ORDER BY booking_date, start_time';

    $days = [];
    foreach (db_all($sql, $params) as $row) {
        $days[(string) $row['booking_date']][] = $row;
    }
    return $days;
}
