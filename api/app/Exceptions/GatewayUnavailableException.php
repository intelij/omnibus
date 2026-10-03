<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class GatewayUnavailableException extends HttpException
{
    public function __construct(string $gateway)
    {
        parent::__construct(503, "Payment method '{$gateway}' is not configured for this operator.");
    }
}
