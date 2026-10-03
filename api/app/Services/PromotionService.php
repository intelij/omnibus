<?php

namespace App\Services;

use App\Exceptions\InvalidPromotionException;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\Trip;
use Illuminate\Support\Str;

class PromotionService
{
    /**
     * Resolve + validate a promo code against a trip/booking context.
     * Throws InvalidPromotionException with a reason on failure.
     */
    public function resolve(string $code, Trip $trip, float $subtotal, ?string $customerKey = null): Promotion
    {
        $code = Str::upper(trim($code));

        $promo = Promotion::where('code', $code)->first()
            ?? throw new InvalidPromotionException('Promotion code not found.');

        if ($promo->status !== 'active') {
            throw new InvalidPromotionException('This promotion is no longer active.');
        }
        if ($promo->starts_at && $promo->starts_at->isFuture()) {
            throw new InvalidPromotionException('This promotion has not started yet.');
        }
        if ($promo->ends_at && $promo->ends_at->isPast()) {
            throw new InvalidPromotionException('This promotion has expired.');
        }
        if ($promo->min_spend !== null && $subtotal < (float) $promo->min_spend) {
            throw new InvalidPromotionException('This promotion requires a minimum spend of '.$promo->min_spend.'.');
        }

        $constraints = $promo->constraints ?? [];
        $routeIds = $constraints['route_ids'] ?? null;
        if ($routeIds && ! in_array($trip->route_id, $routeIds, true)) {
            throw new InvalidPromotionException('This promotion is not valid for this route.');
        }
        $dates = $constraints['dates'] ?? null;
        if ($dates && ! in_array($trip->departure_at->toDateString(), $dates, true)) {
            throw new InvalidPromotionException('This promotion is not valid on this date.');
        }

        if ($promo->usage_limit !== null
            && $promo->redemptions()->count() >= $promo->usage_limit) {
            throw new InvalidPromotionException('This promotion has been fully redeemed.');
        }
        if ($promo->per_customer_limit !== null && $customerKey) {
            $used = PromotionRedemption::where('promotion_id', $promo->id)
                ->where('customer_key', $customerKey)
                ->count();
            if ($used >= $promo->per_customer_limit) {
                throw new InvalidPromotionException('You have already used this promotion.');
            }
        }

        return $promo;
    }

    /**
     * Record redemption inside the booking transaction — the row count on
     * promotion_redemptions is the usage-limit enforcer (checked under the
     * trip/booking lock in BookingService).
     */
    public function redeem(Promotion $promo, Booking $booking, float $amount, ?string $customerKey): PromotionRedemption
    {
        // Lock the promotion row and re-check the cap — two bookings on
        // different trips hold different seat locks, so the earlier count
        // check alone could both read "0 used" and double-redeem.
        $locked = Promotion::lockForUpdate()->findOrFail($promo->id);
        if ($locked->usage_limit !== null
            && $locked->redemptions()->count() >= $locked->usage_limit) {
            throw new InvalidPromotionException('This promotion has been fully redeemed.');
        }

        return PromotionRedemption::create([
            'tenant_id' => $booking->tenant_id,
            'promotion_id' => $promo->id,
            'booking_id' => $booking->id,
            'code' => $promo->code,
            'customer_key' => $customerKey,
            'amount' => $amount,
        ]);
    }

    public function reverseFor(Booking $booking): void
    {
        PromotionRedemption::where('booking_id', $booking->id)->delete();
    }
}
