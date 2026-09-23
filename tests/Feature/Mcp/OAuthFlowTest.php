<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = str_repeat('a1b2c3d4', 6);
    }

    private function challenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    private function register(): string
    {
        $clientId = $this->postJson('/api/v1/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');

        $this->assertIsString($clientId);

        return $clientId;
    }

    /**
     * @return array<string, string>
     */
    private function authorizeParams(string $clientId): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ];
    }

    private function agencyUser(UserRole $role = UserRole::Owner): User
    {
        return User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'role' => $role]);
    }

    public function test_discovery_documents_point_at_this_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1/mcp'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('token_endpoint', url('/api/v1/oauth/token'));
    }

    public function test_registration_rejects_unsafe_redirects(): void
    {
        $this->postJson('/api/v1/oauth/register', ['redirect_uris' => ['http://evil.test/cb']])->assertStatus(400);
        $this->postJson('/api/v1/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])->assertStatus(400);
        $this->postJson('/api/v1/oauth/register', ['redirect_uris' => ['http://localhost:3334/cb']])->assertCreated();
    }

    public function test_guests_are_sent_to_log_in_first(): void
    {
        $query = http_build_query($this->authorizeParams($this->register()));

        $this->get("/oauth/authorize?{$query}")
            ->assertRedirect()
            ->assertRedirectContains('/admin?redirect=');
    }

    public function test_the_full_flow_issues_a_token_with_the_approved_abilities(): void
    {
        $user = $this->agencyUser();
        $clientId = $this->register();
        $params = $this->authorizeParams($clientId);

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($params))
            ->assertOk()
            ->assertSee('Claude');

        $redirect = $this->post('/oauth/authorize', $params + ['decision' => 'approve', 'abilities' => ['reports:write']])
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertIsString($redirect);
        $this->assertStringStartsWith(self::REDIRECT.'?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state'] ?? null);
        $code = $query['code'] ?? null;
        $this->assertIsString($code);

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $this->verifier,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json('access_token');

        $this->assertIsString($token);
        $stored = $user->tokens()->firstOrFail();
        $this->assertSame(['read', 'reports:write'], $stored->abilities);

        // The code is single use.
        $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'code_verifier' => $this->verifier,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // And the token works against the MCP endpoint.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk();
    }

    public function test_a_wrong_pkce_verifier_is_rejected(): void
    {
        $user = $this->agencyUser();
        $clientId = $this->register();
        $params = $this->authorizeParams($clientId);

        $redirect = (string) $this->actingAs($user)->post('/oauth/authorize', $params + ['decision' => 'approve'])->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'] ?? '',
            'client_id' => $clientId,
            'code_verifier' => str_repeat('z', 50),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_denying_redirects_with_access_denied(): void
    {
        $params = $this->authorizeParams($this->register());

        $this->actingAs($this->agencyUser())->post('/oauth/authorize', $params + ['decision' => 'deny'])
            ->assertRedirect(self::REDIRECT.'?error=access_denied&state=xyz');
    }

    public function test_collaborators_and_unknown_redirects_are_refused(): void
    {
        $clientId = $this->register();
        $params = $this->authorizeParams($clientId);

        $this->actingAs($this->agencyUser(UserRole::Collaborator))
            ->get('/oauth/authorize?'.http_build_query($params))
            ->assertStatus(400);

        $params['redirect_uri'] = 'https://evil.test/cb';
        $this->actingAs($this->agencyUser())
            ->get('/oauth/authorize?'.http_build_query($params))
            ->assertStatus(400);
    }
}
