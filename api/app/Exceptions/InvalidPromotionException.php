<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidPromotionException extends HttpException
{
    public function __construct(string $message = 'That promotion code is not valid for this booking.')
    {
        parent::__construct(422, $message);
    }
}
