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
use App\Service\UserService;

final class UserController extends Controller
{
    private const LIST_PATH = '/users';

    public function __construct(Auth $auth, private readonly UserService $users)
    {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        $search = $request->query('q');
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->users->paginate($search, $page, $perPage);

        $rows = '';
        foreach ($pagination->items as $u) {
            $rows .= View::renderFile('users.row', ['u' => $u]);
        }

        $resultsData = [
            'columns' => ['Nama', 'Username', 'Email', 'Role', 'Status'],
            'hasActions' => true,
            'rows' => $rows,
            'emptyMessage' => 'Belum ada user.',
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

        return $this->view('users.index', [
            'title' => 'Manajemen User',
            'tableHtml' => $tableHtml,
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('users.form', [
            'title' => 'Tambah User',
            'mode' => 'create',
            'roles' => Role::cases(),
            'target' => null,
        ]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, '/users/create')) !== null) {
            return $rejected;
        }

        try {
            $this->users->create($request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors('/users/create', $e->errors(), $request->all());
        }

        Session::flash('success', 'User berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('users.form', [
            'title' => 'Edit User',
            'mode' => 'edit',
            'roles' => Role::cases(),
            'target' => $this->users->find($id),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/users/{$id}/edit")) !== null) {
            return $rejected;
        }

        try {
            $this->users->update($id, $request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors("/users/{$id}/edit", $e->errors(), $request->all());
        }

        Session::flash('success', 'User berhasil diperbarui.');

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

        $this->users->setActive($id, $active);
        Session::flash('success', $active ? 'User diaktifkan.' : 'User dinonaktifkan.');

        return $this->redirect(self::LIST_PATH);
    }
}
