<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Routing\Route;
use Tests\TestCase;

class RouteControllerContractTest extends TestCase
{
    public function test_every_registered_controller_action_exists(): void
    {
        $invalidActions = [];

        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            $uses = $route->getAction('uses');

            if ($uses instanceof Closure) {
                continue;
            }

            if (is_array($uses)) {
                [$controller, $method] = $uses;
            } elseif (is_string($uses) && str_contains($uses, '@')) {
                [$controller, $method] = explode('@', $uses, 2);
            } elseif (is_string($uses) && class_exists($uses)) {
                $controller = $uses;
                $method = '__invoke';
            } else {
                $invalidActions[] = sprintf('%s %s -> acción inválida: %s', $route->methods()[0], $route->uri(), get_debug_type($uses));
                continue;
            }

            if (! class_exists($controller) || ! method_exists($controller, $method)) {
                $invalidActions[] = sprintf('%s %s -> %s@%s', $route->methods()[0], $route->uri(), $controller, $method);
            }
        }

        $this->assertSame([], $invalidActions, "Hay rutas que apuntan a controladores o métodos inexistentes:\n".implode("\n", $invalidActions));
    }
}
