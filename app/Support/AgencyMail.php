<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Who at an agency hears about what, and how mail to its CLIENTS goes out. One place for the
 * rules every notification shares: internal alerts go to owners and admins; mail the client
 * reads goes through the agency's own server when it has one (white-label, Etapa 2).
 */
final class AgencyMail
{
    /** Notification switches in `settings.notify` (all on unless the agency turns one off). */
    public const SOURCE_FAILURES = 'source_failures';

    public const CLIENT_QUESTIONS = 'client_questions';

    /**
     * Owners and admins: the people who run the agency.
     *
     * @return list<string>
     */
    public static function team(Agency $agency): array
    {
        $emails = [];
        $users = User::query()->where('agency_id', $agency->id)->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value])->orderBy('id')->get(['email']);
        foreach ($users as $user) {
            if ($user->email !== '' && ! in_array($user->email, $emails, true)) {
                $emails[] = $user->email;
            }
        }

        return $emails;
    }

    /**
     * Where client-facing conversations land: the agency's public contact address when it has
     * one (that's where it asked clients to write), else the team.
     *
     * @return list<string>
     */
    public static function inbox(Agency $agency): array
    {
        $contact = $agency->contact()['email'] ?? null;

        return is_string($contact) && $contact !== '' ? [$contact] : self::team($agency);
    }

    public static function wants(Agency $agency, string $notification): bool
    {
        $notify = $agency->settings['notify'] ?? [];

        return ! is_array($notify) || ($notify[$notification] ?? true) !== false;
    }

    /** Send mail a client will read: through the agency's own server when it set one up. */
    public static function toClient(Agency $agency, string $email, Mailable $mail): void
    {
        $sender = $agency->senderMailer();
        if ($sender !== null) {
            Mail::build($sender['mailer'])->to($email)->send($mail);

            return;
        }

        Mail::to($email)->send($mail);
    }
}
