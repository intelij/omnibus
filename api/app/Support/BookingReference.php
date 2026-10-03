<?php

namespace App\Support;

use App\Models\Booking;
use Illuminate\Support\Str;

class BookingReference
{
    /**
     * e.g. TH-7F4K92 — unique, non-sequential, human-safe alphabet.
     */
    public static function generate(): string
    {
        $alphabet = config('transithub.reference_alphabet');
        $max = strlen($alphabet) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $reference = 'TH-'.$code;
        } while (Booking::withoutTenantScope()->where('booking_reference', $reference)->exists());

        return $reference;
    }

    public static function qrToken(): string
    {
        return Str::random(48);
    }
}
