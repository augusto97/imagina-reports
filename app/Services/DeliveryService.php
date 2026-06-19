<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Enums\ReportStatus;
use App\Events\ReportSent;
use App\Mail\ReportReadyMail;
use App\Models\Report;
use App\Models\ReportDelivery;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The DELIVER stage (CLAUDE.md §3.1/§10.7): ensure the PDF exists, email the branded
 * report to each recipient, and record every attempt in ir_report_deliveries.
 */
final readonly class DeliveryService
{
    public function __construct(private ReportPdfService $pdf) {}

    public function deliver(Report $report): void
    {
        if ($report->pdf_path === null) {
            $this->pdf->generate($report);
            $report->refresh();
        }

        foreach ($this->recipients($report) as $recipient) {
            $this->sendTo($report, $recipient);
        }

        $report->forceFill(['status' => ReportStatus::Sent])->save();

        Event::dispatch(new ReportSent($report));
    }

    /**
     * @return list<string>
     */
    private function recipients(Report $report): array
    {
        return array_values($report->definition->recipients ?? []);
    }

    private function sendTo(Report $report, string $recipient): void
    {
        $delivery = new ReportDelivery;
        $delivery->agency_id = $report->agency_id;
        $delivery->report_id = $report->id;
        $delivery->channel = DeliveryChannel::Email;
        $delivery->recipient = $recipient;

        try {
            Mail::to($recipient)->send(new ReportReadyMail($report));
            $delivery->status = DeliveryStatus::Sent;
            $delivery->sent_at = Date::now();
        } catch (Throwable $exception) {
            $delivery->status = DeliveryStatus::Failed;
            $delivery->error = $exception->getMessage();
        }

        $delivery->save();
    }
}
