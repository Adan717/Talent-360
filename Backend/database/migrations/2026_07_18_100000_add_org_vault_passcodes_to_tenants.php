<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguridad (production-readiness): passcodes de la Wiki pública POR TENANT y hasheados.
 *
 * Antes los passcodes vivían HARDCODEADOS y GLOBALES en ObsidianController (`['Guru28']`,
 * `['Chivas2017','251302','55']`), y eran la única barrera de rutas PÚBLICAS que reescriben el doc de
 * cualquier tenant. Cualquiera con `Guru28` (o `'55'`, 2 dígitos) editaba la wiki de cualquier
 * empresa.
 *
 * Ahora cada tenant tiene su propio passcode HASHEADO (bcrypt). Dos tiers:
 *  - `org_vault_admin_passcode_hash`: aprobar/rechazar sugerencias (mutan el doc).
 *  - `org_vault_viewer_passcode_hash`: ver y proponer sugerencias.
 * Nullable → FAIL-CLOSED: un tenant sin passcode configurado tiene el flujo público DESHABILITADO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('org_vault_admin_passcode_hash')->nullable();
            $table->string('org_vault_viewer_passcode_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['org_vault_admin_passcode_hash', 'org_vault_viewer_passcode_hash']);
        });
    }
};
