<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class ObsidianVault extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'local_path',
        'last_synced_at',
        'hide_oracle_button',
        'gemini_api_key'
    ];

    protected $casts = [
        'last_synced_at' => 'datetime'
    ];

    public function documents()
    {
        return $this->hasMany(ObsidianDocument::class, 'vault_id');
    }
}
