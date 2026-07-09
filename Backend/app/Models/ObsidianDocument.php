<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class ObsidianDocument extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'vault_id',
        'slug',
        'filename',
        'title',
        'content',
        'raw_content',
        'frontmatter',
        'icon',
        'type'
    ];

    protected $casts = [
        'frontmatter' => 'array'
    ];

    public function vault()
    {
        return $this->belongsTo(ObsidianVault::class, 'vault_id');
    }

    public function outgoingLinks()
    {
        return $this->hasMany(ObsidianLink::class, 'source_document_id');
    }

    public function incomingLinks()
    {
        return $this->hasMany(ObsidianLink::class, 'target_document_id');
    }

    public function suggestions()
    {
        return $this->hasMany(ObsidianSuggestion::class, 'document_id');
    }
}
