<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\TenantTaskClonerService;

class DecorarteSadcvTasksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cloner = app(TenantTaskClonerService::class);
        $result = $cloner->cloneTasksAndRoutines(1, 'DecorArte SA de CV');
        
        if ($this->command) {
            $this->command->info("DecorArte SA de CV tareas y rutinas sembradas exitosamente desde DecorArte 360!");
        }
    }
}
