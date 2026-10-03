<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'booking_id', 'passenger_id', 'first_name', 'last_name',
        'email', 'phone', 'id_document', 'date_of_birth', 'gender',
        'passenger_type', 'emergency_contact', 'special_requirements',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
