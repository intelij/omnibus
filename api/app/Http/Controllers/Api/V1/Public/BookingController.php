<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\TripResource;
use App\Models\Booking;
use App\Notifications\BookingCancelled;
use App\Notifications\PaymentInstructions;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private PaymentService $payments,
    ) {}

    public function store(StoreBookingRequest $request)
    {
        $data = $request->validated();
        $data['idempotency_key'] = $request->header('Idempotency-Key');

        $booking = $this->bookings->create($data);

        $payment = null;
        if (! empty($data['payment_method'])) {
            $payment = $this->payments->initiate($booking, $data['payment_method'], $data['idempotency_key']);
            $this->notifyPaymentInstructions($booking->fresh(), $payment);
        }

        return (new BookingResource($booking->fresh(['passengers', 'seats', 'trip.route.stops', 'boardingStop', 'dropoffStop', 'latestPayment'])))
            ->response()->setStatusCode(201);
    }

    public function show(string $reference, Request $request)
    {
        $booking = $this->findForGuest($reference, $request);

        return new BookingResource($booking->load([
            'passengers', 'seats', 'trip.route.stops', 'trip.bus',
            'boardingStop', 'dropoffStop', 'latestPayment', 'tickets',
        ]));
    }

    public function pay(string $reference, Request $request)
    {
        $data = $request->validate(['method' => ['required', 'in:cash,card,ecocash,inbucks']]);

        $booking = $this->findForGuest($reference, $request);
        $payment = $this->payments->initiate($booking, $data['method'], $request->header('Idempotency-Key'));
        $this->notifyPaymentInstructions($booking->fresh(), $payment);

        return response()->json([
            'payment' => [
                'id' => $payment->id,
                'method' => $payment->method,
                'status' => $payment->status,
                'instructions' => $payment->instructions,
                'initiation' => $payment->metadata['initiation'] ?? null,
            ],
        ], 201);
    }

    public function cancel(string $reference, Request $request)
    {
        $booking = $this->findForGuest($reference, $request);

        if (! $booking->isCancellable()) {
            throw ValidationException::withMessages(['booking' => 'This booking can no longer be cancelled.']);
        }

        $refundDue = $this->bookings->refundDue($booking);
        $booking = $this->bookings->cancel($booking, null, $request->input('reason', 'Cancelled by passenger'));

        if ($refundDue > 0 && $booking->paidPayment) {
            $this->payments->refund($booking->paidPayment, $refundDue, 'Customer cancellation', null);
        }

        Notification::route('mail', $booking->contact_email)->notify(new BookingCancelled($booking, $refundDue));

        return new BookingResource($booking);
    }

    public function tickets(string $reference, Request $request)
    {
        $booking = $this->findForGuest($reference, $request);

        if ($booking->tickets->isEmpty()) {
            app(TicketService::class)->issueFor($booking);
        }

        return TicketResource::collection($booking->tickets()->with('booking.trip.route')->get())
            ->additional([
                'booking_reference' => $booking->booking_reference,
                'operator' => $booking->tenant->trading_name,
                'branding' => $booking->tenant->branding,
                'trip' => new TripResource($booking->trip->load('route.stops')),
            ]);
    }

    /**
     * Guest retrieval: booking reference + matching contact detail.
     * This is the booking-management auth boundary for guests.
     */
    private function findForGuest(string $reference, Request $request): Booking
    {
        $booking = Booking::where('booking_reference', $reference)->firstOrFail();

        $key = $request->query('email') ?? $request->input('email') ?? $request->query('phone');
        if (! $key || ! in_array($key, array_filter([$booking->contact_email, $booking->contact_phone]), true)) {
            abort(403, 'Provide the email or phone used at booking.');
        }

        return $booking;
    }

    private function notifyPaymentInstructions(Booking $booking, $payment): void
    {
        if ($booking->contact_email) {
            Notification::route('mail', $booking->contact_email)->notify(new PaymentInstructions($booking, $payment));
        }
    }
}
