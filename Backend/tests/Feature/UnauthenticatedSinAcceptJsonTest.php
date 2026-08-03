<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Una petición NO autenticada a un endpoint protegido con auth:sanctum debe
 * responder 401 aunque el cliente no mande "Accept: application/json" (curl,
 * healthchecks, scanners). Sin el forzado de JSON en api/*, el middleware
 * Authenticate intenta redirigir a route('login') —que no existe en una API
 * pura— y la petición muere en 500, ensuciando los logs.
 */
class UnauthenticatedSinAcceptJsonTest extends TestCase
{
    public function test_endpoint_protegido_sin_accept_devuelve_401_y_no_500(): void
    {
        // get() (a diferencia de getJson()) no añade Accept: application/json.
        $response = $this->get('/api/v1/admin/onboarding/settings');

        $response->assertStatus(401);
    }

    public function test_endpoint_protegido_con_accept_json_devuelve_401(): void
    {
        $response = $this->getJson('/api/v1/admin/onboarding/settings');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
