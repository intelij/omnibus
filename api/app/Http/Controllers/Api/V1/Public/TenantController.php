<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Payments\GatewayManager;

class TenantController extends Controller
{
    /** Public tenant branding + enabled payment methods + policies. */
    public function show(GatewayManager $gateways)
    {
        $tenant = tenant();

        $enabled = collect($tenant->setting('payment_methods', ['cash']))
            ->map(fn ($key) => collect($gateways->available())->firstWhere('key', $key))
            ->filter()
            ->values();

        return response()->json([
            'name' => $tenant->trading_name,
            'legal_name' => $tenant->legal_name,
            'slug' => $tenant->slug,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
            'locale' => $tenant->locale,
            'branding' => $tenant->branding,
            'contact' => [
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'website' => $tenant->website,
            ],
            'payment_methods' => $enabled,
            'rules' => [
                'seat_hold_minutes' => $tenant->bookingRule('seat_hold_minutes'),
                'max_seats_per_booking' => $tenant->bookingRule('max_seats_per_booking'),
                'cancellation_policy' => $tenant->bookingRule('cancellation_policy'),
                'passenger_fields' => $tenant->bookingRule('passenger_fields'),
            ],
        ]);
    }
}
