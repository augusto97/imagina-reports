<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DataSource;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the agency team: one of a client's connections stopped working (Etapa 5). Says which,
 * what the source answered, and the fastest fix — usually asking the client to reconnect from a
 * link, which the button prepares in the panel.
 */
final class SourceFailedMail extends Mailable
{
    public function __construct(public readonly DataSource $source, public readonly bool $reconnectable)
    {
        $this->locale('es');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->source->type->label().' dejó de funcionar en '.$this->siteName());
    }

    public function content(): Content
    {
        $agency = $this->source->agency;
        $url = config('app.url');
        $base = rtrim(is_string($url) ? $url : '', '/');
        $app = config('app.name');
        $error = (string) $this->source->last_error;
        $link = $base.'/admin#/clients?site='.$this->source->site_id.($this->reconnectable ? '&reconnect='.$this->source->id : '');
        $color = $agency?->brand_color;
        $logo = $agency?->logoUrl();

        return new Content(
            view: 'emails.conversation',
            with: [
                'title' => $this->source->type->label().' dejó de funcionar',
                'preview' => $this->siteName().': '.$error,
                'eyebrow' => $this->siteName(),
                'lead' => $this->reconnectable
                    ? 'Si no se arregla, el próximo informe saldrá sin esos datos. Lo más habitual es que el permiso haya caducado: pídele al cliente que vuelva a conectar su cuenta con un enlace, sin contraseñas.'
                    : 'Si no se arregla, el próximo informe saldrá sin esos datos. Revisa la clave o la configuración de la fuente.',
                'quote' => $error !== '' ? $error : null,
                'quoteLabel' => $error !== '' ? 'Lo que respondió '.$this->source->type->label() : null,
                'reply' => null,
                'replyLabel' => null,
                'ctaUrl' => $link,
                'ctaLabel' => $this->reconnectable ? 'Pedir reconexión al cliente' : 'Revisar la conexión',
                'note' => 'Te avisamos una sola vez por caída. Puedes apagar estos avisos en Ajustes → Integraciones → Avisos por correo.',
                'footer' => 'Aviso de '.($agency !== null ? $agency->name : 'tu agencia').' en '.(is_string($app) ? $app : 'Imagina Reports').'.',
                'showBranding' => false,
                'logo' => $logo !== null && $logo !== '' ? (str_starts_with($logo, 'http') ? $logo : url($logo)) : null,
                'agencyName' => $agency !== null ? $agency->name : '',
                'accent' => is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#4f46e5',
            ],
        );
    }

    private function siteName(): string
    {
        return $this->source->site !== null ? $this->source->site->name : 'un sitio';
    }
}
