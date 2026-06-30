<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\AuditLog;
use App\Models\Task;
use App\Events\MonitorUpdated;

class LogTaskValidationJob implements ShouldQueue
{
    use Queueable;

    protected $userId;
    protected $taskId;
    protected $validatedBy;
    protected $tenantId;
    protected $status;
    protected $feedback;

    /**
     * Create a new job instance.
     */
    public function __construct($userId, $taskId, $validatedBy, $tenantId, $status, $feedback = null)
    {
        $this->userId = $userId;
        $this->taskId = $taskId;
        $this->validatedBy = $validatedBy;
        $this->tenantId = $tenantId;
        $this->status = $status;
        $this->feedback = $feedback;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $taskTitle = Task::where('id', $this->taskId)->value('title') ?? 'Tarea';

        if ($this->status === 'completed') {
            AuditLog::create([
                'user_id' => $this->userId,
                'date' => now()->format('Y-m-d'),
                'type' => 'task_validation_approved',
                'reason' => 'Tarea aprobada por supervisor: ' . $taskTitle,
                'punishment_amount' => 0,
                'tenant_id' => $this->tenantId,
                'timestamp_str' => now()->format('Y-m-d H:i:s'),
            ]);
        } else {
            AuditLog::create([
                'user_id' => $this->userId,
                'date' => now()->format('Y-m-d'),
                'type' => 'task_validation_rejected',
                'reason' => 'Tarea rechazada por supervisor: ' . $taskTitle . '. Motivo: ' . $this->feedback,
                'punishment_amount' => 0,
                'tenant_id' => $this->tenantId,
                'timestamp_str' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        event(new MonitorUpdated($this->tenantId));
    }
}
