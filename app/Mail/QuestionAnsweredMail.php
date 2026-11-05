<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ReportMessageKind;
use App\Mail\Concerns\BrandsReportMail;
use App\Models\Report;
use App\Models\ReportMessage;
use App\Services\Platform\Entitlements;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * To the client: the agency answered their question (Etapa 4). White-label like the report
 * email — the agency's name and colours, replies go to the agency, in the report's language.
 */
final class QuestionAnsweredMail extends Mailable
{
    use BrandsReportMail;

    public function __construct(public readonly ReportMessage $reply, public readonly Report $report)
    {
        $this->locale($this->localeFor($report));
    }

    public function envelope(): Envelope
    {
        $agency = $this->report->agency;
        $sender = $agency?->senderMailer();
        $fromAddress = $sender !== null ? $sender['from_address'] : config('mail.from.address');
        $fromName = $sender !== null && $sender['from_name'] !== null ? $sender['from_name'] : $agency?->name;
        $replyTo = $agency?->contact()['email'] ?? null;
        $subject = __('report.answered_subject', ['agency' => $agency !== null ? $agency->name : ''], $this->locale);

        return new Envelope(
            from: is_string($fromAddress) && $fromAddress !== '' ? new Address($fromAddress, $fromName) : null,
            replyTo: $replyTo !== null ? [new Address($replyTo, $agency?->name)] : [],
            subject: is_string($subject) ? $subject : 'Respuesta',
        );
    }

    public function content(): Content
    {
        $agency = $this->report->agency;
        $agencyName = $agency !== null ? $agency->name : '';
        $question = ReportMessage::query()->withoutGlobalScopes()
            ->where('report_id', $this->report->id)
            ->where('kind', ReportMessageKind::Client->value)
            ->where('id', '<', $this->reply->id)
            ->latest('id')
            ->first();
        $portal = $agency !== null ? $agency->publicUrl('portal/'.$this->report->public_token) : route('portal', ['token' => $this->report->public_token]);
        $locale = $this->locale;
        $t = static function (string $key, array $replace = []) use ($locale): string {
            /** @var array<string, string> $replace */
            $text = __('report.'.$key, $replace, $locale);

            return is_string($text) ? $text : $key;
        };

        return new Content(
            view: 'emails.conversation',
            with: [
                'title' => $t('answered_title', ['agency' => $agencyName]),
                'preview' => Str::limit($this->reply->body, 120),
                'eyebrow' => $this->siteNameFor($this->report),
                'lead' => $t('answered_lead', ['period' => $this->periodFor($this->report, $locale)]),
                'quote' => $question?->body,
                'quoteLabel' => $question !== null ? $t('answered_you_asked') : null,
                'reply' => $this->reply->body,
                'replyLabel' => $this->reply->user !== null ? $this->reply->user->name.' · '.$agencyName : $agencyName,
                'ctaUrl' => $portal.'?preguntas=1',
                'ctaLabel' => $t('answered_cta'),
                'note' => null,
                'footer' => $t('ready_reply'),
                'showBranding' => $agency === null || ! app(Entitlements::class)->hasFeature($agency, 'remove_branding'),
                'logo' => $this->logoFor($agency),
                'agencyName' => $agencyName,
                'accent' => $this->accentFor($this->report),
            ],
        );
    }
}
