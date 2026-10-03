<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Passenger PII lifecycle: export (access right) and anonymization
 * (erasure right + scheduled retention). Financial records — amounts,
 * references, payment states, tickets — are retained for legal/financial
 * reasons; only identifying fields are masked.
 */
class PrivacyService
{
    public function export(Booking $booking): array
    {
        $booking->loadMissing(['passengers', 'seats', 'tickets', 'payments', 'trip.route', 'boardingStop', 'dropoffStop']);

        return [
            'booking' => [
                'reference' => $booking->booking_reference,
                'status' => $booking->status,
                'created_at' => $booking->created_at,
                'trip' => [
                    'route' => $booking->trip?->route?->name,
                    'departure' => $booking->trip?->departure_at,
                    'boarding' => $booking->boardingStop?->name,
                    'dropoff' => $booking->dropoffStop?->name,
                ],
                'contact' => [
                    'name' => $booking->contact_name,
                    'email' => $booking->contact_email,
                    'phone' => $booking->contact_phone,
                ],
                'fares' => [
                    'currency' => $booking->currency,
                    'subtotal' => $booking->subtotal,
                    'discount' => $booking->discount_amount,
                    'total' => $booking->total,
                    'promotion' => $booking->promotion_code,
                ],
            ],
            'passengers' => $booking->passengers->map(fn ($p) => [
                'name' => $p->fullName(),
                'email' => $p->email,
                'phone' => $p->phone,
                'id_document' => $p->id_document,
                'date_of_birth' => $p->date_of_birth,
                'type' => $p->passenger_type,
            ]),
            'seats' => $booking->seats->map(fn ($s) => ['label' => $s->seat_label, 'status' => $s->status, 'fare' => $s->fare]),
            'tickets' => $booking->tickets->map(fn ($t) => [
                'seat' => $t->seat_label, 'status' => $t->status, 'issued_at' => $t->created_at,
            ]),
            'payments' => $booking->payments->map(fn ($p) => [
                'method' => $p->method, 'status' => $p->status,
                'amount' => $p->amount, 'currency' => $p->currency,
                'created_at' => $p->created_at,
            ]),
        ];
    }

    /**
     * Anonymize identifying fields on a booking + its passengers. Financial
     * records and ticket validity are preserved — refunds/audits still work.
     */
    public function anonymize(Booking $booking, ?int $actorId = null): void
    {
        DB::transaction(function () use ($booking, $actorId) {
            $booking->update([
                // contact_name/contact_phone are NOT NULL — placeholder wins.
                'contact_name' => 'REDACTED',
                'contact_phone' => 'REDACTED',
                'contact_email' => null,
                'pii_anonymized_at' => now(),
            ]);

            $booking->passengers()->update([
                'first_name' => 'REDACTED',
                'last_name' => '',
                'email' => null,
                'phone' => null,
                'id_document' => null,
                'date_of_birth' => null,
            ]);

            // Linked directory entries are anonymized only when this was the
            // passenger's last live booking — shared PII can't leak elsewhere.
            $booking->passengers->load('passenger')->each(function ($bp) {
                $dir = $bp->passenger;
                if (! $dir) {
                    return;
                }
                $otherLive = $dir->bookingPassengers()
                    ->whereHas('booking', fn ($q) => $q->whereNotIn('id', [$bp->booking_id])
                        ->whereNull('pii_anonymized_at'))
                    ->exists();
                if (! $otherLive) {
                    $dir->update([
                        'first_name' => 'REDACTED', 'last_name' => '',
                        'email' => null, 'phone' => null, 'id_document' => null,
                    ]);
                }
            });

            Audit::record('privacy.anonymized', $booking, [], $actorId, $booking->tenant_id);
        });
    }

    /** Scheduled retention sweep per tenant rule `pii_retention_days`. */
    public function anonymizeExpired(int $limit = 200): int
    {
        $count = 0;
        Booking::withoutTenantScope()
            ->with('tenant')
            ->whereNull('pii_anonymized_at')
            ->whereIn('status', [Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED, Booking::STATUS_REFUNDED])
            ->get()
            ->filter(fn ($b) => $b->created_at->diffInDays(now()) > (int) $b->tenant->bookingRule('pii_retention_days', 730))
            ->take($limit)
            ->each(function ($b) use (&$count) {
                $this->anonymize($b);
                $count++;
            });

        return $count;
    }
}
