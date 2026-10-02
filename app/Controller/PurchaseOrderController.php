<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Service\GoodsReceiptService;
use App\Service\PurchaseOrderService;
use App\Service\WarehouseService;

final class PurchaseOrderController extends Controller
{
    public function __construct(
        Auth $auth,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly GoodsReceiptService $goodsReceipt,
        private readonly WarehouseService $warehouses,
    ) {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);

        // The board's own search/date-range filters are a separate AJAX
        // request from the table's (both hit this same route), so they get
        // their own query params and their own explicit "which fragment do
        // you want" marker rather than overloading isAjax() alone.
        if ($request->isAjax() && $request->query('ajax_view') === 'board') {
            [$boardSearch, $boardFrom, $boardTo] = $this->boardFilters($request);
            $board = $this->purchaseOrders->board($boardSearch, $boardFrom, $boardTo);
            $formatted = $this->formatBoard($board);

            return View::renderFile('components.board-results', [
                'stats' => $formatted['stats'],
                'columns' => $formatted['columns'],
                'emptyMessage' => $boardSearch !== '' || $boardFrom !== '' || $boardTo !== ''
                    ? 'Tidak ada yang cocok.'
                    : 'Belum ada order.',
                'expanded' => $boardSearch !== '',
            ]);
        }

        $search = $request->query('q');
        $status = PurchaseOrderStatus::tryFrom((string) $request->query('status'));
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));
        // The table's own filter form is a real GET, not AJAX - it re-renders
        // this whole page, so without this the hardcoded Board default in
        // views/purchase-orders/index.php would clobber the Tabel tab the
        // user was just filtering in.
        $defaultView = $request->query('view') === 'table' ? 'table' : 'board';

        $pagination = $this->purchaseOrders->paginate($search, $status, $page, $perPage);

        $badgeClass = static fn (PurchaseOrderStatus $s) => match ($s) {
            PurchaseOrderStatus::Received => 'badge--active',
            PurchaseOrderStatus::Cancelled => 'badge--inactive',
            default => 'badge--low',
        };

        $rows = '';
        foreach ($pagination->items as $po) {
            $rows .= View::renderFile('purchase-orders.row', ['po' => $po, 'badgeClass' => $badgeClass($po->status)]);
        }

        $resultsData = [
            'columns' => ['No. PO', 'Supplier', 'Gudang Tujuan', 'Tanggal Order', 'Dibuat Oleh', 'Status'],
            'hasActions' => true,
            'actionsAlign' => 'center',
            'rows' => $rows,
            'emptyMessage' => 'Belum ada Purchase Order.',
            'pagination' => $pagination,
            'queryBase' => $request->queryStringWithout('page', 'per_page'),
        ];

        if ($request->isAjax()) {
            return View::renderFile('components.data-table-results', $resultsData);
        }

        $tableHtml = View::renderFile('components.data-table', [
            ...$resultsData,
            'search' => [
                'name' => 'q',
                'value' => $search ?? '',
                'action' => '/purchase-orders',
                'extra' => ['status' => $status?->value],
            ],
        ]);

        [$boardSearch, $boardFrom, $boardTo] = $this->boardFilters($request);
        $board = $this->purchaseOrders->board($boardSearch, $boardFrom, $boardTo);
        $formattedBoard = $this->formatBoard($board);

        return $this->view('purchase-orders.index', [
            'title' => 'Purchase Order',
            'tableHtml' => $tableHtml,
            'filters' => ['q' => $search, 'status' => $status?->value],
            'statuses' => PurchaseOrderStatus::cases(),
            'boardStats' => $formattedBoard['stats'],
            'boardColumns' => $formattedBoard['columns'],
            'boardSearch' => $boardSearch,
            'boardFrom' => $boardFrom,
            'boardTo' => $boardTo,
            'defaultView' => $defaultView,
        ]);
    }

    /**
     * board_from/board_to default to the current calendar month, but only
     * when truly absent from the request - an explicit empty string (the
     * user cleared the field via the AJAX filter) means "no bound", not
     * "fall back to the default" again.
     *
     * @return array{0:string,1:string,2:string} [search, from, to]
     */
    private function boardFilters(Request $request): array
    {
        $today = new \DateTimeImmutable('today');

        $search = trim((string) ($request->query('board_q') ?? ''));
        $from = $request->query('board_from') ?? $today->modify('first day of this month')->format('Y-m-d');
        $to = $request->query('board_to') ?? $today->modify('last day of this month')->format('Y-m-d');

        return [$search, $from, $to];
    }

    /**
     * Reshapes the service's PO-specific board() result into
     * components.board-results' generic {label,badgeClass,count,total,
     * groups:[{name,meta,cards}]} shape - shared by both the full-page
     * render and the AJAX board-search fragment so the two never drift.
     *
     * @param array{stats:array{totalOrders:int,totalSuppliers:int,totalNominal:float},columns:array<int,array{status:PurchaseOrderStatus,count:int,total:float,suppliers:array<int,array{name:string,total:float,orders:array<int,array<string,mixed>>}>}>} $board
     * @return array{stats:array<int,array{label:string,value:string}>,columns:array<int,array<string,mixed>>}
     */
    private function formatBoard(array $board): array
    {
        $badgeClass = static fn (PurchaseOrderStatus $s) => match ($s) {
            PurchaseOrderStatus::Received => 'badge--active',
            PurchaseOrderStatus::Cancelled => 'badge--inactive',
            default => 'badge--low',
        };
        $rupiah = static fn (float $n) => View::rupiahShort($n);

        $columns = array_map(static function (array $column) use ($badgeClass, $rupiah): array {
            $status = $column['status'];

            return [
                'label' => $status->label(),
                'badgeClass' => $badgeClass($status),
                'count' => $column['count'],
                'total' => $rupiah($column['total']),
                'groups' => array_map(static function (array $supplier) use ($rupiah): array {
                    return [
                        'name' => $supplier['name'],
                        'meta' => count($supplier['orders']) . ' order · ' . $rupiah($supplier['total']),
                        'cards' => array_map(static function (array $order) use ($rupiah): array {
                            return [
                                'href' => "/purchase-orders/{$order['id']}",
                                'title' => 'PO-' . str_pad((string) $order['id'], 5, '0', STR_PAD_LEFT),
                                'subtitle' => View::dateShort($order['order_date']),
                                'value' => $rupiah($order['total']),
                            ];
                        }, $supplier['orders']),
                    ];
                }, array_values($column['suppliers'])),
            ];
        }, $board['columns']);

        return [
            'stats' => [
                ['label' => 'Total Purchase Order', 'value' => (string) $board['stats']['totalOrders']],
                ['label' => 'Total Supplier', 'value' => (string) $board['stats']['totalSuppliers']],
                ['label' => 'Total Nominal', 'value' => $rupiah($board['stats']['totalNominal'])],
            ],
            'columns' => $columns,
        ];
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);

        return $this->view('purchase-orders.create', [
            'title' => 'Buat Purchase Order',
            'warehouses' => $this->warehouses->list(onlyActive: true),
        ]);
    }

    public function store(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);

        if (($rejected = $this->rejectInvalidCsrf($request, '/purchase-orders/create')) !== null) {
            return $rejected;
        }

        $all = $request->all();
        $items = is_array($all['items'] ?? null) ? $all['items'] : [];

        try {
            $po = $this->purchaseOrders->create($all, $items, $user);
        } catch (ValidationException $e) {
            return $this->backWithErrors('/purchase-orders/create', $e->errors(), $all);
        }

        Session::flash('success', 'Purchase Order berhasil dibuat.');

        return $this->redirect("/purchase-orders/{$po->id}");
    }

    public function show(Request $request): string
    {
        $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);
        $id = (int) $request->param('id');

        return $this->view('purchase-orders.show', [
            'title' => 'Detail Purchase Order',
            'po' => $this->purchaseOrders->find($id),
        ]);
    }

    public function receive(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/purchase-orders/{$id}")) !== null) {
            return $rejected;
        }

        $all = $request->all();
        $lines = [];
        foreach ((array) ($all['receive'] ?? []) as $itemId => $qty) {
            $lines[(int) $itemId] = (int) $qty;
        }

        try {
            $this->goodsReceipt->receive($id, $lines, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/purchase-orders/{$id}");
        }

        Session::flash('success', 'Penerimaan barang berhasil dicatat.');

        return $this->redirect("/purchase-orders/{$id}");
    }

    public function cancel(Request $request): string
    {
        $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/purchase-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->purchaseOrders->cancel($id);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/purchase-orders/{$id}");
        }

        Session::flash('success', 'Purchase Order dibatalkan.');

        return $this->redirect("/purchase-orders/{$id}");
    }
}
