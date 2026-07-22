<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});

// §27: canal del reloj (StoreOpened, TimeEntryRecorded, DoorNoticeCreated,
// MealQueueTurnChanged) — nombre de canal distinto a 'tenant.{tenantId}' de arriba,
// necesita su propia entrada de autorización aunque la regla sea idéntica.
Broadcast::channel('tenant.{tenantId}.clock', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
