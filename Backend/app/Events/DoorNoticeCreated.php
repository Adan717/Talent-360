<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DoorNoticeCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $toEmployeeId,
        public int $fromEmployeeId,
        public string $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.' . $this->tenantId . '.clock')];
    }

    public function broadcastWith(): array
    {
        return [
            'to_employee_id' => $this->toEmployeeId,
            'from_employee_id' => $this->fromEmployeeId,
            'message' => $this->message,
        ];
    }
}
