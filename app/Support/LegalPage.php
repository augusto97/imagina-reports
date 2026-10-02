<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformSetting;
use Throwable;

/**
 * Shared data for the public homepage and legal pages: the contact address (the platform's
 * outgoing mail sender, configured in super-admin, falling back to MAIL_FROM_ADDRESS) and the
 * date the policy text last changed.
 */
final class LegalPage
{
    /** Bump when the text of the privacy policy or the terms changes. */
    public const UPDATED = '2 de octubre de 2026';

    /**
     * @return array{contact: string, updated: string}
     */
    public static function data(): array
    {
        // A read that never writes and never fails: these pages are public and must render
        // even before the database is migrated.
        try {
            $contact = PlatformSetting::query()->first()?->get('mail_from_address');
        } catch (Throwable) {
            $contact = null;
        }
        if (! is_string($contact) || $contact === '') {
            $contact = config('mail.from.address');
        }

        return [
            'contact' => is_string($contact) && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false ? $contact : '',
            'updated' => self::UPDATED,
        ];
    }
}
