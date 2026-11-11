<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\SourceEvent;
use App\Models\Agency;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To one team member: a client's connection was connected, works again or was removed (the
 * «stopped working» email is SourceFailedMail, which carries the reconnect button). Same words
 * as the notice in the bell, plus a link to the site's connections.
 */
final class SourceActivityMail extends Mailable
{
    public function __construct(
        public readonly SourceEvent $event,
        public readonly string $headline,
        public readonly string $detail,
        public readonly string $site,
        public readonly string $link,
        public readonly ?Agency $agency,
    ) {
        $this->locale('es');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        $app = config('app.name');
        $color = $this->agency?->brand_color;
        $logo = $this->agency?->logoUrl();

        return new Content(
            view: 'emails.conversation',
            with: [
                'title' => $this->headline,
                'preview' => $this->detail,
                'eyebrow' => $this->site,
                'lead' => $this->detail,
                'quote' => null,
                'quoteLabel' => null,
                'reply' => null,
                'replyLabel' => null,
                'ctaUrl' => $this->link,
                'ctaLabel' => $this->event === SourceEvent::Removed ? 'Ver las fuentes del sitio' : 'Ver la conexión',
                'note' => 'Eliges qué avisos te llegan, y de qué sitios, en Ajustes → Avisos.',
                'footer' => 'Aviso de '.($this->agency !== null ? $this->agency->name : 'tu agencia').' en '.(is_string($app) ? $app : 'Imagina Reports').'.',
                'showBranding' => false,
                'logo' => $logo !== null && $logo !== '' ? (str_starts_with($logo, 'http') ? $logo : url($logo)) : null,
                'agencyName' => $this->agency !== null ? $this->agency->name : '',
                'accent' => is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#4f46e5',
            ],
        );
    }
}
