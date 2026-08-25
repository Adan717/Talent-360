<?php

namespace App\Console\Commands;

use App\Models\AcademyCourse;
use App\Models\CourseCertificate;
use App\Scopes\TenantScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Revoca las constancias expedidas sobre un examen que la empresa nunca configuró (2026-08-24).
 *
 * El apagón de folios de la Fase 2 impide emitir nuevas, pero llegó tarde para las que ya salieron.
 * Un certificado verificable en público que dice "Derechos Laborales y Ley Federal del Trabajo" y
 * está respaldado por un examen de UNA pregunta —cuya respuesta correcta es la primera opción— lo
 * va a leer un inspector o un abogado como constancia de capacitación de la empresa.
 *
 * REVOCA, NO BORRA. Un certificado emitido es un hecho: alguien lo tuvo en la mano y pudo compartir
 * el folio. Borrarlo dejaría esa consulta indistinguible de un folio inventado y borraría también
 * la evidencia de que se emitió y de por qué se retiró. Revocar deja las dos cosas por escrito.
 *
 * SIMULACRO POR DEFECTO: sin `--aplicar` sólo enseña lo que haría. Es dinero reputacional de
 * terceros; nadie lo toca por teclear un comando de más.
 */
#[Signature('academia:revocar-folios-sin-examen {--aplicar : Escribe la revocación (sin esto es un simulacro)} {--folio=* : Sólo estos folios} {--motivo= : Razón que queda registrada}')]
#[Description('Revoca los certificados emitidos sobre exámenes que la empresa nunca configuró. Simulacro por defecto.')]
class RevocarCertificadosSinExamenPropio extends Command
{
    private const MOTIVO_POR_DEFECTO = 'Expedido sobre el examen de ejemplo del catálogo, que la empresa nunca configuró.';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $folios = (array) $this->option('folio');
        $motivo = (string) ($this->option('motivo') ?: self::MOTIVO_POR_DEFECTO);

        $query = CourseCertificate::withoutGlobalScope(TenantScope::class)->vigente();
        if (!empty($folios)) {
            $query->whereIn('folio', $folios);
        }

        $certificados = $query->orderBy('id')->get();

        if ($certificados->isEmpty()) {
            $this->info('No hay certificados vigentes que revisar.');

            return self::SUCCESS;
        }

        $aRevocar = [];
        $seQuedan = [];

        foreach ($certificados as $certificado) {
            $curso = AcademyCourse::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->find($certificado->course_id);

            // Si el curso trae el sello de la empresa, el certificado es legítimo y no se toca.
            // Si el curso ya no existe, no hay forma de saber si su examen era propio: se revisa
            // a mano, no se revoca por si acaso.
            if ($curso === null) {
                $seQuedan[] = [$certificado->folio, $certificado->course_title, 'el curso ya no existe — revisar a mano'];
                continue;
            }

            if ($curso->quiz_approved_at !== null) {
                $seQuedan[] = [$certificado->folio, $certificado->course_title, 'examen configurado por la empresa'];
                continue;
            }

            $aRevocar[] = $certificado;
        }

        $this->newLine();
        $this->line($aplicar ? '── REVOCANDO ──' : '── SIMULACRO (nada se escribe; use --aplicar) ──');
        $this->newLine();

        if (!empty($seQuedan)) {
            $this->line('Se quedan como están:');
            $this->table(['Folio', 'Curso', 'Por qué'], $seQuedan);
        }

        if (empty($aRevocar)) {
            $this->info('No hay nada que revocar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Folio', 'Persona', 'Curso', 'Empresa', 'Emitido'],
            array_map(fn ($c) => [
                $c->folio,
                $c->participant_name,
                $c->course_title,
                $c->company_name ?? '—',
                optional($c->issued_at)->toDateString(),
            ], $aRevocar)
        );

        if (!$aplicar) {
            $this->warn('Simulacro: ' . count($aRevocar) . ' certificado(s) se revocarían. Nada se escribió.');

            return self::SUCCESS;
        }

        foreach ($aRevocar as $certificado) {
            $certificado->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $motivo,
                'revoked_by' => null, // se corre desde consola: no hay sesión que atribuir
            ])->save();
        }

        $this->info(count($aRevocar) . ' certificado(s) revocado(s). Sus folios ya no verifican en público.');
        $this->line('Motivo registrado: ' . $motivo);

        return self::SUCCESS;
    }
}
