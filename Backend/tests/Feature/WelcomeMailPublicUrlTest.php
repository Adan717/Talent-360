<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use Tests\TestCase;

class WelcomeMailPublicUrlTest extends TestCase
{
    public function test_bienvenida_usa_el_host_publico_real_y_no_inventa_un_subdominio(): void
    {
        config(['app.url' => 'https://talent360.com.mx']);

        $html = (new WelcomeMail('Admin QA', 'Empresa QA', 'empresa-qa'))->render();

        $this->assertStringContainsString('href="https://talent360.com.mx/login"', $html);
        $this->assertStringContainsString('Identificador de Empresa', $html);
        $this->assertStringNotContainsString('empresa-qa.talent360.com', $html);
        $this->assertStringNotContainsString('http://', $html);
    }
}
