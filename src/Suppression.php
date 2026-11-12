<?php
declare(strict_types=1);

/**
 * The do-not-contact list. Deliberately keyed by email rather than lead id:
 * if the same person appears twice under two ZoomInfo records, one opt-out
 * still blocks both.
 */
final class Suppression
{
    public static function has(?string $email): bool
    {
        if (!is_valid_email($email)) {
            return false;
        }
        return Db::value(
            'SELECT 1 FROM suppressions WHERE email = :e',
            ['e' => strtolower(trim((string) $email))]
        ) !== null;
    }

    public static function add(string $email, string $reason = 'unsubscribed'): void
    {
        $email = strtolower(trim($email));
        if (!is_valid_email($email)) {
            return;
        }

        Db::upsert('suppressions', ['email' => $email, 'reason' => $reason], ['email']);

        // Mirror onto the lead and stop any sequence it is sitting in.
        Db::run(
            'UPDATE leads SET unsubscribed_at = NOW() WHERE email = :e AND unsubscribed_at IS NULL',
            ['e' => $email]
        );
        Db::run(
            "UPDATE enrollments SET status = 'stopped', stop_reason = :r, next_send_at = NULL
             WHERE status = 'active' AND lead_id IN (SELECT id FROM leads WHERE email = :e)",
            ['e' => $email, 'r' => $reason]
        );
    }

    public static function remove(string $email): void
    {
        Db::run('DELETE FROM suppressions WHERE email = :e', ['e' => strtolower(trim($email))]);
        Db::run('UPDATE leads SET unsubscribed_at = NULL WHERE email = :e', ['e' => strtolower(trim($email))]);
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM suppressions');
    }
}
