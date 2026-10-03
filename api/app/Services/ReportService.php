<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-scoped reporting. All queries run through tenant-scoped models —
 * platform aggregates call this from platform context with withoutTenantScope.
 */
class ReportService
{
    public function dashboard(): array
    {
        $today = now()->startOfDay();

        return [
            'today' => [
                'trips' => Trip::whereDate('departure_at', $today)->count(),
                'bookings' => Booking::whereDate('created_at', $today)->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_PENDING_PAYMENT])->count(),
                'revenue' => (float) Payment::where('status', Payment::STATUS_PAID)->whereDate('confirmed_at', $today)->sum('amount'),
                'pending_cash' => (float) Payment::where('method', 'cash')->where('status', Payment::STATUS_PENDING)->sum('amount'),
            ],
            'upcoming_departures' => Trip::with('route')
                ->where('departure_at', '>=', now())
                ->where('status', Trip::STATUS_SCHEDULED)
                ->orderBy('departure_at')->limit(10)->get(['id', 'route_id', 'departure_at', 'status']),
        ];
    }

    public function revenue(CarbonInterface $from, CarbonInterface $to): array
    {
        $payments = Payment::where('status', Payment::STATUS_PAID)
            ->whereBetween('confirmed_at', [$from, $to])
            ->select('method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'), 'currency')
            ->groupBy('method', 'currency')
            ->get();

        return [
            'by_method' => $payments,
            'total' => (float) $payments->sum('total'),
            'refunded' => (float) \App\Models\Refund::where('status', 'processed')
                ->whereBetween('processed_at', [$from, $to])->sum('amount'),
        ];
    }

    public function occupancy(CarbonInterface $from, CarbonInterface $to): array
    {
        return Trip::whereBetween('departure_at', [$from, $to])
            ->whereIn('status', [Trip::STATUS_SCHEDULED, Trip::STATUS_DEPARTED, Trip::STATUS_IN_TRANSIT, Trip::STATUS_ARRIVED, Trip::STATUS_COMPLETED])
            ->withCount([
                'seats as total_seats' => fn ($q) => $q->whereNotIn('type', \App\Models\SeatLayoutSeat::NON_BOOKABLE_TYPES),
            ])
            ->with(['route:id,name'])
            ->get()
            ->map(function (Trip $trip) {
                $booked = \App\Models\TripSeatLeg::where('trip_id', $trip->id)->where('state', 'booked')->distinct('trip_seat_id')->count('trip_seat_id');
                return [
                    'trip_id' => $trip->id,
                    'route' => $trip->route?->name,
                    'departure_at' => $trip->departure_at,
                    'seats' => $trip->total_seats,
                    'booked' => $booked,
                    'occupancy' => $trip->total_seats ? round($booked / $trip->total_seats * 100, 1) : 0,
                ];
            })->all();
    }

    public function agentPerformance(CarbonInterface $from, CarbonInterface $to): array
    {
        return Booking::whereBetween('bookings.created_at', [$from, $to])
            ->whereNotNull('user_id')
            ->join('users', 'users.id', '=', 'bookings.user_id')
            ->select('users.name', 'bookings.user_id', DB::raw('count(*) as bookings'), DB::raw('sum(bookings.total) as revenue'))
            ->groupBy('bookings.user_id', 'users.name')
            ->orderByDesc('revenue')
            ->get();
    }

    public function paymentFailures(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'failed' => Payment::where('status', Payment::STATUS_FAILED)->whereBetween('created_at', [$from, $to])->count(),
            'expired' => Payment::where('status', Payment::STATUS_EXPIRED)->whereBetween('created_at', [$from, $to])->count(),
            'pending' => Payment::whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])->count(),
        ];
    }
}
