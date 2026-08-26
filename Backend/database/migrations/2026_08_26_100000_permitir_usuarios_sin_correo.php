<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un colaborador puede no tener correo (2026-08-26).
 *
 * `users.email` era NOT NULL, y como el alta no lo pedía, el sistema **se lo inventaba** a partir
 * del nombre: `juan.perez@decorarte.com`, y con homónimos `juanperez-a7f3k2@decorarte.com`. Esos
 * buzones no existen. El día que se encienda el correo, el sistema mandaría PINes y contraseñas
 * —que son las credenciales de acceso— a direcciones fabricadas, y con SMTP nadie se enteraría:
 * no hay rebote, no hay reporte, no hay nada. El administrador creería que su gente recibió sus
 * accesos.
 *
 * La mayoría de los colaboradores de una PyME de piso no tiene correo de empresa, y no lo necesita:
 * entra por el kiosco con su PIN (`/clock/kiosk-login`). Fabricarle un buzón no le daba acceso a
 * nada — sólo ensuciaba la base y preparaba un envío al vacío.
 *
 * NULL SIGUE SIENDO ÚNICO EN LA PRÁCTICA: tanto Postgres como sqlite permiten múltiples NULL en un
 * índice UNIQUE, porque NULL no es igual a NULL. Así que la unicidad de los correos que SÍ existen
 * se conserva intacta, y pueden convivir cien colaboradores sin correo.
 *
 * No se toca ningún dato existente: los correos ya fabricados siguen ahí (borrarlos dejaría sin
 * identidad a cuentas que hoy se usan). Lo que se corta es la fabricación de nuevos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver a NOT NULL con filas en NULL reventaría. Se deja el permiso puesto: es la
        // dirección segura, y revertirlo exigiría inventar correos otra vez.
    }
};
