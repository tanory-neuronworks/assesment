<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;
use App\Service\GoodsIssueService;
use App\Service\SalesOrderService;
use App\Service\WarehouseService;

final class SalesOrderController extends Controller
{
    public function __construct(
        Auth $auth,
        private readonly SalesOrderService $salesOrders,
        private readonly GoodsIssueService $goodsIssue,
        private readonly WarehouseService $warehouses,
    ) {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $user = $this->auth->requireLogin();

        // The board's own search/date-range filters are a separate AJAX
        // request from the table's (both hit this same route), so they get
        // their own query params and their own explicit "which fragment do
        // you want" marker rather than overloading isAjax() alone.
        if ($request->isAjax() && $request->query('ajax_view') === 'board') {
            [$boardSearch, $boardFrom, $boardTo] = $this->boardFilters($request);
            $board = $this->salesOrders->board($user, $boardSearch, $boardFrom, $boardTo);
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
        $status = SalesOrderStatus::tryFrom((string) $request->query('status'));
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));
        // The table's own filter form is a real GET, not AJAX - it re-renders
        // this whole page, so without this the hardcoded Board default in
        // views/sales-orders/index.php would clobber the Tabel tab the user
        // was just filtering in.
        $defaultView = $request->query('view') === 'table' ? 'table' : 'board';

        $pagination = $this->salesOrders->paginate($search, $status, $user, $page, $perPage);

        $badgeClass = static fn (SalesOrderStatus $s) => match ($s) {
            SalesOrderStatus::Fulfilled => 'badge--active',
            SalesOrderStatus::Cancelled => 'badge--inactive',
            default => 'badge--low',
        };

        $rows = '';
        foreach ($pagination->items as $so) {
            $rows .= View::renderFile('sales-orders.row', ['so' => $so, 'badgeClass' => $badgeClass($so->status)]);
        }

        $resultsData = [
            'columns' => ['No. SO', 'Customer', 'Gudang Asal', 'Tanggal Order', 'Dibuat Oleh', 'Status'],
            'hasActions' => true,
            'actionsAlign' => 'center',
            'rows' => $rows,
            'emptyMessage' => 'Belum ada Sales Order.',
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
                'action' => '/sales-orders',
                'extra' => ['status' => $status?->value],
            ],
        ]);

        [$boardSearch, $boardFrom, $boardTo] = $this->boardFilters($request);
        $board = $this->salesOrders->board($user, $boardSearch, $boardFrom, $boardTo);
        $formattedBoard = $this->formatBoard($board);

        return $this->view('sales-orders.index', [
            'title' => 'Sales Order',
            'tableHtml' => $tableHtml,
            'filters' => ['q' => $search, 'status' => $status?->value],
            'statuses' => SalesOrderStatus::cases(),
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
     * Reshapes the service's SO-specific board() result into
     * components.board-results' generic {label,badgeClass,count,total,
     * groups:[{name,meta,cards}]} shape - shared by both the full-page
     * render and the AJAX board-search fragment so the two never drift.
     *
     * @param array{stats:array{totalOrders:int,totalCustomers:int,totalNominal:float},columns:array<int,array{status:SalesOrderStatus,count:int,total:float,customers:array<int,array{name:string,total:float,orders:array<int,array<string,mixed>>}>}>} $board
     * @return array{stats:array<int,array{label:string,value:string}>,columns:array<int,array<string,mixed>>}
     */
    private function formatBoard(array $board): array
    {
        $badgeClass = static fn (SalesOrderStatus $s) => match ($s) {
            SalesOrderStatus::Fulfilled => 'badge--active',
            SalesOrderStatus::Cancelled => 'badge--inactive',
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
                'groups' => array_map(static function (array $customer) use ($rupiah): array {
                    return [
                        'name' => $customer['name'],
                        'meta' => count($customer['orders']) . ' order · ' . $rupiah($customer['total']),
                        'cards' => array_map(static function (array $order) use ($rupiah): array {
                            return [
                                'href' => "/sales-orders/{$order['id']}",
                                'title' => 'SO-' . str_pad((string) $order['id'], 5, '0', STR_PAD_LEFT),
                                'subtitle' => View::dateShort($order['order_date']),
                                'value' => $rupiah($order['total']),
                            ];
                        }, $customer['orders']),
                    ];
                }, array_values($column['customers'])),
            ];
        }, $board['columns']);

        return [
            'stats' => [
                ['label' => 'Total Sales Order', 'value' => (string) $board['stats']['totalOrders']],
                ['label' => 'Total Customer', 'value' => (string) $board['stats']['totalCustomers']],
                ['label' => 'Total Nominal', 'value' => $rupiah($board['stats']['totalNominal'])],
            ],
            'columns' => $columns,
        ];
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin, Role::Sales);

        return $this->view('sales-orders.create', [
            'title' => 'Buat Sales Order',
            'warehouses' => $this->warehouses->list(onlyActive: true),
        ]);
    }

    public function store(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::Sales);

        if (($rejected = $this->rejectInvalidCsrf($request, '/sales-orders/create')) !== null) {
            return $rejected;
        }

        $all = $request->all();
        $items = is_array($all['items'] ?? null) ? $all['items'] : [];

        try {
            $so = $this->salesOrders->create($all, $items, $user);
        } catch (ValidationException $e) {
            return $this->backWithErrors('/sales-orders/create', $e->errors(), $all);
        }

        Session::flash('success', 'Sales Order berhasil dibuat sebagai Draft.');

        return $this->redirect("/sales-orders/{$so->id}");
    }

    public function show(Request $request): string
    {
        $user = $this->auth->requireLogin();
        $id = (int) $request->param('id');
        $so = $this->salesOrders->find($id);

        if ($user->role === Role::Sales && $so->createdBy !== $user->id) {
            throw new ForbiddenException();
        }

        return $this->view('sales-orders.show', [
            'title' => 'Detail Sales Order',
            'so' => $so,
        ]);
    }

    public function submit(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::Sales);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/sales-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->salesOrders->submit($id, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/sales-orders/{$id}");
        }

        Session::flash('success', 'Sales Order diajukan untuk persetujuan.');

        return $this->redirect("/sales-orders/{$id}");
    }

    public function approve(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/sales-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->salesOrders->approve($id, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/sales-orders/{$id}");
        }

        Session::flash('success', 'Sales Order disetujui.');

        return $this->redirect("/sales-orders/{$id}");
    }

    public function reject(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/sales-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->salesOrders->reject($id, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/sales-orders/{$id}");
        }

        Session::flash('success', 'Sales Order ditolak.');

        return $this->redirect("/sales-orders/{$id}");
    }

    public function cancel(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::Sales);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/sales-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->salesOrders->cancel($id, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/sales-orders/{$id}");
        }

        Session::flash('success', 'Sales Order dibatalkan.');

        return $this->redirect("/sales-orders/{$id}");
    }

    public function issue(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/sales-orders/{$id}")) !== null) {
            return $rejected;
        }

        try {
            $this->goodsIssue->issue($id, $user);
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));

            return $this->redirect("/sales-orders/{$id}");
        }

        Session::flash('success', 'Goods issue berhasil dicatat, SO terpenuhi.');

        return $this->redirect("/sales-orders/{$id}");
    }
}
