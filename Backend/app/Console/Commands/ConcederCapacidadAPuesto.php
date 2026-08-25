<?php

namespace App\Console\Commands;

use App\Support\PermissionCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Concede (o retira) una capacidad a un puesto, desde la consola (2026-08-25).
 *
 * Existe por una consecuencia que hay que decir en voz alta: en la Fase 4 se decidió **no construir
 * la pantalla de la matriz de permisos** (decisión D8). Bien — pero eso deja las capacidades sin
 * ninguna forma de otorgarse, y una capacidad que nadie puede conceder es una función que nadie
 * puede usar. Es el mismo defecto que el justificante que no se podía pedir: el circuito existía
 * entero y no tenía puerta de entrada.
 *
 * Esto es la puerta mínima. No es una pantalla ni pretende serlo: es el administrador de la máquina
 * concediendo una capacidad a propósito, con el cambio registrado y verificable.
 */
#[Signature('permisos:conceder {tenant : Id de la empresa} {puesto : Id o nombre del puesto} {capacidad : Capacidad delegable} {--retirar : Quitarla en vez de concederla} {--listar : Sólo mostrar lo que el puesto tiene hoy}')]
#[Description('Concede o retira una capacidad delegable a un puesto (la pantalla de la matriz está congelada por decisión D8).')]
class ConcederCapacidadAPuesto extends Command
{
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $puestoArg = (string) $this->argument('puesto');
        $capacidad = (string) $this->argument('capacidad');

        $puesto = DB::table('job_roles')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($puestoArg) {
                $q->where('id', is_numeric($puestoArg) ? (int) $puestoArg : 0)
                    ->orWhere('name', $puestoArg);
            })
            ->first();

        if (!$puesto) {
            $this->error("No existe el puesto '{$puestoArg}' en la empresa {$tenantId}.");
            $this->line('Puestos de esa empresa:');
            foreach (DB::table('job_roles')->where('tenant_id', $tenantId)->orderBy('id')->get() as $p) {
                $this->line("  {$p->id} · {$p->name}");
            }

            return self::FAILURE;
        }

        if ($this->option('listar')) {
            return $this->listar($tenantId, $puesto);
        }

        if (!array_key_exists($capacidad, PermissionCatalog::DELEGABLE)) {
            $this->error("'{$capacidad}' no es una capacidad delegable.");
            $this->line('Las delegables son:');
            foreach (PermissionCatalog::DELEGABLE as $nombre => $desc) {
                $this->line("  {$nombre} — {$desc}");
            }
            // Las INDELEGABLES no se conceden NUNCA por aquí: se quedan en `role:admin` para que
            // ningún permiso pueda otorgarlas, ni aunque el dueño quisiera.
            return self::FAILURE;
        }

        $permisoId = DB::table('permissions')->where('name', $capacidad)->value('id');
        if (!$permisoId) {
            $permisoId = DB::table('permissions')->insertGetId([
                'name' => $capacidad,
                'description' => PermissionCatalog::DELEGABLE[$capacidad],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($this->option('retirar')) {
            $quitadas = DB::table('role_permissions')
                ->where('tenant_id', $tenantId)
                ->where('job_role_id', $puesto->id)
                ->where('permission_id', $permisoId)
                ->delete();

            $this->info($quitadas > 0
                ? "Retirada '{$capacidad}' del puesto '{$puesto->name}'."
                : "El puesto '{$puesto->name}' no tenía '{$capacidad}'; no hubo cambios.");

            return self::SUCCESS;
        }

        $yaLaTiene = DB::table('role_permissions')
            ->where('tenant_id', $tenantId)
            ->where('job_role_id', $puesto->id)
            ->where('permission_id', $permisoId)
            ->exists();

        if ($yaLaTiene) {
            $this->info("El puesto '{$puesto->name}' ya tenía '{$capacidad}'. Nada que hacer.");

            return self::SUCCESS;
        }

        DB::table('role_permissions')->insert([
            'tenant_id' => $tenantId,
            'job_role_id' => $puesto->id,
            'permission_id' => $permisoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Concedida '{$capacidad}' al puesto '{$puesto->name}' de la empresa {$tenantId}.");

        // Aviso que importa: en cuanto un puesto tiene UNA capacidad, deja de aplicarle el set por
        // defecto de supervisor. Conceder una puede QUITAR otras sin que nadie lo note.
        $total = DB::table('role_permissions')
            ->where('tenant_id', $tenantId)->where('job_role_id', $puesto->id)->count();

        if ($total === 1) {
            $this->newLine();
            $this->warn('OJO: éste es el primer permiso de ese puesto. Desde ahora manda la matriz y');
            $this->warn('DEJA de aplicarle el set por defecto de supervisor (manage_tasks,');
            $this->warn('approve_operations, manage_store_opening, view_reports). Si el puesto los');
            $this->warn('necesitaba, concédeselos también o se quedará sin ellos.');
        }

        return self::SUCCESS;
    }

    private function listar(int $tenantId, $puesto): int
    {
        $tiene = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.tenant_id', $tenantId)
            ->where('role_permissions.job_role_id', $puesto->id)
            ->pluck('permissions.name')
            ->all();

        $this->line("Puesto '{$puesto->name}' (empresa {$tenantId}):");

        if (empty($tiene)) {
            $this->line('  Sin capacidades configuradas.');
            $this->line('  → Si es un puesto de mando, hoy le aplica el set por defecto de supervisor.');

            return self::SUCCESS;
        }

        foreach ($tiene as $c) {
            $this->line('  · ' . $c);
        }

        return self::SUCCESS;
    }
}
