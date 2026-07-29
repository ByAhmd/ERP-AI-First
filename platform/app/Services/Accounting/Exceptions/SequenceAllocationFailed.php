<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use RuntimeException;

final class SequenceAllocationFailed extends RuntimeException
{
    public static function outsideTransaction(string $key): self
    {
        return new self(sprintf(
            'Refusing to allocate a [%s] number outside a transaction. The number must share '
            .'the fate of the document it identifies, or a failed save leaves a permanent gap '
            .'in the series.',
            $key,
        ));
    }
}
