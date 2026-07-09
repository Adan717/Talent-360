<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class ObsidianLink extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'source_document_id',
        'target_document_id',
        'link_text'
    ];

    public function sourceDocument()
    {
        return $this->belongsTo(ObsidianDocument::class, 'source_document_id');
    }

    public function targetDocument()
    {
        return $this->belongsTo(ObsidianDocument::class, 'target_document_id');
    }
}
