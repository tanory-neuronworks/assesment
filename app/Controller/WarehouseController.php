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
use App\Entity\Role;
use App\Service\WarehouseService;

final class WarehouseController extends Controller
{
    private const LIST_PATH = '/warehouses';

    public function __construct(Auth $auth, private readonly WarehouseService $warehouses)
    {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireLogin();

        $search = $request->query('q');
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->warehouses->paginate($search, $page, $perPage);
        $isAdmin = $this->auth->user()?->role === Role::Admin;

        $rows = '';
        foreach ($pagination->items as $w) {
            $rows .= View::renderFile('warehouses.row', ['w' => $w, 'isAdmin' => $isAdmin]);
        }

        $resultsData = [
            'columns' => ['Nama', 'Lokasi', 'Status'],
            'hasActions' => $isAdmin,
            'rows' => $rows,
            'emptyMessage' => 'Belum ada gudang.',
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
                'action' => self::LIST_PATH,
            ],
        ]);

        return $this->view('warehouses.index', [
            'title' => 'Gudang',
            'tableHtml' => $tableHtml,
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('warehouses.form', ['title' => 'Tambah Gudang', 'mode' => 'create', 'target' => null]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, '/warehouses/create')) !== null) {
            return $rejected;
        }

        try {
            $this->warehouses->create($request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors('/warehouses/create', $e->errors(), $request->all());
        }

        Session::flash('success', 'Gudang berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('warehouses.form', [
            'title' => 'Edit Gudang',
            'mode' => 'edit',
            'target' => $this->warehouses->find($id),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/warehouses/{$id}/edit")) !== null) {
            return $rejected;
        }

        try {
            $this->warehouses->update($id, $request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors("/warehouses/{$id}/edit", $e->errors(), $request->all());
        }

        Session::flash('success', 'Gudang berhasil diperbarui.');

        return $this->redirect(self::LIST_PATH);
    }

    public function toggleActive(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');
        $active = $request->post('active') === '1';

        if (($rejected = $this->rejectInvalidCsrf($request, self::LIST_PATH)) !== null) {
            return $rejected;
        }

        $this->warehouses->setActive($id, $active);
        Session::flash('success', $active ? 'Gudang diaktifkan.' : 'Gudang dinonaktifkan.');

        return $this->redirect(self::LIST_PATH);
    }
}
