<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\BrandsReportMail;
use App\Models\Report;
use App\Models\ReportMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * To the agency: a client asked something from the portal (Etapa 4). Written in the panel's
 * language (Spanish). When the client left an email, replying to this message reaches them
 * directly; the button opens the conversation in the panel.
 */
final class ClientQuestionMail extends Mailable
{
    use BrandsReportMail;

    public function __construct(public readonly ReportMessage $message, public readonly Report $report)
    {
        $this->locale('es');
    }

    public function envelope(): Envelope
    {
        $author = $this->author();
        $email = $this->message->author_email;

        return new Envelope(
            replyTo: is_string($email) && $email !== '' ? [new Address($email, $author)] : [],
            subject: $author.' preguntó sobre el informe de '.$this->siteNameFor($this->report),
        );
    }

    public function content(): Content
    {
        $agency = $this->report->agency;
        $email = $this->message->author_email;
        $url = config('app.url');
        $base = rtrim(is_string($url) ? $url : '', '/');
        $app = config('app.name');

        return new Content(
            view: 'emails.conversation',
            with: [
                'title' => 'Nueva pregunta de '.$this->author(),
                'preview' => Str::limit($this->message->body, 120),
                'eyebrow' => $this->siteNameFor($this->report),
                'lead' => 'Te escribió desde el informe de '.$this->periodFor($this->report, 'es').'.',
                'quote' => $this->message->body,
                'quoteLabel' => $this->author().(is_string($email) && $email !== '' ? ' · '.$email : ''),
                'reply' => null,
                'replyLabel' => null,
                'ctaUrl' => $base.'/admin#/reports?report='.$this->report->id.'&tab=questions',
                'ctaLabel' => 'Responder',
                'note' => is_string($email) && $email !== ''
                    ? 'Le llegará tu respuesta por correo. También puedes contestar directamente a este mensaje.'
                    : 'No dejó correo: verá tu respuesta cuando vuelva a abrir el informe.',
                'footer' => 'Aviso de '.($agency !== null ? $agency->name : 'tu agencia').' en '.(is_string($app) ? $app : 'Imagina Reports').'.',
                'showBranding' => false,
                'logo' => $this->logoFor($agency),
                'agencyName' => $agency !== null ? $agency->name : '',
                'accent' => $this->accentFor($this->report),
            ],
        );
    }

    private function author(): string
    {
        $name = $this->message->author_name;

        return is_string($name) && $name !== '' ? $name : 'Tu cliente';
    }
}
