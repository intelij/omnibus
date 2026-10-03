<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidStateTransition extends HttpException
{
    public function __construct(string $from, string $to, string $resource = 'resource')
    {
        parent::__construct(422, "Cannot transition {$resource} from '{$from}' to '{$to}'.");
    }
}
