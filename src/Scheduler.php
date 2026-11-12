<?php
declare(strict_types=1);

/**
 * Turns a rep's weekly availability into concrete bookable slots, and books them.
 *
 * Times are stored in the rep's own timezone (users.timezone) and rendered to
 * the lead in that same timezone, labelled, rather than guessing the lead's.
 */
final class Scheduler
{
    /** Default Mon–Fri 09:00–17:00 for a brand-new rep. */
    public static function seedDefaultAvailability(int $userId): void
    {
        for ($weekday = 1; $weekday <= 5; $weekday++) {
            Db::run(
                Db::insertIgnore() . ' INTO availability (user_id, weekday, start_time, end_time)
                 VALUES (:u, :w, :s, :e)',
                ['u' => $userId, 'w' => $weekday, 's' => '09:00:00', 'e' => '17:00:00']
            );
        }
    }

    public static function availability(int $userId): array
    {
        return Db::all(
            'SELECT * FROM availability WHERE user_id = :u ORDER BY weekday, start_time',
            ['u' => $userId]
        );
    }

    /**
     * Bookable slots for the next $days days.
     *
     * @return array<string, array<int, array{start:string, end:string, label:string}>>
     *         keyed by Y-m-d
     */
    public static function freeSlots(int $userId, int $days = 14, ?string $fromDate = null): array
    {
        $user = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user) {
            return [];
        }

        $slotMinutes = max(5, (int) $user['slot_minutes']);
        $rules = self::availability($userId);
        if (!$rules) {
            return [];
        }

        $byWeekday = [];
        foreach ($rules as $rule) {
            $byWeekday[(int) $rule['weekday']][] = $rule;
        }

        $tz = new DateTimeZone($user['timezone'] ?: 'UTC');
        $cursor = new DateTimeImmutable($fromDate ?? 'today', $tz);
        $now = new DateTimeImmutable('now', $tz);
        $end = $cursor->modify("+$days days");

        $taken = self::bookedRanges($userId, $cursor->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
        $slots = [];

        for ($day = $cursor; $day < $end; $day = $day->modify('+1 day')) {
            $weekday = (int) $day->format('w');
            foreach ($byWeekday[$weekday] ?? [] as $rule) {
                $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $rule['start_time'], $tz);
                $stop  = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $rule['end_time'], $tz);

                for ($t = $start; $t < $stop; $t = $t->modify("+$slotMinutes minutes")) {
                    $slotEnd = $t->modify("+$slotMinutes minutes");
                    if ($slotEnd > $stop || $t <= $now) {
                        continue;
                    }
                    if (self::overlaps($t, $slotEnd, $taken)) {
                        continue;
                    }
                    $slots[$day->format('Y-m-d')][] = [
                        'start' => $t->format('Y-m-d H:i:s'),
                        'end'   => $slotEnd->format('Y-m-d H:i:s'),
                        'label' => $t->format('g:i A'),
                    ];
                }
            }
        }

        return $slots;
    }

    private static function bookedRanges(int $userId, string $from, string $to): array
    {
        return Db::all(
            "SELECT starts_at, ends_at FROM appointments
             WHERE user_id = :u AND status IN ('scheduled','completed')
               AND starts_at < :to AND ends_at > :from",
            ['u' => $userId, 'from' => $from, 'to' => $to]
        );
    }

    private static function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $taken): bool
    {
        foreach ($taken as $range) {
            if ($start < new DateTimeImmutable($range['ends_at']) && $end > new DateTimeImmutable($range['starts_at'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Book a slot. Re-checks availability inside a transaction so two leads
     * clicking the same slot at the same time cannot both win.
     *
     * @return array{ok:bool, error:?string, appointment_id:?int}
     */
    public static function book(
        int $leadId,
        int $userId,
        string $startsAt,
        string $source = 'self',
        ?string $notes = null,
        ?string $location = null,
    ): array {
        $user = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user) {
            return ['ok' => false, 'error' => 'That rep no longer exists.', 'appointment_id' => null];
        }

        $tz = new DateTimeZone($user['timezone'] ?: 'UTC');
        try {
            $start = new DateTimeImmutable($startsAt, $tz);
        } catch (Exception) {
            return ['ok' => false, 'error' => 'That is not a valid date and time.', 'appointment_id' => null];
        }

        if ($start <= new DateTimeImmutable('now', $tz)) {
            return ['ok' => false, 'error' => 'That time is in the past.', 'appointment_id' => null];
        }

        $end = $start->modify('+' . max(5, (int) $user['slot_minutes']) . ' minutes');
        Db::beginWrite();

        try {
            $clash = Db::value(
                "SELECT id FROM appointments
                 WHERE user_id = :u AND status IN ('scheduled','completed')
                   AND starts_at < :end AND ends_at > :start" . Db::forUpdate(),
                ['u' => $userId, 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]
            );

            if ($clash) {
                Db::rollback();
                return ['ok' => false, 'error' => 'That slot was just taken. Please pick another.', 'appointment_id' => null];
            }

            $id = Db::insert('appointments', [
                'lead_id'   => $leadId,
                'user_id'   => $userId,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at'   => $end->format('Y-m-d H:i:s'),
                'source'    => $source,
                'notes'     => $notes,
                'location'  => $location,
            ]);

            Db::run("UPDATE leads SET status = 'booked' WHERE id = :id", ['id' => $leadId]);
            // A booked lead should stop receiving follow-ups.
            Db::run(
                "UPDATE enrollments SET status = 'stopped', stop_reason = 'booked', next_send_at = NULL
                 WHERE lead_id = :id AND status = 'active'",
                ['id' => $leadId]
            );

            Db::commit();
            return ['ok' => true, 'error' => null, 'appointment_id' => $id];
        } catch (Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'error' => 'Could not save the appointment: ' . $e->getMessage(), 'appointment_id' => null];
        }
    }
}
