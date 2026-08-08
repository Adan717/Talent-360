<?php

namespace App\Models;

use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocument extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'doc_type',
        'original_name',
        'path',
        'mime',
        'size_bytes',
        'status',
        'rejection_reason',
        'uploaded_by',
        'validated_by',
        'validated_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    // La ruta física en el disco privado no se expone al frontend: la descarga
    // pasa siempre por el endpoint autenticado.
    protected $hidden = ['path'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
