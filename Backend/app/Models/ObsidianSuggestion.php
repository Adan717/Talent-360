<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Tenantable;

class ObsidianSuggestion extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'document_id',
        'user_id',
        'author_name',
        'original_content',
        'proposed_content',
        'comment',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_comment'
    ];

    protected $casts = [
        'reviewed_at' => 'datetime'
    ];

    public function document()
    {
        return $this->belongsTo(ObsidianDocument::class, 'document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
