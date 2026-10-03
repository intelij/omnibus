<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatLayoutSeat extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_STANDARD = 'standard';

    public const TYPE_PREMIUM = 'premium';

    public const TYPE_VIP = 'vip';

    public const TYPE_ACCESSIBLE = 'disabled_access';

    public const TYPE_STAFF = 'staff';

    public const NON_BOOKABLE_TYPES = ['driver', 'toilet', 'kitchen', 'stairs', 'exit', 'staff'];

    protected $fillable = [
        'tenant_id', 'seat_layout_id', 'deck', 'row', 'col', 'label',
        'seat_number', 'type', 'position', 'side', 'price_modifier',
        'is_bookable', 'attributes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
            'is_bookable' => 'boolean',
            'attributes' => 'array',
        ];
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(SeatLayout::class, 'seat_layout_id');
    }
}
