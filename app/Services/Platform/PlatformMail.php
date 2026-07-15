<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Throwable;

/**
 * Applies the outbound-mail configuration saved by the super-admin (PlatformSetting) over
 * the config/.env defaults, so the operator manages SMTP + the "from" identity from the
 * panel instead of editing the server. Called on boot; a missing/empty setting leaves the
 * .env values in place (the fallback). Read-only and defensive — never throws on boot.
 */
final class PlatformMail
{
    /** Setting keys (also the request field names in the controller). */
    public const MAILER = 'mail_mailer';

    public const HOST = 'mail_host';

    public const PORT = 'mail_port';

    public const USERNAME = 'mail_username';

    public const PASSWORD = 'mail_password'; // secret

    public const SCHEME = 'mail_scheme';

    public const FROM_ADDRESS = 'mail_from_address';

    public const FROM_NAME = 'mail_from_name';

    public static function apply(): void
    {
        try {
            $settings = PlatformSetting::query()->first();
        } catch (Throwable) {
            // No DB / table yet (fresh install, migrations) — keep the .env defaults.
            return;
        }

        if ($settings === null) {
            return;
        }

        // The "from" identity applies regardless of the transport.
        $fromAddress = self::str($settings->get(self::FROM_ADDRESS));
        if ($fromAddress !== '') {
            config(['mail.from.address' => $fromAddress]);
        }
        $fromName = self::str($settings->get(self::FROM_NAME));
        if ($fromName !== '') {
            config(['mail.from.name' => $fromName]);
        }

        $mailer = self::str($settings->get(self::MAILER));
        if ($mailer === '') {
            return; // no transport override → use the .env mailer
        }

        config(['mail.default' => $mailer]);

        // Full SMTP override (the universal case). Other transports (resend/ses/postmark)
        // still read their API keys from config/.env.
        if ($mailer === 'smtp') {
            $overrides = [
                'mail.mailers.smtp.host' => self::str($settings->get(self::HOST)),
                'mail.mailers.smtp.port' => (int) self::str($settings->get(self::PORT)) ?: 587,
                'mail.mailers.smtp.username' => self::str($settings->get(self::USERNAME)) ?: null,
                'mail.mailers.smtp.password' => $settings->secret(self::PASSWORD),
                'mail.mailers.smtp.scheme' => self::str($settings->get(self::SCHEME)) ?: null,
            ];
            config(array_filter($overrides, static fn (mixed $value): bool => $value !== ''));
        }
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
