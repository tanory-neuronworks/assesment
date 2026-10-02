<?php

declare(strict_types=1);

namespace App\Repository\Mysql;

use App\Core\Pagination;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Repository\Contracts\SalesOrderRepositoryInterface;
use PDO;
use Throwable;

final class MysqlSalesOrderRepository implements SalesOrderRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?SalesOrder
    {
        $stmt = $this->pdo->prepare($this->headerQuery() . ' WHERE so.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $so = $this->hydrateHeader($row);
        $so->items = $this->fetchItems($id);

        return $so;
    }

    public function all(?int $createdBy = null): array
    {
        $sql = $this->headerQuery();
        $params = [];
        if ($createdBy !== null) {
            $sql .= ' WHERE so.created_by = ?';
            $params[] = $createdBy;
        }
        $sql .= ' ORDER BY so.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($this->hydrateHeader(...), $stmt->fetchAll());
    }

    public function create(SalesOrder $so): int
    {
        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sales_orders (customer_id, warehouse_id, status, order_date, created_by, approved_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $so->customerId,
                $so->warehouseId,
                $so->status->value,
                $so->orderDate,
                $so->createdBy,
                $so->approvedBy,
            ]);
            $so->id = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO sales_order_items (sales_order_id, product_id, qty, sell_price) VALUES (?, ?, ?, ?)'
            );
            foreach ($so->items as $item) {
                $item->salesOrderId = $so->id;
                $itemStmt->execute([$item->salesOrderId, $item->productId, $item->qty, $item->sellPrice]);
                $item->id = (int) $this->pdo->lastInsertId();
            }

            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $so->id;
    }

    public function updateStatus(int $id, SalesOrderStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE sales_orders SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    public function approve(int $id, int $approvedBy): void
    {
        $stmt = $this->pdo->prepare('UPDATE sales_orders SET status = ?, approved_by = ? WHERE id = ?');
        $stmt->execute([SalesOrderStatus::Approved->value, $approvedBy, $id]);
    }

    public function countByStatus(?int $createdBy = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM sales_orders';
        $params = [];
        if ($createdBy !== null) {
            $sql .= ' WHERE created_by = ?';
            $params[] = $createdBy;
        }
        $sql .= ' GROUP BY status';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function findInRange(string $from, string $to, ?int $createdBy = null): array
    {
        $sql = $this->headerQuery() . ' WHERE so.order_date BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($createdBy !== null) {
            $sql .= ' AND so.created_by = ?';
            $params[] = $createdBy;
        }
        $sql .= ' ORDER BY so.order_date ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($this->hydrateHeader(...), $stmt->fetchAll());
    }

    public function paginate(?string $search, ?SalesOrderStatus $status, ?int $createdBy, int $page, int $perPage): Pagination
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            // Matches every column the Sales Order table actually displays
            // (No. SO, Customer, Gudang Asal, Tanggal Order, Dibuat Oleh,
            // Status), including the formatted "SO-00001" id and a reverse
            // lookup from the human-readable status label back to its raw
            // enum value.
            $like = '%' . $search . '%';
            $clauses = [
                "CONCAT('SO-', LPAD(so.id, 5, '0')) LIKE ?",
                'c.name LIKE ?',
                'w.name LIKE ?',
                'so.order_date LIKE ?',
                'u1.name LIKE ?',
            ];
            $clauseParams = [$like, $like, $like, $like, $like];

            foreach (SalesOrderStatus::cases() as $case) {
                if (stripos($case->label(), $search) !== false) {
                    $clauses[] = 'so.status = ?';
                    $clauseParams[] = $case->value;
                }
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
            array_push($params, ...$clauseParams);
        }

        if ($status !== null) {
            $where[] = 'so.status = ?';
            $params[] = $status->value;
        }

        if ($createdBy !== null) {
            $where[] = 'so.created_by = ?';
            $params[] = $createdBy;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = $this->headerQuery() . $whereSql;

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ({$from}) counted");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("{$from} ORDER BY so.order_date DESC, so.id DESC LIMIT ? OFFSET ?");
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

    public function boardSummary(?int $createdBy = null): array
    {
        // product_names backs the board's client-side search (matches SO
        // number, customer, or any product on the order) - GROUP_CONCAT
        // keeps it a one-row-per-order aggregate like everything else here,
        // rather than a separate items fetch per order.
        $sql = "SELECT so.id, so.customer_id, c.name AS customer_name, so.status, so.order_date,
                       u.name AS created_by_name,
                       COALESCE(SUM(soi.qty * soi.sell_price), 0) AS total,
                       GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') AS product_names
                FROM sales_orders so
                JOIN customers c ON c.id = so.customer_id
                JOIN users u ON u.id = so.created_by
                LEFT JOIN sales_order_items soi ON soi.sales_order_id = so.id
                LEFT JOIN products p ON p.id = soi.product_id";
        $params = [];

        if ($createdBy !== null) {
            $sql .= ' WHERE so.created_by = ?';
            $params[] = $createdBy;
        }

        $sql .= ' GROUP BY so.id, so.customer_id, c.name, so.status, so.order_date, u.name
                  ORDER BY c.name ASC, so.order_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'customer_id' => (int) $row['customer_id'],
            'customer_name' => (string) $row['customer_name'],
            'status' => (string) $row['status'],
            'order_date' => (string) $row['order_date'],
            'created_by_name' => (string) $row['created_by_name'],
            'total' => (float) $row['total'],
            'product_names' => (string) ($row['product_names'] ?? ''),
        ], $stmt->fetchAll());
    }

    /**
     * @return SalesOrderItem[]
     */
    private function fetchItems(int $salesOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT soi.id, soi.sales_order_id, soi.product_id, soi.qty, soi.sell_price, p.name AS product_name, p.sku AS product_sku, p.unit AS unit, p.image AS product_image
             FROM sales_order_items soi
             JOIN products p ON p.id = soi.product_id
             WHERE soi.sales_order_id = ?
             ORDER BY soi.id ASC'
        );
        $stmt->execute([$salesOrderId]);

        return array_map($this->hydrateItem(...), $stmt->fetchAll());
    }

    private function headerQuery(): string
    {
        return 'SELECT so.id, so.customer_id, so.warehouse_id, so.status, so.order_date, so.created_by, so.approved_by, so.created_at, c.name AS customer_name, w.name AS warehouse_name,
                       u1.name AS created_by_name, u2.name AS approved_by_name
                FROM sales_orders so
                JOIN customers c ON c.id = so.customer_id
                JOIN warehouses w ON w.id = so.warehouse_id
                JOIN users u1 ON u1.id = so.created_by
                LEFT JOIN users u2 ON u2.id = so.approved_by';
    }

    private function hydrateHeader(array $row): SalesOrder
    {
        return new SalesOrder(
            id: (int) $row['id'],
            customerId: (int) $row['customer_id'],
            warehouseId: (int) $row['warehouse_id'],
            status: SalesOrderStatus::from((string) $row['status']),
            orderDate: (string) $row['order_date'],
            createdBy: (int) $row['created_by'],
            approvedBy: $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
            items: [],
            customerName: $row['customer_name'] ?? null,
            warehouseName: $row['warehouse_name'] ?? null,
            createdByName: $row['created_by_name'] ?? null,
            approvedByName: $row['approved_by_name'] ?? null,
            createdAt: $row['created_at'] ?? null,
        );
    }

    private function hydrateItem(array $row): SalesOrderItem
    {
        return new SalesOrderItem(
            id: (int) $row['id'],
            salesOrderId: (int) $row['sales_order_id'],
            productId: (int) $row['product_id'],
            qty: (int) $row['qty'],
            sellPrice: (float) $row['sell_price'],
            productName: $row['product_name'] ?? null,
            productSku: $row['product_sku'] ?? null,
            unit: $row['unit'] ?? null,
            productImage: $row['product_image'] ?? null,
        );
    }
}
