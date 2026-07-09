<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Traits\Tenantable;
use Laravel\Sanctum\HasApiTokens;

class ObsidianUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Tenantable;

    protected $table = 'obsidian_users';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'job_role_id',
        'role'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class, 'job_role_id');
    }

    public function readProgress()
    {
        return $this->hasMany(ObsidianReadProgress::class, 'user_id');
    }
}
