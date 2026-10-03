<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatLayout extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'decks', 'rows', 'cols', 'status',
    ];

    protected function casts(): array
    {
        return ['decks' => 'integer', 'rows' => 'integer', 'cols' => 'integer'];
    }

    public function seats(): HasMany
    {
        return $this->hasMany(SeatLayoutSeat::class)
            ->orderBy('deck')->orderBy('row')->orderBy('col');
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
