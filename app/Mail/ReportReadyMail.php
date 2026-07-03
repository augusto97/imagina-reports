<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Report;
use App\Models\ReportDefinition;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Branded "your report is ready" email (CLAUDE.md §10.7): summary + PDF attachment
 * + link to the interactive portal. Agency branding comes from the report's agency.
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
        // Subject uses the platform name; the body (blade) carries the agency branding.
        $appName = config('app.name');
        $name = is_string($appName) ? $appName : 'Imagina Reports';

        $subject = __('report.ready_subject');

        return new Envelope(subject: $name.' — '.(is_string($subject) ? $subject : 'tu reporte está listo'));
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
        return new Content(
            markdown: 'emails.report-ready',
            with: [
                'report' => $this->report,
                'agency' => $this->report->agency,
                'portalUrl' => route('report.public', ['token' => $this->report->public_token]),
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

        return [Attachment::fromStorage($this->report->pdf_path)->as('reporte.pdf')];
    }
}
