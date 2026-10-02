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
use App\Service\CustomerService;

final class CustomerController extends Controller
{
    private const LIST_PATH = '/customers';

    public function __construct(Auth $auth, private readonly CustomerService $customers)
    {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        $search = $request->query('q');
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->customers->paginate($search, $page, $perPage);

        $rows = '';
        foreach ($pagination->items as $c) {
            $rows .= View::renderFile('customers.row', ['c' => $c]);
        }

        $resultsData = [
            'columns' => ['Nama', 'Kontak', 'Alamat', 'Status'],
            'hasActions' => true,
            'rows' => $rows,
            'emptyMessage' => 'Belum ada customer.',
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

        return $this->view('customers.index', [
            'title' => 'Customer',
            'tableHtml' => $tableHtml,
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('customers.form', ['title' => 'Tambah Customer', 'mode' => 'create', 'target' => null]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, '/customers/create')) !== null) {
            return $rejected;
        }

        try {
            $this->customers->create($request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors('/customers/create', $e->errors(), $request->all());
        }

        Session::flash('success', 'Customer berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('customers.form', [
            'title' => 'Edit Customer',
            'mode' => 'edit',
            'target' => $this->customers->find($id),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/customers/{$id}/edit")) !== null) {
            return $rejected;
        }

        try {
            $this->customers->update($id, $request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors("/customers/{$id}/edit", $e->errors(), $request->all());
        }

        Session::flash('success', 'Customer berhasil diperbarui.');

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

        $this->customers->setActive($id, $active);
        Session::flash('success', $active ? 'Customer diaktifkan.' : 'Customer dinonaktifkan.');

        return $this->redirect(self::LIST_PATH);
    }
}
