<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Tenantable;

class KeyTransfer extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'sender_id',
        'receiver_id',
        'status',
        'notes',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
