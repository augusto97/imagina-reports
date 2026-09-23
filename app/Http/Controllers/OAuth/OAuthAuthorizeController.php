<?php

declare(strict_types=1);

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\McpAbility;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\OAuth\AuthorizationCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The consent screen of the OAuth flow: a logged-in owner/admin sees which assistant is
 * asking, picks what it may do, and approves or denies. Approval hands the assistant a
 * short-lived code it exchanges (with its PKCE verifier) for a token.
 */
final class OAuthAuthorizeController extends Controller
{
    /** Checked by default when the assistant doesn't ask for specific permissions. */
    private const DEFAULT_OFF = [McpAbility::ReportsSend, McpAbility::Delete];

    public function __construct(private readonly AuthorizationCodes $codes) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $params = $this->validated($request);
        if (is_string($params)) {
            return $this->failure($params);
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // The admin SPA logs the person in and sends them straight back here.
            return redirect('/admin?redirect='.rawurlencode($request->getRequestUri()));
        }

        if ($denied = $this->refusal($user)) {
            return $this->failure($denied);
        }

        $requested = self::requestedAbilities($params['scope']);

        return response()->view('oauth.authorize', [
            'client' => $params['client'],
            'user' => $user,
            'params' => $params,
            'abilities' => array_map(static fn (McpAbility $ability): array => [
                'value' => $ability->value,
                'label' => $ability->label(),
                'checked' => $ability === McpAbility::Read || ($requested !== null
                    ? in_array($ability->value, $requested, true)
                    : ! in_array($ability, self::DEFAULT_OFF, true)),
                'locked' => $ability === McpAbility::Read,
            ], McpAbility::cases()),
        ]);
    }

    public function decide(Request $request): Response|RedirectResponse
    {
        $params = $this->validated($request);
        if (is_string($params)) {
            return $this->failure($params);
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return redirect('/admin');
        }

        if ($denied = $this->refusal($user)) {
            return $this->failure($denied);
        }

        if ($request->input('decision') !== 'approve') {
            return $this->back($params['redirect_uri'], ['error' => 'access_denied', 'state' => $params['state']]);
        }

        $abilities = [McpAbility::Read->value];
        foreach ((array) $request->input('abilities', []) as $ability) {
            if (is_string($ability) && McpAbility::tryFrom($ability) !== null) {
                $abilities[] = $ability;
            }
        }

        $code = $this->codes->issue(
            $params['client']->client_id,
            $user->id,
            $params['redirect_uri'],
            $params['code_challenge'],
            array_values(array_unique($abilities)),
        );

        return $this->back($params['redirect_uri'], ['code' => $code, 'state' => $params['state']]);
    }

    /**
     * The authorization request, checked. A string is a reason to show the person (never a
     * redirect: with an unverified client or redirect_uri we must not send them anywhere).
     *
     * @return array{client: OAuthClient, redirect_uri: string, code_challenge: string, state: string|null, scope: string|null}|string
     */
    private function validated(Request $request): array|string
    {
        $clientId = $request->input('client_id');
        $redirect = $request->input('redirect_uri');

        $client = is_string($clientId) ? OAuthClient::query()->where('client_id', $clientId)->first() : null;
        if ($client === null) {
            return 'La aplicación que intenta conectarse no está registrada. Vuelve a añadir el conector desde tu asistente.';
        }

        if (! is_string($redirect) || ! $client->allowsRedirect($redirect)) {
            return 'La dirección de retorno no coincide con la registrada por la aplicación.';
        }

        if ($request->input('response_type') !== 'code') {
            return 'Solicitud no válida: response_type debe ser «code».';
        }

        $challenge = $request->input('code_challenge');
        if (! is_string($challenge) || preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge) !== 1 || $request->input('code_challenge_method') !== 'S256') {
            return 'Solicitud no válida: se requiere PKCE con S256.';
        }

        $state = $request->input('state');
        $scope = $request->input('scope');

        return [
            'client' => $client,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'state' => is_string($state) ? $state : null,
            'scope' => is_string($scope) && trim($scope) !== '' ? $scope : null,
        ];
    }

    private function refusal(User $user): ?string
    {
        if ($user->is_platform_admin || $user->agency_id === null) {
            return 'Los asistentes se conectan a una agencia. Entra con una cuenta de agencia para autorizarlo.';
        }

        if (! $user->role->isPrivileged()) {
            return 'Solo un propietario o administrador de la agencia puede conectar asistentes de IA.';
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function requestedAbilities(?string $scope): ?array
    {
        if ($scope === null) {
            return null;
        }

        $known = array_values(array_filter(
            preg_split('/\s+/', trim($scope)) ?: [],
            static fn (string $item): bool => McpAbility::tryFrom($item) !== null,
        ));

        return $known === [] ? null : $known;
    }

    /**
     * @param  array<string, string|null>  $query
     */
    private function back(string $redirectUri, array $query): RedirectResponse
    {
        $query = array_filter($query, static fn (?string $value): bool => $value !== null);
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->away($redirectUri.$separator.http_build_query($query));
    }

    private function failure(string $message): Response
    {
        return response()->view('oauth.error', ['message' => $message], 400);
    }
}
