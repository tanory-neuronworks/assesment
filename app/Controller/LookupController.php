<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Service\CategoryService;
use App\Service\CustomerService;
use App\Service\ProductService;
use App\Service\SupplierService;
use App\Service\WarehouseService;

/**
 * Backs the async searchable "lookup" dropdowns (product/supplier/customer
 * pickers in the PO/SO create forms) with debounced, server-side search
 * instead of preloading every row into a giant <select>.
 */
final class LookupController extends Controller
{
    public function __construct(
        Auth $auth,
        private readonly ProductService $products,
        private readonly SupplierService $suppliers,
        private readonly CustomerService $customers,
        private readonly WarehouseService $warehouses,
        private readonly CategoryService $categories,
    ) {
        parent::__construct($auth);
    }

    public function search(Request $request): string
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $type = (string) $request->param('type');
        $q = trim((string) ($request->query('q') ?? ''));

        $results = match ($type) {
            'products' => $this->searchProducts($q),
            'suppliers' => $this->searchEntities($this->suppliers->list(onlyActive: true), $q),
            'customers' => $this->searchEntities($this->customers->list(onlyActive: true), $q),
            'warehouses' => $this->searchEntities($this->warehouses->list(onlyActive: true), $q),
            'categories' => $this->searchEntities($this->categories->list(), $q),
            default => null,
        };

        if ($results === null) {
            return Response::json(['error' => 'Unknown lookup type'], 404);
        }

        return Response::json(['results' => $results]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchProducts(string $q): array
    {
        $pagination = $this->products->paginate($q === '' ? null : $q, null, null, 1, 20);

        return array_map(static fn ($p) => [
            'id' => $p->id,
            'label' => "{$p->sku} - {$p->name}",
            'image' => $p->image,
            'meta' => ['sellPrice' => $p->sellPrice, 'costPrice' => $p->costPrice],
        ], $pagination->items);
    }

    /**
     * @param array<int,object{id:?int,name:string}> $entities
     * @return array<int,array<string,mixed>>
     */
    private function searchEntities(array $entities, string $q): array
    {
        $needle = mb_strtolower($q);
        $matches = $q === ''
            ? $entities
            : array_values(array_filter($entities, static fn ($e) => str_contains(mb_strtolower($e->name), $needle)));

        $matches = array_slice($matches, 0, 20);

        return array_map(static fn ($e) => ['id' => $e->id, 'label' => $e->name], $matches);
    }
}
