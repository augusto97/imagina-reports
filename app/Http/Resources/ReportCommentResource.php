<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ReportCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        if (! $comment instanceof ReportComment) {
            throw new LogicException('ReportCommentResource expects a ReportComment.');
        }

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'visibility' => $comment->visibility->value,
            'author' => $comment->author?->name,
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
