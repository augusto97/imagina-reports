<?php

declare(strict_types=1);

namespace App\Connectors\Mailchimp;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mailchimp connector (CLAUDE.md §9). Reads email-marketing performance — campaigns sent,
 * emails delivered, opens, clicks, open/click rate, unsubscribes — by aggregating the
 * campaign reports whose send date falls in the period (the report list is already
 * server-summarized, §3.3). Auth is an API key; its datacenter suffix (e.g. `-us21`) selects
 * the API host.
 */
final class MailchimpConnector implements DataSourceConnector, ProvidesSetupGuide
{
    use ParsesValues;

    public function key(): string
    {
        return DataSourceType::Mailchimp->value;
    }

    public function label(): string
    {
        return DataSourceType::Mailchimp->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('api_key', 'API key', ConfigFieldType::Password, secret: true, help: 'Tu clave de API de Mailchimp (Account → Extras → API keys). Termina en «-usXX», que indica el servidor.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('mailchimp.campaigns_sent', 'Campañas enviadas', MetricType::Scalar, 'count'),
            new MetricDefinition('mailchimp.emails_sent', 'Emails enviados', MetricType::Scalar, 'count'),
            new MetricDefinition('mailchimp.opens', 'Aperturas únicas', MetricType::Scalar, 'count'),
            new MetricDefinition('mailchimp.clicks', 'Clics', MetricType::Scalar, 'count'),
            new MetricDefinition('mailchimp.unsubscribes', 'Bajas', MetricType::Scalar, 'count'),
            new MetricDefinition('mailchimp.open_rate', 'Tasa de apertura', MetricType::Scalar, 'percent'),
            new MetricDefinition('mailchimp.click_rate', 'Tasa de clics', MetricType::Scalar, 'percent'),
            new MetricDefinition('mailchimp.top_campaigns', 'Campañas del periodo', MetricType::Table),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        try {
            $client = $this->client($source);
            if ($client === null) {
                return ConnectionResult::failure('La API key de Mailchimp no tiene el sufijo de servidor esperado (p. ej. «-us21»).');
            }

            $response = $client->get('/ping');

            return $response->successful()
                ? ConnectionResult::success('Mailchimp reachable.')
                : ConnectionResult::failure('Mailchimp respondió HTTP '.$response->status().' '.$this->apiError($response->json()));
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo conectar con Mailchimp: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $client = $this->client($source);
        if ($client === null) {
            return MetricSet::failed('Mailchimp: la API key no incluye el sufijo de servidor (p. ej. «-us21»).');
        }

        try {
            $response = $client->get('/reports', [
                'since_send_time' => $period->start->toIso8601String(),
                'before_send_time' => $period->end->toIso8601String(),
                'count' => 100,
            ]);
        } catch (Throwable $e) {
            return MetricSet::failed('Mailchimp request error: '.$e->getMessage());
        }

        if ($response->failed()) {
            return MetricSet::failed('Mailchimp request failed: HTTP '.$response->status().' '.$this->apiError($response->json()));
        }

        $reports = $this->listOf(Arr::get($this->arrayOf($response->json()), 'reports'));

        $emailsSent = 0;
        $opens = 0;
        $clicks = 0;
        $unsubscribes = 0;
        $campaigns = [];

        foreach ($reports as $report) {
            $sent = $this->toInt(Arr::get($report, 'emails_sent'));
            $uniqueOpens = $this->toInt(Arr::get($report, 'opens.unique_opens'));
            $totalClicks = $this->toInt(Arr::get($report, 'clicks.clicks_total'));

            $emailsSent += $sent;
            $opens += $uniqueOpens;
            $clicks += $totalClicks;
            $unsubscribes += $this->toInt(Arr::get($report, 'unsubscribed'));

            $campaigns[] = [
                'campaign' => $this->toStr(Arr::get($report, 'campaign_title')),
                'emails_sent' => $sent,
                'open_rate' => round($this->toFloat(Arr::get($report, 'opens.open_rate')) * 100, 1),
                'click_rate' => round($this->toFloat(Arr::get($report, 'clicks.click_rate')) * 100, 1),
            ];
        }

        usort($campaigns, static fn (array $a, array $b): int => $b['emails_sent'] <=> $a['emails_sent']);

        return MetricSet::ok([
            'mailchimp.campaigns_sent' => count($reports),
            'mailchimp.emails_sent' => $emailsSent,
            'mailchimp.opens' => $opens,
            'mailchimp.clicks' => $clicks,
            'mailchimp.unsubscribes' => $unsubscribes,
            'mailchimp.open_rate' => $emailsSent > 0 ? round($opens / $emailsSent * 100, 1) : 0.0,
            'mailchimp.click_rate' => $emailsSent > 0 ? round($clicks / $emailsSent * 100, 1) : 0.0,
            'mailchimp.top_campaigns' => array_slice($campaigns, 0, 10),
        ]);
    }

    /** The API host is derived from the key's datacenter suffix; null when it's missing. */
    private function client(DataSource $source): ?PendingRequest
    {
        $key = $this->toStr(Arr::get($source->credentials ?? [], 'api_key'));
        $dc = str_contains($key, '-') ? substr(strrchr($key, '-') ?: '', 1) : '';

        if ($dc === '') {
            return null;
        }

        return Http::baseUrl("https://{$dc}.api.mailchimp.com/3.0")
            ->withBasicAuth('imagina', $key)
            ->acceptJson()
            ->timeout(30);
    }

    private function apiError(mixed $json): string
    {
        $detail = is_array($json) ? Arr::get($json, 'detail') : null;

        return is_string($detail) ? $detail : '';
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta Mailchimp con una API key.',
            [
                'En Mailchimp: Account → Extras → API keys → «Create A Key».',
                'Copia la clave completa (incluye el sufijo «-usXX» del servidor).',
                'Pégala en «API key», guarda y pulsa «Probar conexión».',
            ],
            'https://mailchimp.com/developer/marketing/api/reports/',
        );
    }
}
