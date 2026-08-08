<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDocument extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'original_name',
        'path',
        'mime',
        'size_bytes',
        'linked_course_id',
        'uploaded_by',
    ];

    protected $hidden = ['path'];

    public function course()
    {
        return $this->belongsTo(AcademyCourse::class, 'linked_course_id');
    }
}
