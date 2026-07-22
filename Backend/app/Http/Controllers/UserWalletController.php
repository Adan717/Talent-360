<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserWallet;
use App\Models\WalletTransaction;

class UserWalletController extends Controller
{
    public function getBalance(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $wallet = UserWallet::getOrCreateForUser($user->id, $user->tenant_id ?? 1);

        return response()->json([
            'success' => true,
            'balance_coins' => (float)$wallet->balance_coins,
            'total_earned_coins' => (float)$wallet->total_earned_coins,
            'xp_points' => (int)$wallet->xp_points,
            'level' => (int)$wallet->level
        ]);
    }

    public function getTransactions(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $transactions = WalletTransaction::where('tenant_id', $user->tenant_id ?? 1)
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'transactions' => $transactions
        ]);
    }
}
