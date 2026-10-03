<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentInstructions extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking, public Payment $payment) {}

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

        return (new MailMessage)
            ->subject("{$tenant->trading_name}: Complete payment for {$this->booking->booking_reference}")
            ->line('Your seats are reserved. Complete payment to confirm your booking.')
            ->line("Amount due: {$this->payment->currency} {$this->payment->amount}")
            ->line('Method: ' . ucfirst($this->payment->method))
            ->when($this->payment->instructions, fn ($m) => $m->line($this->payment->instructions))
            ->action('Complete booking', url("/booking/{$this->booking->booking_reference}"));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'payment.instructions', 'booking_id' => $this->booking->id];
    }
}
