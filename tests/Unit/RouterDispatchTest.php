<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterDispatchTest extends TestCase
{
    public function test_static_route_dispatches_and_returns_handler_output(): void
    {
        $router = new Router();
        $router->get('/products', static fn (Request $r): string => 'list');

        $this->assertSame('list', $router->dispatch('GET', '/products', new Request()));
    }

    public function test_path_params_with_underscores_and_digits_are_captured(): void
    {
        $router = new Router();
        $router->get('/orders/{order_id}/items/{item2}', static fn (Request $r): string => $r->param('order_id') . '|' . $r->param('item2'));

        $this->assertSame('42|ab_9', $router->dispatch('GET', '/orders/42/items/ab_9', new Request()));
    }

    public function test_param_name_may_start_with_underscore_and_contain_digits(): void
    {
        $router = new Router();
        $router->get('/x/{_p1}', static fn (Request $r): string => (string) $r->param('_p1'));

        $this->assertSame('v-1', $router->dispatch('GET', '/x/v-1', new Request()));
    }

    public function test_param_does_not_span_path_separators(): void
    {
        $router = new Router();
        $router->get('/products/{id}', static fn (Request $r): string => 'show');

        $this->expectException(NotFoundException::class);
        $router->dispatch('GET', '/products/1/extra', new Request());
    }

    public function test_param_requires_non_empty_segment(): void
    {
        $router = new Router();
        $router->get('/products/{id}', static fn (Request $r): string => 'show');

        $this->expectException(NotFoundException::class);
        $router->dispatch('GET', '/products/', new Request());
    }

    public function test_pattern_is_anchored_at_both_ends(): void
    {
        $router = new Router();
        $router->get('/products/{id}', static fn (Request $r): string => 'show');

        foreach (['/prefix/products/1', '/products'] as $path) {
            try {
                $router->dispatch('GET', $path, new Request());
                $this->fail("Expected NotFoundException for {$path}");
            } catch (NotFoundException $e) {
                $this->assertSame("No route for GET {$path}", $e->getMessage());
            }
        }
    }

    public function test_method_must_match(): void
    {
        $router = new Router();
        $router->post('/products', static fn (Request $r): string => 'created');

        try {
            $router->dispatch('GET', '/products', new Request());
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('No route for GET /products', $e->getMessage());
        }

        $this->assertSame('created', $router->dispatch('POST', '/products', new Request()));
    }

    public function test_first_matching_route_wins_and_later_params_are_not_leaked(): void
    {
        $router = new Router();
        $router->get('/products/new', static fn (Request $r): string => 'form:' . var_export($r->param('id'), true));
        $router->get('/products/{id}', static fn (Request $r): string => 'show:' . $r->param('id'));

        $this->assertSame('form:NULL', $router->dispatch('GET', '/products/new', new Request()));
        $this->assertSame('show:7', $router->dispatch('GET', '/products/7', new Request()));
    }

    public function test_numeric_regex_groups_are_not_exposed_as_params(): void
    {
        $router = new Router();
        $router->get('/a/{id}', static fn (Request $r): string => (string) $r->param('0'));

        $this->assertSame('', $router->dispatch('GET', '/a/5', new Request()));
    }

    public function test_handler_result_is_cast_to_string(): void
    {
        $router = new Router();
        $router->get('/n', static fn (Request $r) => 123);

        $this->assertSame('123', $router->dispatch('GET', '/n', new Request()));
    }

    public function test_empty_router_throws_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No route for GET /');

        (new Router())->dispatch('GET', '/', new Request());
    }
}
