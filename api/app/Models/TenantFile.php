<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'disk', 'path', 'original_name', 'mime', 'size',
        'kind', 'uploaded_by',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
