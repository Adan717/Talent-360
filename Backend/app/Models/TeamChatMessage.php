<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\Tenantable;

class TeamChatMessage extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
