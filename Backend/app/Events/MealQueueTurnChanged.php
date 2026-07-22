<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MealQueueTurnChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public ?int $currentTurnEmployeeId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('tenant.' . $this->tenantId . '.clock')];
    }

    public function broadcastWith(): array
    {
        return [
            'current_turn_employee_id' => $this->currentTurnEmployeeId,
        ];
    }
}
