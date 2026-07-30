<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H20 (tercera jornada, 2026-07-30): el modo OFFLINE del reloj estaba roto en producción por
 * **un solo carácter**.
 *
 * `tenant_offline_secrets.secret` se creó con `$table->string('secret')` → `varchar(255)`, pero
 * `Crypt::encryptString()` devuelve **256** caracteres (el sobre de Laravel: iv + value + mac en
 * JSON base64). Postgres rechaza el INSERT con "value too long"; **sqlite no aplica los límites
 * de longitud de VARCHAR**, así que en la suite pasaba y en el servidor fallaba.
 *
 * Consecuencia: `GET /clock/offline-secret` devolvía error, y sin secreto el dial no puede
 * firmar los fichajes de la cola offline — es decir, fichar sin internet, que es justo el caso
 * para el que existe esa cola. Los tres tests de `ClockPunchBatchTest` lo demostraban en cuanto
 * la suite corrió contra Postgres.
 *
 * Se pasa a `text`: no hay razón para acotar la longitud de un valor cifrado, cuyo tamaño depende
 * del algoritmo y del padding y puede cambiar al actualizar Laravel. Ese fue el error de origen:
 * poner un límite fijo a algo cuyo tamaño no controlamos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_offline_secrets')) {
            return;
        }

        Schema::table('tenant_offline_secrets', function (Blueprint $table) {
            $table->text('secret')->change();
        });
    }

    public function down(): void
    {
        // Sin vuelta atrás: volver a varchar(255) truncaría los secretos ya guardados y dejaría
        // el modo offline roto otra vez.
    }
};
