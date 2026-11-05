<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ConnectInvite;
use App\Models\DataSource;
use App\Services\Platform\Entitlements;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the client: please reconnect this account (Etapa 5). White-label like every client email —
 * the agency's name, colour and reply address — with one button to a link that only lets them
 * reconnect that one source, for a few days.
 */
final class ReconnectRequestMail extends Mailable
{
    public function __construct(
        public readonly DataSource $source,
        public readonly ConnectInvite $invite,
        public readonly ?string $note = null,
    ) {
        $locale = str_replace('-', '_', $source->site?->client->locale ?? 'es');
        $this->locale(in_array($locale, ['es', 'en', 'pt_BR'], true) ? $locale : 'es');
    }

    public function envelope(): Envelope
    {
        $agency = $this->source->agency;
        $sender = $agency?->senderMailer();
        $fromAddress = $sender !== null ? $sender['from_address'] : config('mail.from.address');
        $fromName = $sender !== null && $sender['from_name'] !== null ? $sender['from_name'] : $agency?->name;
        $replyTo = $agency?->contact()['email'] ?? null;

        return new Envelope(
            from: is_string($fromAddress) && $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            replyTo: $replyTo !== null ? [new Address($replyTo, $agency?->name)] : [],
            subject: $this->t('reconnect_subject', ['agency' => $agency !== null ? $agency->name : '', 'source' => $this->source->type->label()]),
        );
    }

    public function content(): Content
    {
        $agency = $this->source->agency;
        $site = $this->source->site !== null ? $this->source->site->name : '';
        $color = $agency?->brand_color;
        $logo = $agency?->logoUrl();
        $note = $this->note !== null && trim($this->note) !== '' ? trim($this->note) : null;

        return new Content(
            view: 'emails.conversation',
            with: [
                'title' => $this->t('reconnect_title', ['source' => $this->source->type->label()]),
                'preview' => $this->t('reconnect_title', ['source' => $this->source->type->label()]),
                'eyebrow' => $site,
                'lead' => $this->t('reconnect_lead', ['source' => $this->source->type->label(), 'site' => $site]),
                'quote' => $note,
                'quoteLabel' => $note !== null && $agency !== null ? $agency->name : null,
                'reply' => null,
                'replyLabel' => null,
                'ctaUrl' => $this->invite->url(),
                'ctaLabel' => $this->t('reconnect_cta'),
                'note' => $this->t('reconnect_note', ['date' => $this->expiry()]),
                'footer' => $this->t('ready_reply'),
                'showBranding' => $agency === null || ! app(Entitlements::class)->hasFeature($agency, 'remove_branding'),
                'logo' => $logo !== null && $logo !== '' ? (str_starts_with($logo, 'http') ? $logo : url($logo)) : null,
                'agencyName' => $agency !== null ? $agency->name : '',
                'accent' => is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#4f46e5',
            ],
        );
    }

    private function expiry(): string
    {
        $date = $this->invite->expires_at->copy();
        $date->locale($this->locale);

        return $date->isoFormat('LL');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        $text = __('report.'.$key, $replace, $this->locale);

        return is_string($text) ? $text : $key;
    }
}
