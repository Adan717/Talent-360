<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class ObsidianReadProgress extends Model
{
    use Tenantable;

    protected $table = 'obsidian_read_progress';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_id'
    ];

    public function user()
    {
        return $this->belongsTo(ObsidianUser::class, 'user_id');
    }

    public function document()
    {
        return $this->belongsTo(ObsidianDocument::class, 'document_id');
    }
}
