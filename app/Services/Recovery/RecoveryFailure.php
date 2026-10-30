<?php

namespace App\Services\Recovery;

use RuntimeException;

final class RecoveryFailure extends RuntimeException
{
    public function __construct()
    {
        // Never chain filesystem, SQL, crypto or private record diagnostics.
        parent::__construct('Recovery safeguards unavailable. Access or recovery remains blocked; contact the operator.');
    }
}
