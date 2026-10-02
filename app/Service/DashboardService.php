<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PurchaseOrderStatus;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Repository\Contracts\ProductRepositoryInterface;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use App\Repository\Contracts\SalesOrderRepositoryInterface;

final class DashboardService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly SalesOrderRepositoryInterface $salesOrders,
    ) {
    }

    /**
     * @return array{inventoryValue: float, lowStockCount: int, poByStatus: array<string,int>, soByStatus: array<string,int>}
     */
    public function forAdmin(): array
    {
        return [
            'inventoryValue' => $this->products->inventoryValue(),
            'lowStockCount' => $this->products->lowStockCount(),
            'poByStatus' => $this->purchaseOrders->countByStatus(),
            'soByStatus' => $this->salesOrders->countByStatus(),
        ];
    }

    /**
     * @return array{soByStatus: array<string,int>}
     */
    public function forSales(User $user): array
    {
        return [
            'soByStatus' => $this->salesOrders->countByStatus($user->id),
        ];
    }

    /**
     * @return array{pendingReceipts: int, pendingIssues: int, lowStockCount: int}
     */
    public function forWarehouse(): array
    {
        $poByStatus = $this->purchaseOrders->countByStatus();
        $soByStatus = $this->salesOrders->countByStatus();

        $pendingReceipts = ($poByStatus[PurchaseOrderStatus::Ordered->value] ?? 0)
            + ($poByStatus[PurchaseOrderStatus::PartiallyReceived->value] ?? 0);
        $pendingIssues = $soByStatus[SalesOrderStatus::Approved->value] ?? 0;

        return [
            'pendingReceipts' => $pendingReceipts,
            'pendingIssues' => $pendingIssues,
            'lowStockCount' => $this->products->lowStockCount(),
        ];
    }
}
