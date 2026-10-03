<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    protected $fillable = [
        'tenant_id', 'booking_id', 'assignee_id', 'created_by', 'subject',
        'description', 'customer_name', 'customer_contact', 'status', 'priority',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupportTicketNote::class)->orderBy('created_at');
    }
}
