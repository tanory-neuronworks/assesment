<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repository\Contracts\ProductRepositoryInterface;
use App\Repository\Contracts\ProductStockRepositoryInterface;

final class ApiController extends Controller
{
    public function __construct(
        Auth $auth,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductStockRepositoryInterface $productStocks,
    ) {
        parent::__construct($auth);
    }

    /**
     * GET /api/products/{sku}/availability - minimal JSON endpoint (API-01).
     * Auth is checked the same way as any page, but a failure returns JSON
     * (401/404), never an HTML redirect or error page.
     */
    public function productAvailability(Request $request): string
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $sku = (string) $request->param('sku');
        $product = $this->products->findBySku($sku);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        $stocks = array_map(
            static fn ($s) => ['warehouse' => $s->warehouseName, 'quantity' => $s->quantity],
            $this->productStocks->findByProduct((int) $product->id)
        );

        return Response::json([
            'sku' => $product->sku,
            'name' => $product->name,
            'is_active' => $product->isActive,
            'stock' => $stocks,
        ], 200);
    }
}
