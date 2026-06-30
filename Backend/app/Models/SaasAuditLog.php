<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class SaasAuditLog extends Model
{
    protected $table = 'saas_audit_logs';
 
    protected $fillable = [
        'tenant_id',
        'user_id',
        'event_type',
        'description',
        'ip_address',
        'user_agent'
    ];
 
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
