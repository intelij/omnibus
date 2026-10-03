<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Notifications\BookingConfirmed;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings, private PaymentService $payments) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Booking::class);

        $bookings = Booking::with(['trip.route:id,origin,destination', 'latestPayment'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->trip_id, fn ($q, $v) => $q->where('trip_id', $v))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($q2) => $q2
                ->where('booking_reference', 'like', "%{$v}%")
                ->orWhere('contact_name', 'like', "%{$v}%")
                ->orWhere('contact_email', 'like', "%{$v}%")
                ->orWhere('contact_phone', 'like', "%{$v}%")))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return BookingResource::collection($bookings);
    }

    /** Agent-assisted booking creation. */
    public function store(StoreBookingRequest $request)
    {
        $this->authorize('create', Booking::class);

        $data = $request->validated();
        $data['idempotency_key'] = $request->header('Idempotency-Key');

        $booking = $this->bookings->create($data, $request->user());

        $payment = null;
        if (! empty($data['payment_method'])) {
            $payment = $this->payments->initiate($booking, $data['payment_method'], $data['idempotency_key']);
        }

        return (new BookingResource($booking->fresh(['passengers', 'seats', 'trip.route.stops', 'latestPayment'])))
            ->response()->setStatusCode(201);
    }

    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        return new BookingResource($booking->load([
            'passengers', 'seats.tripSeat', 'trip.route.stops', 'trip.bus',
            'boardingStop', 'dropoffStop', 'payments.transactions', 'tickets', 'events',
        ]));
    }

    public function update(Booking $booking, Request $request)
    {
        $this->authorize('update', $booking);

        $data = $request->validate([
            'contact_name' => ['sometimes', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['sometimes', 'string', 'max:30'],
        ]);

        $booking->update($data);
        $booking->logEvent('updated', $request->user()->id, 'user', $data);

        return new BookingResource($booking);
    }

    public function cancel(Booking $booking, Request $request)
    {
        $this->authorize('cancel', $booking);

        $refundDue = $this->bookings->refundDue($booking);
        $booking = $this->bookings->cancel($booking, $request->user(), $request->input('reason'));

        if ($refundDue > 0 && $booking->paidPayment) {
            $this->payments->refund($booking->paidPayment, $refundDue, $request->input('reason', 'Cancellation'), $request->user());
        }

        return new BookingResource($booking);
    }

    public function resendTicket(Booking $booking, Request $request)
    {
        $this->authorize('resendTicket', $booking);

        if ($booking->contact_email) {
            Notification::route('mail', $booking->contact_email)->notify(new BookingConfirmed($booking));
        }
        $booking->logEvent('ticket_resent', $request->user()->id, 'user');

        return response()->json(['message' => 'Ticket resent.']);
    }
}
