<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportCommentRequest;
use App\Http\Resources\ReportCommentResource;
use App\Models\Report;
use App\Models\ReportComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Report comments/annotations (CLAUDE.md §11): internal team notes + client-visible
 * comments. The team sees both here; only `client` comments reach the public report.
 */
final class ReportCommentController extends Controller
{
    public function index(Report $report): AnonymousResourceCollection
    {
        return ReportCommentResource::collection($report->comments()->with('author')->get());
    }

    public function store(StoreReportCommentRequest $request, Report $report): JsonResponse
    {
        $comment = $report->comments()->create([
            ...$request->validated(),
            'author_user_id' => $request->user()?->id,
        ]);

        return ReportCommentResource::make($comment)->response()->setStatusCode(201);
    }

    public function destroy(ReportComment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json(null, 204);
    }
}
