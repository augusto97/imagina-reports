<?php

declare(strict_types=1);

namespace App\Reports\Blocks;

use RuntimeException;

final class BlockValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid block layout: '.implode(' ', $errors));
    }
}
