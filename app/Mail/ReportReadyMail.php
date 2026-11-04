<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Reports\ReportHighlights;
use App\Services\Platform\Entitlements;
use App\Support\PeriodLabel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The email that announces a report to the client (CLAUDE.md §10.7). White-label: it comes
 * from the agency's name, replies go to the agency, and the platform is never named unless
 * the plan keeps the "powered by" line. It carries the health score, the first three KPIs
 * and the summary, so the client gets the gist without opening anything.
 */
final class ReportReadyMail extends Mailable
{
    public function __construct(public readonly Report $report)
    {
        // Render the email in the report's own locale (FUN — i18n): subject + body follow
        // the definition's language instead of always Spanish.
        $this->locale($this->resolveLocale());
    }

    public function envelope(): Envelope
    {
        $agency = $this->report->agency;
        $fromAddress = config('mail.from.address');
        $replyTo = $this->replyAddress($agency);

        $subject = __('report.ready_subject_site', ['site' => $this->siteName(), 'period' => $this->periodLabel()], $this->locale);

        return new Envelope(
            // The sending address stays the platform's verified one (deliverability); the
            // name the client sees is the agency's.
            from: is_string($fromAddress) && $fromAddress !== '' ? new Address($fromAddress, $agency !== null ? $agency->name : null) : null,
            replyTo: $replyTo !== null ? [new Address($replyTo, $agency?->name)] : [],
            subject: is_string($subject) ? $subject : 'Informe',
        );
    }

    /**
     * The report's language, from its definition's locale. Normalized (pt-BR → pt_BR) and
     * constrained to the locales we actually ship; anything else falls back to Spanish (the
     * product default).
     */
    private function resolveLocale(): string
    {
        $definition = $this->report->definition;
        $raw = $definition instanceof ReportDefinition ? $definition->locale : 'es';

        $normalized = str_replace('-', '_', $raw);

        return in_array($normalized, ['es', 'en', 'pt_BR'], true) ? $normalized : 'es';
    }

    public function content(): Content
    {
        $agency = $this->report->agency;
        $score = $this->report->health_score;
        $verdicts = __('report.verdicts', [], $this->locale);
        $verdict = null;
        if ($score !== null && is_array($verdicts) && count($verdicts) === 4) {
            $verdict = $verdicts[$score >= 90 ? 0 : ($score >= 75 ? 1 : ($score >= 50 ? 2 : 3))];
        }

        $summary = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $this->report->executive_summary)));

        return new Content(
            view: 'emails.report-ready',
            with: [
                'report' => $this->report,
                'agency' => $agency,
                'agencyName' => $agency !== null ? $agency->name : '',
                'logo' => $this->absolute($agency?->logoUrl()),
                'accent' => $this->accent(),
                'site' => $this->siteName(),
                'period' => $this->periodLabel(),
                'score' => $score,
                'scoreColor' => $score === null ? '#71717a' : ($score >= 80 ? '#059669' : ($score >= 50 ? '#d97706' : '#dc2626')),
                'verdict' => $verdict,
                'highlights' => ReportHighlights::from($this->report->resolved_blocks, $this->locale, $this->currency()),
                'summary' => Str::limit($summary, 700),
                'portalUrl' => route('portal', ['token' => $this->report->public_token]),
                'contact' => $agency?->contact() ?? [],
                'showBranding' => $agency === null || ! app(Entitlements::class)->hasFeature($agency, 'remove_branding'),
                'hasPdf' => $this->report->pdf_path !== null,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->report->pdf_path === null) {
            return [];
        }

        $name = Str::slug($this->siteName().' '.$this->report->period_start->format('Y-m')).'.pdf';

        return [Attachment::fromStorage($this->report->pdf_path)->as($name)];
    }

    private function siteName(): string
    {
        $site = $this->report->definition?->site;

        return $site !== null ? $site->name : ($this->report->definition !== null ? $this->report->definition->name : '');
    }

    private function periodLabel(): string
    {
        return PeriodLabel::for($this->report->period_start, $this->report->period_end, $this->locale);
    }

    private function currency(): string
    {
        $currency = $this->report->definition?->site?->currency;

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    /** Where the client's replies go: the agency's contact email, else its owner. */
    private function replyAddress(?Agency $agency): ?string
    {
        if ($agency === null) {
            return null;
        }
        $contact = $agency->contact()['email'] ?? null;
        if ($contact !== null) {
            return $contact;
        }

        $owner = User::query()->where('agency_id', $agency->id)->where('role', UserRole::Owner->value)->orderBy('id')->first();

        return $owner?->email;
    }

    /** The report's accent (its theme, else the agency brand), only as a plain hex colour. */
    private function accent(): string
    {
        $theme = $this->report->resolved_blocks['theme'] ?? null;
        $candidates = [is_array($theme) ? ($theme['accent'] ?? null) : null, $this->report->agency?->brand_color];
        foreach ($candidates as $color) {
            if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
                return $color;
            }
        }

        return '#4f46e5';
    }

    private function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
