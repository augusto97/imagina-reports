<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the NEW address when a user changes their login email. The change only takes
 * effect once this link is opened, which proves the person owns that mailbox — so a typo
 * can't lock them out and a hijacked session can't redirect account recovery.
 */
final class VerifyPendingEmail extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appUrl = config('app.url');
        $base = rtrim(is_string($appUrl) ? $appUrl : '', '/');
        $url = $base.'/#/verify-email?token='.$this->token;

        return (new MailMessage)
            ->subject('Confirma tu nuevo correo — Imagina Reports')
            ->greeting('Confirma tu nuevo correo')
            ->line('Pediste cambiar el correo con el que inicias sesión en Imagina Reports.')
            ->action('Confirmar mi correo', $url)
            ->line('El cambio no se aplicará hasta que confirmes desde este enlace.')
            ->line('Si no fuiste tú, ignora este mensaje: tu correo actual seguirá siendo el mismo.');
    }
}
