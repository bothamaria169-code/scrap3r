<?php
declare(strict_types=1);

/**
 * Key/value app settings, backed by the `settings` table.
 * SMTP credentials live here so the sales team can change them in the UI
 * without touching .env or redeploying.
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'smtp_host'        => '',
        'smtp_port'        => '587',
        'smtp_secure'      => 'tls',      // tls (STARTTLS) | ssl (implicit) | none
        'smtp_user'        => '',
        'smtp_pass'        => '',
        'smtp_timeout'     => '20',
        'from_name'        => '',
        'from_email'       => '',
        'reply_to'         => '',
        'company_name'     => '',
        'company_address'  => '',        // required in the footer by CAN-SPAM
        'daily_send_cap'   => '200',
        'hunter_api_key'   => '',
        'signature_html'   => '',     // inserted wherever a template uses {{signature}}
        'brand_color'      => '',     // email designs inherit these unless they override them
        'brand_logo'       => '',     // "uploads/..." path of the logo image
        'brand_header'     => '',     // text shown at the top when there is no logo
    ];

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (Db::all('SELECT skey, svalue FROM settings') as $row) {
                self::$cache[$row['skey']] = (string) $row['svalue'];
            }
            // .env acts as the fallback for SMTP so a machine can be preconfigured.
            foreach (['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'hunter_api_key'] as $key) {
                if (self::$cache[$key] === '') {
                    self::$cache[$key] = (string) env(strtoupper($key), self::DEFAULTS[$key]);
                }
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::load()[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::load();
    }

    public static function set(string $key, string $value): void
    {
        Db::upsert('settings', ['skey' => $key, 'svalue' => $value], ['skey']);
        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                self::set($key, $value);
            }
        }
    }

    /** True when enough is configured to attempt a send. */
    public static function smtpReady(): bool
    {
        return self::get('smtp_host') !== '' && is_valid_email(self::get('from_email'));
    }
}
