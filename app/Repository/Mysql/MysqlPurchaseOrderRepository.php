<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use PDO;

final class MysqlPurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?PurchaseOrder
    {
        $stmt = $this->pdo->prepare($this->headerQuery() . ' WHERE po.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $po = $this->hydrateHeader($row);
        $po->items = $this->fetchItems($id);

        return $po;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query($this->headerQuery() . ' ORDER BY po.id DESC');

        return array_map($this->hydrateHeader(...), $stmt->fetchAll());
    }

    public function create(PurchaseOrder $po): int
    {
        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO purchase_orders (supplier_id, warehouse_id, status, order_date, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $po->supplierId,
                $po->warehouseId,
                $po->status->value,
                $po->orderDate,
                $po->createdBy,
            ]);
            $po->id = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO purchase_order_items (purchase_order_id, product_id, qty_ordered, qty_received, cost_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($po->items as $item) {
                $item->purchaseOrderId = $po->id;
                $itemStmt->execute([$item->purchaseOrderId, $item->productId, $item->qtyOrdered, $item->qtyReceived, $item->costPrice]);
                $item->id = (int) $this->pdo->lastInsertId();
            }

            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $po->id;
    }

    public function updateStatus(int $id, PurchaseOrderStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    public function incrementItemReceivedQty(int $itemId, int $qty): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE purchase_order_items SET qty_received = qty_received + ?
             WHERE id = ? AND qty_received + ? <= qty_ordered'
        );
        $stmt->execute([$qty, $itemId, $qty]);

        return $stmt->rowCount() > 0;
    }

    public function countByStatus(): array
    {
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS total FROM purchase_orders GROUP BY status');
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function findInRange(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare($this->headerQuery() . ' WHERE po.order_date BETWEEN ? AND ? ORDER BY po.order_date ASC');
        $stmt->execute([$from, $to]);

        return array_map($this->hydrateHeader(...), $stmt->fetchAll());
    }

    public function paginate(?string $search, ?PurchaseOrderStatus $status, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            // Matches every column the Purchase Order table actually
            // displays (No. PO, Supplier, Gudang Tujuan, Tanggal Order,
            // Dibuat Oleh, Status), including the formatted "PO-00001" id
            // and a reverse lookup from the human-readable status label
            // back to its raw enum value.
            $like = '%' . $search . '%';
            $clauses = [
                "CONCAT('PO-', LPAD(po.id, 5, '0')) LIKE ?",
                's.name LIKE ?',
                'w.name LIKE ?',
                'po.order_date LIKE ?',
                'u.name LIKE ?',
            ];
            $clauseParams = [$like, $like, $like, $like, $like];

            foreach (PurchaseOrderStatus::cases() as $case) {
                if (stripos($case->label(), $search) !== false) {
                    $clauses[] = 'po.status = ?';
                    $clauseParams[] = $case->value;
                }
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
            array_push($params, ...$clauseParams);
        }

        if ($status !== null) {
            $where[] = 'po.status = ?';
            $params[] = $status->value;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = $this->headerQuery() . $whereSql;

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ({$from}) counted");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("{$from} ORDER BY po.order_date DESC, po.id DESC LIMIT ? OFFSET ?");
        $position = 1;
        foreach ($params as $value) {
            $listStmt->bindValue($position++, $value);
        }
        $listStmt->bindValue($position++, $perPage, PDO::PARAM_INT);
        $listStmt->bindValue($position++, $offset, PDO::PARAM_INT);
        $listStmt->execute();

        $items = array_map($this->hydrateHeader(...), $listStmt->fetchAll());

        return new Pagination($items, $total, $page, $perPage);
    }

    public function boardSummary(): array
    {
        $sql = "SELECT po.id, po.supplier_id, s.name AS supplier_name, po.status, po.order_date,
                       u.name AS created_by_name,
                       COALESCE(SUM(poi.qty_ordered * poi.cost_price), 0) AS total,
                       GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS product_names
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                JOIN users u ON u.id = po.created_by
                LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                LEFT JOIN products p ON p.id = poi.product_id
                GROUP BY po.id, po.supplier_id, s.name, po.status, po.order_date, u.name
                ORDER BY s.name ASC, po.order_date DESC";

        $stmt = $this->pdo->query($sql);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'supplier_name' => (string) $row['supplier_name'],
            'status' => (string) $row['status'],
            'order_date' => (string) $row['order_date'],
            'created_by_name' => (string) $row['created_by_name'],
            'total' => (float) $row['total'],
            'product_names' => (string) ($row['product_names'] ?? ''),
        ], $stmt->fetchAll());
    }

    /**
     * @return PurchaseOrderItem[]
     */
    private function fetchItems(int $purchaseOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT poi.id, poi.purchase_order_id, poi.product_id, poi.qty_ordered, poi.qty_received, poi.cost_price, p.name AS product_name, p.sku AS product_sku, p.unit AS unit, p.image AS product_image
             FROM purchase_order_items poi
             JOIN products p ON p.id = poi.product_id
             WHERE poi.purchase_order_id = ?
             ORDER BY poi.id ASC'
        );
        $stmt->execute([$purchaseOrderId]);

        return array_map($this->hydrateItem(...), $stmt->fetchAll());
    }

    private function headerQuery(): string
    {
        return 'SELECT po.id, po.supplier_id, po.warehouse_id, po.status, po.order_date, po.created_by, po.created_at, s.name AS supplier_name, w.name AS warehouse_name, u.name AS created_by_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                JOIN warehouses w ON w.id = po.warehouse_id
                JOIN users u ON u.id = po.created_by';
    }

    private function hydrateHeader(array $row): PurchaseOrder
    {
        return new PurchaseOrder(
            id: (int) $row['id'],
            supplierId: (int) $row['supplier_id'],
            warehouseId: (int) $row['warehouse_id'],
            status: PurchaseOrderStatus::from((string) $row['status']),
            orderDate: (string) $row['order_date'],
            createdBy: (int) $row['created_by'],
            items: [],
            supplierName: $row['supplier_name'] ?? null,
            warehouseName: $row['warehouse_name'] ?? null,
            createdByName: $row['created_by_name'] ?? null,
            createdAt: $row['created_at'] ?? null,
        );
    }

    private function hydrateItem(array $row): PurchaseOrderItem
    {
        return new PurchaseOrderItem(
            id: (int) $row['id'],
            purchaseOrderId: (int) $row['purchase_order_id'],
            productId: (int) $row['product_id'],
            qtyOrdered: (int) $row['qty_ordered'],
            qtyReceived: (int) $row['qty_received'],
            costPrice: (float) $row['cost_price'],
            productName: $row['product_name'] ?? null,
            productSku: $row['product_sku'] ?? null,
            unit: $row['unit'] ?? null,
            productImage: $row['product_image'] ?? null,
        );
    }
}
