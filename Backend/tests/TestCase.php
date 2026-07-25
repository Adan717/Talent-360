<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // §46: el caché de config casi-estática de /sync/state persiste dentro del
        // mismo proceso de PHPUnit (driver 'array'). Se limpia antes de cada test para
        // que un test no vea la config cacheada por otro que reutilizó el mismo tenant_id.
        Cache::flush();
    }
}
