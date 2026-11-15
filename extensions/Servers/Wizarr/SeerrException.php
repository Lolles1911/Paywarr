<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Exception;

/** Seerr refused a call or could not be reached. $status is Seerr's HTTP status (0: not reached). */
class SeerrException extends Exception
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $seerrMessage = '')
    {
        parent::__construct($message);
    }
}
