<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\PromotionService;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    /** Validate a promo code for a trip (returns discount preview). */
    public function validateCode(Request $request, PromotionService $promotions)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trip = Trip::findOrFail($data['trip_id']);
        $subtotal = (float) ($data['subtotal'] ?? $trip->base_fare);

        $promo = $promotions->resolve($data['code'], $trip, $subtotal, $request->input('customer'));

        return response()->json([
            'code' => $promo->code,
            'name' => $promo->name,
            'type' => $promo->type,
            'value' => (float) $promo->value,
            'discount' => $promo->discountFor($subtotal),
        ]);
    }
}
