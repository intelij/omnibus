<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        return array_filter([
            $this->booking->contact_email ? 'mail' : null,
            'database',
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->booking->tenant;
        $brand = $tenant->brandingValue('primary_color', '#0f766e');
        $trip = $this->booking->trip;

        return (new MailMessage)
            ->subject("{$tenant->trading_name}: Booking {$this->booking->booking_reference} confirmed")
            ->greeting('Your booking is confirmed')
            ->line("Operator: {$tenant->trading_name}")
            ->line("Route: {$trip->route->origin} → {$trip->route->destination}")
            ->line('Departure: '.$trip->departure_at->setTimezone($tenant->timezone)->format('d M Y H:i'))
            ->line('Seats: '.$this->booking->seats->pluck('seat_label')->implode(', '))
            ->line("Reference: {$this->booking->booking_reference}")
            ->line("Total paid: {$this->booking->currency} {$this->booking->total}")
            ->action('View ticket', url("/booking/{$this->booking->booking_reference}"))
            ->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('X-Brand-Color', $brand));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking.confirmed',
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->booking_reference,
        ];
    }
}
