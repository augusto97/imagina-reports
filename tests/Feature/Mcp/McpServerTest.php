<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->user = User::factory()->create(['agency_id' => $this->agency->id]);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities = ['read', 'clients:write']): string
    {
        return $this->user->createToken('Test', $abilities)->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TestResponse<JsonResponse>
     */
    private function rpc(string $token, string $method, array $params = [], int $id = 1): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return TestResponse<JsonResponse>
     */
    private function tool(string $token, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    public function test_unauthenticated_requests_get_the_oauth_discovery_pointer(): void
    {
        $response = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $response->assertUnauthorized();
        $this->assertStringContainsString('/.well-known/oauth-protected-resource/api/v1/mcp', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_initialize_negotiates_the_protocol_version(): void
    {
        $this->rpc($this->token(), 'initialize', ['protocolVersion' => '2025-03-26'])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'imagina-reports');
    }

    public function test_notifications_are_accepted_without_a_body(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202);
    }

    public function test_tools_are_filtered_by_the_token_abilities(): void
    {
        $readOnly = collect($this->rpc($this->token(['read']), 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertContains('list_clients', $readOnly);
        $this->assertNotContains('propose_create_client', $readOnly);
        $this->assertNotContains('apply_proposal', $readOnly);

        $writer = collect($this->rpc($this->token(), 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertContains('propose_create_client', $writer);
        $this->assertContains('apply_proposal', $writer);
        $this->assertNotContains('propose_send_report', $writer);
    }

    public function test_read_tools_go_through_the_api_scoped_to_the_agency(): void
    {
        Client::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Acme']);
        Client::factory()->create(['agency_id' => Agency::factory()->create()->id, 'name' => 'Other agency']);

        $response = $this->tool($this->token(), 'list_clients')->assertOk();

        $response->assertJsonPath('result.isError', false);
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('Acme', $text);
        $this->assertStringNotContainsString('Other agency', $text);
    }

    public function test_a_proposal_changes_nothing_until_applied(): void
    {
        $token = $this->token();

        $proposal = $this->tool($token, 'propose_create_client', ['name' => 'Nueva SA'])->assertOk();
        $proposalId = $proposal->json('result.structuredContent.proposal_id');
        $this->assertIsString($proposalId);
        $this->assertDatabaseMissing('ir_clients', ['name' => 'Nueva SA']);

        $this->tool($token, 'apply_proposal', ['proposal_id' => $proposalId])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
        $this->assertDatabaseHas('ir_clients', ['name' => 'Nueva SA', 'agency_id' => $this->agency->id]);
        $this->assertDatabaseHas('ir_audit_logs', ['action' => 'mcp.applied', 'agency_id' => $this->agency->id]);

        // Single use.
        $this->tool($token, 'apply_proposal', ['proposal_id' => $proposalId])->assertJsonPath('result.isError', true);
        $this->assertSame(1, Client::query()->withoutGlobalScopes()->where('name', 'Nueva SA')->count());
    }

    public function test_a_proposal_cannot_be_applied_with_another_token(): void
    {
        $proposalId = $this->tool($this->token(), 'propose_create_client', ['name' => 'Nueva SA'])
            ->json('result.structuredContent.proposal_id');

        $this->tool($this->token(), 'apply_proposal', ['proposal_id' => $proposalId])
            ->assertJsonPath('result.isError', true);
        $this->assertDatabaseMissing('ir_clients', ['name' => 'Nueva SA']);
    }

    public function test_tools_outside_the_token_abilities_are_refused(): void
    {
        $this->tool($this->token(['read']), 'propose_create_client', ['name' => 'X'])
            ->assertJsonPath('result.isError', true);
    }

    public function test_tokens_cannot_call_the_rest_api_directly(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/v1/clients')
            ->assertForbidden();
    }

    public function test_only_owners_and_admins_manage_tokens(): void
    {
        $this->actingAs($this->user);
        $this->postJson('/api/v1/api-tokens', ['name' => 'Claude Code', 'abilities' => ['reports:write']])
            ->assertCreated()
            ->assertJsonStructure(['plain_text_token', 'token' => ['id', 'name', 'abilities']]);
        $this->getJson('/api/v1/api-tokens')->assertOk()->assertJsonCount(1, 'tokens');

        $collaborator = User::factory()->create(['agency_id' => $this->agency->id, 'role' => UserRole::Collaborator]);
        $this->actingAs($collaborator);
        $this->postJson('/api/v1/api-tokens', ['name' => 'Mío', 'abilities' => ['read']])->assertForbidden();
        $this->getJson('/api/v1/api-tokens')->assertForbidden();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $token = $this->token();
        $id = $this->user->tokens()->firstOrFail()->id;

        $this->actingAs($this->user);
        $this->deleteJson("/api/v1/api-tokens/{$id}")->assertNoContent();

        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }
}
