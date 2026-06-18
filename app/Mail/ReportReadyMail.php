<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Report;
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
    public function __construct(public readonly Report $report) {}

    public function envelope(): Envelope
    {
        // Subject uses the platform name; the body (blade) carries the agency branding.
        $appName = config('app.name');
        $name = is_string($appName) ? $appName : 'Imagina Reports';

        return new Envelope(subject: $name.' — tu reporte está listo');
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
