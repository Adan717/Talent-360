<?php

use App\Support\EmailNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H18: normaliza los correos con diacríticos que quedaron de antes del fix de H3.
 *
 * H3 dejó de GENERARLOS, pero no tocó los existentes, y esas personas no pueden iniciar sesión:
 * la validación nativa de `<input type="email">` rechaza el valor en el navegador y la petición
 * nunca sale. Por API el login funciona —el backend compara bytes—, así que el bloqueo sólo se
 * ve entrando por el formulario, que es como entra todo el mundo.
 *
 * REGLA ANTE COLISIONES: si el correo normalizado ya pertenece a OTRA fila, se deja el original
 * intacto y se registra en el log. Pisar el correo de otra persona —o peor, fusionar dos accesos
 * distintos— es más grave que el bloqueo que se viene a arreglar; esos pocos casos se resuelven
 * a mano con el listado del log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conflictos = [];
        $normalizados = 0;

        // Sólo las filas con algún carácter no ASCII antes de la @: el resto ya está bien.
        foreach (DB::table('users')->select('id', 'email')->orderBy('id')->cursor() as $fila) {
            $original = $fila->email;
            if ($original === null || $original === '') {
                continue;
            }

            $limpio = EmailNormalizer::normalizar($original);
            if ($limpio === $original) {
                continue; // sin cambios: no se reescribe la fila (ni su updated_at)
            }

            $ocupado = DB::table('users')
                ->where('email', $limpio)
                ->where('id', '<>', $fila->id)
                ->exists();

            if ($ocupado) {
                $conflictos[] = "#{$fila->id} {$original} → {$limpio}";
                continue;
            }

            DB::table('users')->where('id', $fila->id)->update(['email' => $limpio]);

            // El expediente guarda su propia copia del correo: si no se mueve con el usuario,
            // queda apuntando a un valor que ya no existe.
            DB::table('employees')
                ->where('user_id', $fila->id)
                ->where('email', $original)
                ->update(['email' => $limpio]);

            $normalizados++;
        }

        if ($normalizados > 0) {
            Log::info("H18: {$normalizados} correo(s) con diacríticos normalizados.");
        }

        if ($conflictos !== []) {
            Log::warning(
                'H18: estos correos NO se normalizaron porque el destino ya está ocupado; '
                . 'hay que resolverlos a mano: ' . implode(', ', $conflictos)
            );
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: el valor original ya no se conoce y restaurarlo volvería a dejar
        // fuera del sistema a quien hoy sí puede entrar.
    }
};
