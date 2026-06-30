<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'created_by',
        'contact_name',
        'contact_email'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SupportTicketNote::class, 'ticket_id');
    }
}
