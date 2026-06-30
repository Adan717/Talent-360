<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;

class StoreOpeningSetting extends Model
{
    use Tenantable;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'is_enabled',
        'pre_opening_window_minutes',
        'absence_late_report_window_minutes',
        'early_clock_in_allowed_minutes',
        'allow_automatic_handoff',
        'allow_late_if_before_opening',
        'allow_store_closed_report',
        'enable_amnesty_if_store_closed',
        'require_opening_checklist',
        'require_opening_roll_call',
        'notify_admin_on_handoff',
        'notify_supervisor_on_handoff',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'allow_automatic_handoff' => 'boolean',
        'allow_late_if_before_opening' => 'boolean',
        'allow_store_closed_report' => 'boolean',
        'enable_amnesty_if_store_closed' => 'boolean',
        'require_opening_checklist' => 'boolean',
        'require_opening_roll_call' => 'boolean',
        'notify_admin_on_handoff' => 'boolean',
        'notify_supervisor_on_handoff' => 'boolean',
        'pre_opening_window_minutes' => 'integer',
        'absence_late_report_window_minutes' => 'integer',
        'early_clock_in_allowed_minutes' => 'integer',
    ];
}
