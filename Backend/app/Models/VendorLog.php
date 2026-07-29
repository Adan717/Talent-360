<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendor_logs';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'vendor_name',
        'driver_name',
        'order_ref',
        'arrival_at',
        'departure_at',
        'status',
        'photo_evidence_url',
        'received_by_user_id',
        'received_by_name_snapshot',
    ];

    protected $casts = [
        'arrival_at' => 'datetime',
        'departure_at' => 'datetime',
    ];

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
