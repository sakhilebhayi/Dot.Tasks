<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A restore the rules do not allow (a parent task or the board is still gone).
 * It is a ValidationException under the `restore` key, so the Removed page shows
 * it as an ordinary error message.
 */
class RestoreRefused extends ValidationException
{
    public static function because(string $message): self
    {
        return self::withMessages(['restore' => $message]);
    }
}
