<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SeatUnavailableException extends HttpException
{
    public function __construct(string $message = 'One or more selected seats are no longer available.')
    {
        parent::__construct(409, $message);
    }
}
