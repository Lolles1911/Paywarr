<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Exception;

/**
 * Wizarr refused a call, could not be reached, or answered with something unusable.
 */
class WizarrException extends Exception
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** The thing we asked for is already gone. Often fine: deleting twice is not an error. */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
