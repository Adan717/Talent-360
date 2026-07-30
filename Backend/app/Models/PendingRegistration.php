<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'reminded_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForEmailOrSubdomain($query, $email, $subdomain = null)
    {
        return $query->where(function($q) use ($email, $subdomain) {
            $q->where('admin_email', strtolower($email));
            if ($subdomain) {
                $q->orWhere('subdomain', strtolower($subdomain));
            }
        });
    }
}
