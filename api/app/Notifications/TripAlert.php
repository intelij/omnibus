<?php

namespace App\Notifications;

use App\Models\Trip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Trip delay / cancellation / change alert to booked passengers. */
class TripAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Trip $trip, public string $subject, public string $body) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->trip->tenant;

        return (new MailMessage)
            ->subject("{$tenant->trading_name}: {$this->subject}")
            ->line($this->body)
            ->line('Departure: '.$this->trip->departure_at->setTimezone($tenant->timezone)->format('d M Y H:i'))
            ->line("Route: {$this->trip->route->origin} → {$this->trip->route->destination}");
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'trip.alert', 'trip_id' => $this->trip->id, 'subject' => $this->subject];
    }
}
