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
use App\Service\SupplierService;

final class SupplierController extends Controller
{
    private const LIST_PATH = '/suppliers';

    public function __construct(Auth $auth, private readonly SupplierService $suppliers)
    {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        $search = $request->query('q');
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->suppliers->paginate($search, $page, $perPage);

        $rows = '';
        foreach ($pagination->items as $s) {
            $rows .= View::renderFile('suppliers.row', ['s' => $s]);
        }

        $resultsData = [
            'columns' => ['Nama', 'Kontak', 'Alamat', 'Status'],
            'hasActions' => true,
            'rows' => $rows,
            'emptyMessage' => 'Belum ada supplier.',
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

        return $this->view('suppliers.index', [
            'title' => 'Supplier',
            'tableHtml' => $tableHtml,
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('suppliers.form', ['title' => 'Tambah Supplier', 'mode' => 'create', 'target' => null]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, '/suppliers/create')) !== null) {
            return $rejected;
        }

        try {
            $this->suppliers->create($request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors('/suppliers/create', $e->errors(), $request->all());
        }

        Session::flash('success', 'Supplier berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('suppliers.form', [
            'title' => 'Edit Supplier',
            'mode' => 'edit',
            'target' => $this->suppliers->find($id),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/suppliers/{$id}/edit")) !== null) {
            return $rejected;
        }

        try {
            $this->suppliers->update($id, $request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors("/suppliers/{$id}/edit", $e->errors(), $request->all());
        }

        Session::flash('success', 'Supplier berhasil diperbarui.');

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

        $this->suppliers->setActive($id, $active);
        Session::flash('success', $active ? 'Supplier diaktifkan.' : 'Supplier dinonaktifkan.');

        return $this->redirect(self::LIST_PATH);
    }
}
