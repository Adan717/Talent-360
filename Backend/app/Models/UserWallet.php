<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Tenantable;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserWallet extends Model
{
    use Tenantable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'balance_coins',
        'total_earned_coins',
        'xp_points',
        'level'
    ];

    protected $casts = [
        'balance_coins' => 'decimal:2',
        'total_earned_coins' => 'decimal:2',
        'xp_points' => 'integer',
        'level' => 'integer'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'user_id', 'user_id');
    }

    public static function getOrCreateForUser($userId, $tenantId = null)
    {
        $tenantId = $tenantId ?? (auth()->check() ? auth()->user()->tenant_id : 1);
        return static::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            ['balance_coins' => 0.00, 'total_earned_coins' => 0.00, 'xp_points' => 0, 'level' => 1]
        );
    }

    public function deposit($coins, $xp, $type, $description = null, $refType = null, $refId = null)
    {
        $coins = max(0, (float)$coins);
        $xp = max(0, (int)$xp);

        // M4 (auditoría 2026-07-27): el depósito era lectura-modificación-escritura sobre el
        // estado EN MEMORIA, sin transacción ni lock — dos depósitos concurrentes del mismo
        // usuario (doble click, batch + validación simultánea) se pisaban y uno se PERDÍA.
        // Ahora se re-lee la fila con lock pesimista dentro de una transacción y se suma
        // sobre el estado FRESCO. (Anidado en la tx de un caller, esto es un savepoint.)
        \Illuminate\Support\Facades\DB::transaction(function () use ($coins, $xp, $type, $description, $refType, $refId) {
            $fresh = static::withoutGlobalScopes()->lockForUpdate()->findOrFail($this->getKey());

            $fresh->balance_coins = (float) $fresh->balance_coins + $coins;
            $fresh->total_earned_coins = (float) $fresh->total_earned_coins + $coins;
            $fresh->xp_points = (int) $fresh->xp_points + $xp;

            // Level up formula: every 500 XP = +1 Level
            $fresh->level = max(1, floor($fresh->xp_points / 500) + 1);
            $fresh->save();

            WalletTransaction::create([
                'tenant_id' => $fresh->tenant_id,
                'user_id' => $fresh->user_id,
                'type' => $type,
                'amount' => $coins,
                'xp_amount' => $xp,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'description' => $description
            ]);

            // Reflejar el estado real en ESTA instancia (los callers leen $wallet después).
            $this->setRawAttributes($fresh->getAttributes(), true);
        });

        return $this;
    }
}
