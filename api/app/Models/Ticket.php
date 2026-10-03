<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use BelongsToTenant;

    public const STATUS_VALID = 'valid';

    public const STATUS_USED = 'used';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'tenant_id', 'booking_id', 'booking_passenger_id', 'trip_seat_id',
        'passenger_name', 'seat_label', 'qr_token', 'status',
        'issued_at', 'used_at', 'used_by',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }

    public function tripSeat(): BelongsTo
    {
        return $this->belongsTo(TripSeat::class);
    }

    /** Signed QR payload: verify server-side, never trust client data. */
    public function qrPayload(): string
    {
        $payload = "{$this->id}|{$this->booking->booking_reference}|{$this->qr_token}";
        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return base64_encode("{$payload}|{$signature}");
    }

    public static function decodeQrPayload(string $encoded): ?array
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }
        [$id, $reference, $token, $signature] = $parts;
        $expected = hash_hmac('sha256', "{$id}|{$reference}|{$token}", config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return ['id' => (int) $id, 'reference' => $reference, 'token' => $token];
    }
}
