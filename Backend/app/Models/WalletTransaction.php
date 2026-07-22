<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class WalletTransaction extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'amount',
        'xp_amount',
        'reference_type',
        'reference_id',
        'description'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'xp_amount' => 'integer'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
