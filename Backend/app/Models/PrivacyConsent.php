<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Constancia de que una persona aceptó una versión concreta del aviso de privacidad.
 *
 * NO usa el trait Tenantable a propósito: dos de los tres puntos donde se firma ocurren SIN sesión
 * (el alta de empresa, donde la empresa todavía no existe, y la postulación en el portal público).
 * El hook `creating` de Tenantable, sin usuario autenticado, cae en `request()->header('X-Tenant-ID', 1)`
 * — es decir, estamparía la empresa 1 en el consentimiento de un desconocido. Aquí el `tenant_id` se
 * pone SIEMPRE a mano, con el valor que el código ya resolvió (la empresa de la vacante, la empresa
 * recién aprovisionada), y por eso tampoco se declara `booted()`.
 *
 * Sólo se escribe y se lee para probar; nunca se edita ni se borra: es la prueba.
 */
class PrivacyConsent extends Model
{
    protected $table = 'privacy_consents';

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
