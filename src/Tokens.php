<?php
declare(strict_types=1);

/**
 * Random, single-purpose tokens for the public pages. Booking and unsubscribe
 * links must work without a login, so the token is the only credential —
 * hence 256 bits of entropy and a lookup by primary key.
 */
final class Tokens
{
    /** Reuse an existing token per (lead, purpose) so links stay stable across a sequence. */
    public static function forLead(int $leadId, string $purpose, ?int $userId = null, ?string $expiresAt = null): string
    {
        $existing = Db::value(
            'SELECT token FROM tokens
             WHERE lead_id = :lead AND purpose = :purpose
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC LIMIT 1',
            ['lead' => $leadId, 'purpose' => $purpose]
        );

        if (is_string($existing) && $existing !== '') {
            if ($userId !== null) {
                Db::run('UPDATE tokens SET user_id = :u WHERE token = :t', ['u' => $userId, 't' => $existing]);
            }
            return $existing;
        }

        $token = random_token();
        Db::insert('tokens', [
            'token'      => $token,
            'purpose'    => $purpose,
            'lead_id'    => $leadId,
            'user_id'    => $userId ?: null,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public static function bookingUrl(int $leadId, int $userId = 0): string
    {
        if ($leadId <= 0) {
            return public_url('book.php?t=preview');   // previews use a made-up contact
        }
        $expires = date('Y-m-d H:i:s', strtotime('+90 days'));
        return public_url('book.php?t=' . self::forLead($leadId, 'book', $userId ?: null, $expires));
    }

    public static function unsubscribeUrl(int $leadId): string
    {
        if ($leadId <= 0) {
            return public_url('unsubscribe.php?t=preview');
        }
        return public_url('unsubscribe.php?t=' . self::forLead($leadId, 'unsubscribe'));
    }

    /** @return array<string,mixed>|null */
    public static function resolve(string $token, string $purpose): ?array
    {
        if ($token === '') {
            return null;
        }
        return Db::one(
            'SELECT t.*, l.name AS lead_name, l.email AS lead_email, l.company, l.description
             FROM tokens t
             JOIN leads l ON l.id = t.lead_id
             WHERE t.token = :t AND t.purpose = :p
               AND (t.expires_at IS NULL OR t.expires_at > NOW())',
            ['t' => $token, 'p' => $purpose]
        );
    }

    public static function markUsed(string $token): void
    {
        Db::run('UPDATE tokens SET used_at = NOW() WHERE token = :t', ['t' => $token]);
    }
}
