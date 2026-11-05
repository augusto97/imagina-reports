<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\Domains\DomainVerifier;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Full white-label (plan feature `custom_domain`): the agency's own domain for the pages its
 * clients open, and its own mail server so report emails come from its address.
 */
final class AgencyBrandingController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** Set (or clear) the custom domain; it is checked right away. */
    public function domain(Request $request, TenantContext $tenant, DomainVerifier $verifier): JsonResponse
    {
        $agency = $this->agency($request, $tenant);
        $raw = $request->input('domain');

        if (! is_string($raw) || trim($raw) === '') {
            $agency->forceFill(['domain' => null, 'domain_status' => null, 'domain_checked_at' => null, 'domain_error' => null])->save();

            return response()->json(self::presentDomain($agency));
        }

        $domain = DomainVerifier::normalize($raw);
        $platform = DomainVerifier::platformHost();
        if ($domain === null) {
            throw ValidationException::withMessages(['domain' => 'Escribe solo el dominio, por ejemplo reportes.tuagencia.com.']);
        }
        if ($domain === $platform || str_ends_with($domain, '.'.$platform)) {
            throw ValidationException::withMessages(['domain' => 'Usa un dominio tuyo, no el de la plataforma.']);
        }
        if (Agency::query()->where('domain', $domain)->where('id', '!=', $agency->id)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Ese dominio ya lo usa otra cuenta.']);
        }

        if ($agency->domain !== $domain) {
            $agency->forceFill(['domain' => $domain, 'domain_status' => DomainVerifier::PENDING_DNS, 'domain_error' => null])->save();
        }

        return response()->json(self::presentDomain($verifier->check($agency)));
    }

    public function checkDomain(Request $request, TenantContext $tenant, DomainVerifier $verifier): JsonResponse
    {
        $agency = $this->agency($request, $tenant);

        return response()->json(self::presentDomain($verifier->check($agency)));
    }

    /** Save the agency's own SMTP sender. A blank password keeps the stored one. */
    public function sender(Request $request, TenantContext $tenant): JsonResponse
    {
        $agency = $this->agency($request, $tenant);

        if ($request->boolean('remove')) {
            $settings = $agency->settings ?? [];
            unset($settings['sender']);
            $agency->forceFill(['settings' => $settings])->save();

            return response()->json(self::presentSender($agency));
        }

        /** @var array{from_address: string, from_name?: string|null, host: string, port: int|string, username?: string|null, password?: string|null, scheme?: string|null} $data */
        $data = $request->validate([
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'scheme' => ['nullable', 'in:smtp,smtps'],
        ]);

        $settings = $agency->settings ?? [];
        $current = is_array($settings['sender'] ?? null) ? $settings['sender'] : [];
        $password = $data['password'] ?? null;

        $settings['sender'] = [
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'] ?? null,
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'username' => $data['username'] ?? null,
            'scheme' => $data['scheme'] ?? null,
            // Encrypted at rest; never sent back to the browser (§6).
            'password' => is_string($password) && $password !== '' ? Crypt::encryptString($password) : ($current['password'] ?? null),
        ];
        $agency->forceFill(['settings' => $settings])->save();

        return response()->json(self::presentSender($agency));
    }

    /** Send a test through the agency's own server and report the server's exact answer. */
    public function testSender(Request $request, TenantContext $tenant): JsonResponse
    {
        $agency = $this->agency($request, $tenant);
        $to = $request->string('to')->toString();
        $request->validate(['to' => ['required', 'email']]);
        $config = $agency->senderMailer();
        if ($config === null) {
            return response()->json(['sent' => false, 'error' => 'Primero guarda los datos del servidor de correo.']);
        }

        try {
            Mail::build($config['mailer'])->raw(
                "Correo de prueba de {$agency->name}. Si lo recibes, tus informes saldrán desde {$config['from_address']}.",
                static function (Message $message) use ($to, $config, $agency): void {
                    $message->to($to)->from($config['from_address'], $config['from_name'] ?? $agency->name)->subject('Prueba de envío');
                },
            );
        } catch (Throwable $e) {
            return response()->json(['sent' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['sent' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentDomain(Agency $agency): array
    {
        return [
            'domain' => $agency->domain,
            'domain_status' => $agency->domain_status,
            'domain_error' => $agency->domain_error,
            'domain_checked_at' => $agency->domain_checked_at?->toIso8601String(),
            'domain_target' => DomainVerifier::platformHost(),
            'public_base_url' => $agency->publicBaseUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentSender(Agency $agency): array
    {
        $sender = is_array($agency->settings['sender'] ?? null) ? $agency->settings['sender'] : null;

        return ['sender' => $sender === null ? null : [
            'from_address' => $sender['from_address'] ?? '',
            'from_name' => $sender['from_name'] ?? '',
            'host' => $sender['host'] ?? '',
            'port' => $sender['port'] ?? '',
            'username' => $sender['username'] ?? '',
            'scheme' => $sender['scheme'] ?? '',
            'password_set' => is_string($sender['password'] ?? null) && $sender['password'] !== '',
        ]];
    }

    private function agency(Request $request, TenantContext $tenant): Agency
    {
        $this->authorizePrivileged($request);
        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($this->entitlements->hasFeature($agency, 'custom_domain'), 403, 'Tu plan no incluye dominio y correo propios. Mejora el plan para usarlos.');

        return $agency;
    }
}
