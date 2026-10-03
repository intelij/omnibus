<?php

namespace App\Payments;

use App\Exceptions\GatewayUnavailableException;
use App\Payments\Drivers\CardGateway;
use App\Payments\Drivers\CashGateway;
use App\Payments\Drivers\EcocashGateway;
use App\Payments\Drivers\InbucksGateway;

class GatewayManager
{
    private const DRIVERS = [
        'cash' => CashGateway::class,
        'card' => CardGateway::class,
        'ecocash' => EcocashGateway::class,
        'inbucks' => InbucksGateway::class,
    ];

    public function driver(string $key): PaymentGateway
    {
        $class = self::DRIVERS[strtolower($key)] ?? null;

        if (! $class) {
            throw new GatewayUnavailableException($key);
        }

        return app($class);
    }

    /** @return list<array{key:string,label:string,available:bool}> */
    public function available(): array
    {
        return collect(self::DRIVERS)->map(fn ($class, $key) => tap(app($class), fn ($d) => null))
            ->map(fn (PaymentGateway $d) => [
                'key' => $d->key(),
                'label' => $d->label(),
                'available' => $d->isAvailable(),
            ])->values()->all();
    }
}
