<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Platform\PlatformMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outbound-mail (SMTP) settings, managed from the super-admin instead of the .env. The
 * password is stored encrypted and never returned; everything else is echoed so the
 * operator can see/edit it. A "send test" action verifies the config end-to-end.
 * Platform-admin only (route middleware).
 */
final class PlatformMailController extends Controller
{
    private const PLAIN = [PlatformMail::MAILER, PlatformMail::HOST, PlatformMail::PORT, PlatformMail::USERNAME, PlatformMail::SCHEME, PlatformMail::FROM_ADDRESS, PlatformMail::FROM_NAME];

    public function show(): JsonResponse
    {
        return response()->json($this->present(PlatformSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            PlatformMail::MAILER => ['sometimes', 'nullable', 'in:smtp,log,ses,postmark,resend,sendmail'],
            PlatformMail::HOST => ['sometimes', 'nullable', 'string'],
            PlatformMail::PORT => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            PlatformMail::USERNAME => ['sometimes', 'nullable', 'string'],
            PlatformMail::PASSWORD => ['sometimes', 'nullable', 'string'],
            PlatformMail::SCHEME => ['sometimes', 'nullable', 'in:tls,ssl'],
            PlatformMail::FROM_ADDRESS => ['sometimes', 'nullable', 'email'],
            PlatformMail::FROM_NAME => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $settings = PlatformSetting::current();

        foreach (self::PLAIN as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                $settings->put($key, is_scalar($value) ? (string) $value : '');
            }
        }

        if ($request->has(PlatformMail::PASSWORD)) {
            $value = $request->input(PlatformMail::PASSWORD);
            $settings->putSecret(PlatformMail::PASSWORD, is_string($value) ? $value : null);
        }

        $settings->save();

        return response()->json($this->present($settings));
    }

    /**
     * Send a test email to the given address using the saved settings, so the operator can
     * confirm delivery before relying on it. Applies the settings first (in case they were
     * just saved this request) and reports the transport error verbatim on failure.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate(['to' => ['required', 'email']]);
        $to = $request->string('to')->toString();

        PlatformMail::apply();

        try {
            Mail::raw('Correo de prueba de Imagina Reports. Si lo recibes, la configuración de correo funciona. ✅', function (Message $message) use ($to): void {
                $message->to($to)->subject('Imagina Reports — correo de prueba');
            });
        } catch (Throwable $e) {
            // 200 (not 422): the endpoint ran fine, the SEND failed — so the frontend receives
            // this as data and can show the transport's verbatim error instead of a generic one.
            return response()->json(['sent' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['sent' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformSetting $settings): array
    {
        $configDefault = config('mail.default');
        $configDefault = is_string($configDefault) ? $configDefault : 'log';
        $mailer = $this->plain($settings, PlatformMail::MAILER);
        $effectiveMailer = $mailer !== '' ? $mailer : $configDefault;

        return [
            'mail_mailer' => $effectiveMailer,
            'mail_host' => $this->plain($settings, PlatformMail::HOST),
            'mail_port' => $this->plain($settings, PlatformMail::PORT),
            'mail_username' => $this->plain($settings, PlatformMail::USERNAME),
            'mail_scheme' => $this->plain($settings, PlatformMail::SCHEME),
            'mail_from_address' => $this->plain($settings, PlatformMail::FROM_ADDRESS),
            'mail_from_name' => $this->plain($settings, PlatformMail::FROM_NAME),
            'mail_password_set' => $settings->hasSecret(PlatformMail::PASSWORD),
            // Whether outbound mail will actually send (anything other than the 'log' driver).
            'mail_sends' => $effectiveMailer !== 'log',
        ];
    }

    private function plain(PlatformSetting $settings, string $key): string
    {
        $value = $settings->get($key);

        return is_string($value) ? $value : '';
    }
}
