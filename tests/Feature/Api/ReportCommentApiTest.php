<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportCommentApiTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::factory()->create();
        Sanctum::actingAs(User::factory()->create(['agency_id' => $this->agency->id]));
    }

    public function test_it_adds_an_internal_note_and_a_client_comment(): void
    {
        $report = Report::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson("/api/v1/reports/{$report->id}/comments", ['body' => 'Revisar antes de enviar', 'visibility' => 'internal'])
            ->assertCreated()
            ->assertJsonPath('visibility', 'internal');

        $this->postJson("/api/v1/reports/{$report->id}/comments", ['body' => 'Gracias por la confianza', 'visibility' => 'client'])
            ->assertCreated()
            ->assertJsonPath('visibility', 'client');

        $this->getJson("/api/v1/reports/{$report->id}/comments")->assertOk()->assertJsonCount(2);
    }

    public function test_it_rejects_an_invalid_visibility(): void
    {
        $report = Report::factory()->create(['agency_id' => $this->agency->id]);

        $this->postJson("/api/v1/reports/{$report->id}/comments", ['body' => 'x', 'visibility' => 'public'])
            ->assertJsonValidationErrorFor('visibility');
    }

    public function test_it_deletes_a_comment(): void
    {
        $report = Report::factory()->create(['agency_id' => $this->agency->id]);
        $comment = ReportComment::factory()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id]);

        $this->deleteJson("/api/v1/comments/{$comment->id}")->assertNoContent();
        $this->assertDatabaseMissing('ir_report_comments', ['id' => $comment->id]);
    }

    public function test_only_client_comments_reach_the_public_report(): void
    {
        $report = Report::factory()->create([
            'agency_id' => $this->agency->id,
            'resolved_blocks' => [
                'blocks' => [['id' => 'c1', 'type' => 'comments', 'binding' => null, 'props' => [], 'style' => []]],
                'data' => ['c1' => []],
            ],
        ]);
        ReportComment::factory()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'body' => 'Nota interna', 'visibility' => 'internal']);
        ReportComment::factory()->client()->create(['agency_id' => $this->agency->id, 'report_id' => $report->id, 'body' => 'Mensaje al cliente']);

        $this->getJson("/api/v1/public/reports/{$report->public_token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.c1')
            ->assertJsonPath('data.c1.0.body', 'Mensaje al cliente');
    }

    public function test_it_cannot_comment_on_another_agencys_report(): void
    {
        $report = Report::factory()->create(['agency_id' => Agency::factory()->create()->id]);

        $this->postJson("/api/v1/reports/{$report->id}/comments", ['body' => 'x', 'visibility' => 'internal'])->assertNotFound();
    }
}
