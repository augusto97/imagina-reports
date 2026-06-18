<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockValidationException;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared rule that validates a request's `blocks` field against the block schema
 * (CLAUDE.md §10.2) so the editor and AI builder can never persist a malformed
 * layout (§10.6). Surfaces each block error under the `blocks` key.
 */
trait ValidatesBlocks
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->input('blocks'))) {
                return;
            }

            try {
                app(BlocksValidator::class)->validate($this->input('blocks'));
            } catch (BlockValidationException $exception) {
                foreach ($exception->errors as $error) {
                    $validator->errors()->add('blocks', $error);
                }
            }
        });
    }
}
