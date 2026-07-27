<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Enums\UserRole;

class EmailSettingsController extends Controller
{
    /** §52: config de correo a nivel PLATAFORMA (system_settings, tenant_id NULL). */
    public function getPlatformConfig()
    {
        if (auth()->user()->role !== UserRole::PLATFORM_ADMIN->value) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $service = app(\App\Services\MailSettingsService::class);

        return response()->json([
            'success' => true,
            'platform_sender_email' => $service->platformSenderEmail(),
            'platform_welcome_email_subject' => $service->platformWelcomeSubject(),
            'platform_welcome_email_body' => $service->platformWelcomeBody(),
        ]);
    }

    public function savePlatformConfig(Request $request)
    {
        if (auth()->user()->role !== UserRole::PLATFORM_ADMIN->value) {
            return response()->json(['error' => 'Acceso denegado'], 403);
        }

        $validated = $request->validate([
            'platform_sender_email' => 'nullable|email',
            'platform_welcome_email_subject' => 'nullable|string|max:255',
            'platform_welcome_email_body' => 'nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => null, 'key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['success' => true, 'message' => 'Configuración de correo de plataforma guardada.']);
    }

    /** §52: config de correo a nivel TENANT (nombre para mostrar + Reply-To). */
    public function getTenantConfig(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $displayName = DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', 'sender_display_name')->value('value');
        $replyTo = DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', 'sender_reply_to')->value('value');

        return response()->json([
            'success' => true,
            'sender_display_name' => $displayName ? (json_decode($displayName, true) ?: $displayName) : (DB::table('tenants')->where('id', $tenantId)->value('name')),
            'sender_reply_to' => $replyTo ? (json_decode($replyTo, true) ?: $replyTo) : null,
        ]);
    }

    public function saveTenantConfig(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $validated = $request->validate([
            'sender_display_name' => 'nullable|string|max:255',
            'sender_reply_to' => 'nullable|email',
        ]);

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            DB::table('system_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['success' => true, 'message' => 'Configuración de correo de la empresa guardada.']);
    }
}
