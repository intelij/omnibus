<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(public Booking $booking, public ?float $refundAmount = null) {}

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

        $mail = (new MailMessage)
            ->subject("{$tenant->trading_name}: Booking {$this->booking->booking_reference} cancelled")
            ->line("Your booking {$this->booking->booking_reference} has been cancelled.")
            ->line('Reason: '.($this->booking->cancellation_reason ?? '—'));

        if ($this->refundAmount !== null && $this->refundAmount > 0) {
            $mail->line("A refund of {$this->booking->currency} {$this->refundAmount} will be processed to your original payment method.");
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'booking.cancelled', 'booking_id' => $this->booking->id];
    }
}
