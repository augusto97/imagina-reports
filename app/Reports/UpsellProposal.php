<?php

declare(strict_types=1);

namespace App\Reports;

use App\Ai\AiClient;
use App\Enums\UpsellType;
use App\Models\Agency;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Preparar propuesta» (Etapa 5): turns an upsell signal into an email the agency can send the
 * client — opening with the client's own number, then the proposal and a next step. Written by
 * the AI when the plan includes it (from the same facts, never inventing prices), else from a
 * template per signal. Always a draft: the agency edits and sends it from its own mail.
 */
final readonly class UpsellProposal
{
    public function __construct(
        private AiClient $ai,
        private Entitlements $entitlements,
    ) {}

    /**
     * @return array{subject: string, body: string, ai: bool}
     */
    public function draft(Site $site, UpsellOpportunity $opportunity, Agency $agency): array
    {
        $template = $this->template($site, $opportunity, $agency);

        if (! $this->entitlements->hasFeature($agency, 'ai_builder')) {
            return $template + ['ai' => false];
        }

        try {
            $raw = $this->ai->complete($this->system($agency), $this->prompt($site, $opportunity, $template));
            $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? ''), true);
            if (is_array($json) && is_string($json['subject'] ?? null) && is_string($json['body'] ?? null) && trim($json['body']) !== '') {
                return ['subject' => trim($json['subject']), 'body' => trim($json['body']), 'ai' => true];
            }
        } catch (Throwable $e) {
            Log::warning('Upsell proposal AI draft failed; using the template.', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        return $template + ['ai' => false];
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function template(Site $site, UpsellOpportunity $opportunity, Agency $agency): array
    {
        $c = $opportunity->context;
        $n = static fn (mixed $value, int $decimals = 0): string => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '';
        $site_ = $site->name;

        [$subject, $hook, $offer] = match ($opportunity->type) {
            UpsellType::TrafficGrowth => [
                "{$site_}: tus visitas crecieron un {$n($c['change_percent'] ?? 0)} %",
                "{$site_} tuvo un mes muy bueno: las visitas subieron un {$n($c['change_percent'] ?? 0)} % frente al mes anterior ({$n($c['previous'] ?? 0)} → {$n($c['current'] ?? 0)}).",
                'Cuando el tráfico crece así, conviene aprovecharlo: mejorar las páginas que más visitas reciben, reforzar el SEO de lo que ya funciona y asegurar que el sitio responde bien con más carga. Te propongo sumar a tu plan unas horas mensuales de SEO y optimización.',
            ],
            UpsellType::SalesGrowth => [
                "{$site_}: tus ventas crecieron un {$n($c['change_percent'] ?? 0)} %",
                "Las ventas de {$site_} subieron un {$n($c['change_percent'] ?? 0)} % frente al mes anterior.",
                'Con la tienda creciendo, cada caída o error cuesta más. Te propongo un plan de soporte para tiendas: revisión mensual de la conversión, prioridad en incidencias y pruebas del proceso de compra después de cada actualización.',
            ],
            UpsellType::SecurityHardening => [
                "{$site_} recibió {$n($c['attacks'] ?? 0)} ataques este mes",
                "Este mes bloqueamos {$n($c['attacks'] ?? 0)} ataques contra {$site_}. Todos se detuvieron, pero el volumen es alto.",
                'Te propongo reforzar la protección con un firewall gestionado y una revisión de seguridad mensual, para que el sitio siga a salvo aunque los ataques aumenten.',
            ],
            UpsellType::UptimeMonitoring => [
                "¿Sabrías si {$site_} se cae esta noche?",
                "Hoy no estamos vigilando la disponibilidad de {$site_} minuto a minuto.",
                'Te propongo añadir monitoreo 24/7: si el sitio se cae, nos enteramos al momento y actuamos antes de que lo notes tú o tus clientes. Además, verás el porcentaje de disponibilidad cada mes en tu informe.',
            ],
            UpsellType::SecurityProtection => [
                "Protejamos {$site_} contra ataques",
                "{$site_} no tiene hoy una capa de protección contra ataques ni escaneo de malware.",
                'Te propongo activar un firewall y el escaneo de malware: bloquean los intentos de entrada antes de que lleguen al sitio y verás en tu informe cuántos se detuvieron.',
            ],
            UpsellType::PlanOveruse => [
                "{$site_}: ajustemos el plan a lo que de verdad necesitas",
                "Este mes dedicamos {$n($c['hours'] ?? 0, 1)} h a {$site_}, un {$n($c['over_percent'] ?? 0)} % más que las {$n($c['plan_hours'] ?? 0, 1)} h de tu plan.",
                'Para no tener que dejar tareas fuera ni cobrarte horas sueltas, te propongo pasar al plan siguiente: tendrás más horas cada mes y un precio por hora mejor.',
            ],
            UpsellType::GoalsReached => [
                "{$site_} cumplió todas sus metas 🎯",
                "Este mes {$site_} alcanzó todas las metas que nos propusimos ({$n($c['goals'] ?? 0)}).",
                'Es el momento de apuntar más alto: te propongo fijar metas nuevas para el próximo trimestre y el plan de trabajo para lograrlas.',
            ],
            UpsellType::Performance => [
                "{$site_} podría cargar más rápido",
                "Tu sitio tarda de media {$n($c['response_ms'] ?? 0)} ms en responder. Cada segundo de espera hace que parte de las visitas se vaya.",
                'Te propongo una optimización de velocidad: caché, imágenes, scripts y servidor. Al terminar te mostramos el antes y el después en tu informe.',
            ],
        };

        $client = $site->client !== null ? $site->client->name : null;
        $body = 'Hola'.($client !== null ? ", equipo de {$client}" : '').":\n\n{$hook}\n\n{$offer}\n\n¿Te parece si lo vemos en una llamada de 15 minutos esta semana?\n\nUn saludo,\n{$agency->name}";

        return ['subject' => $subject, 'body' => $body];
    }

    private function system(Agency $agency): string
    {
        return <<<PROMPT
        You write a short sales email from the web agency "{$agency->name}" to one of its clients.
        - Neutral Spanish, addressing the client as «tú» (never «vos» or voseo). Warm, plain, no jargon.
        - Open with the client's own figure from the facts, then the proposal, then one clear next step (a short call).
        - Never invent prices, discounts, deadlines or numbers that are not in the facts.
        - At most 150 words. No markdown, no headings.
        Return ONLY JSON: {"subject": "...", "body": "..."}.
        PROMPT;
    }

    /**
     * @param  array{subject: string, body: string}  $template
     */
    private function prompt(Site $site, UpsellOpportunity $opportunity, array $template): string
    {
        $facts = json_encode([
            'sitio' => $site->name,
            'cliente' => $site->client?->name,
            'senal' => $opportunity->type->value,
            'datos' => $opportunity->context,
            'propuesta_base' => $template['body'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return 'Facts (JSON):'."\n".(is_string($facts) ? $facts : '{}')."\n\nRewrite the base proposal so it reads natural and personal, keeping its offer.";
    }
}
