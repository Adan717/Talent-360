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

        $this->balance_coins += $coins;
        $this->total_earned_coins += $coins;
        $this->xp_points += $xp;
        
        // Level up formula: every 500 XP = +1 Level
        $this->level = max(1, floor($this->xp_points / 500) + 1);
        $this->save();

        WalletTransaction::create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'type' => $type,
            'amount' => $coins,
            'xp_amount' => $xp,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'description' => $description
        ]);

        return $this;
    }
}
