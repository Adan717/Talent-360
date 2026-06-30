<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
// use Laravel\Cashier\Billable; // Descomentar al instalar cashier

class Company extends Model
{
    use HasFactory; 
    // use Billable; // Descomentar al instalar cashier

    protected $fillable = [
        'name',
        'domain',
        'is_active',
        'subscription_tier',
        'trial_ends_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
