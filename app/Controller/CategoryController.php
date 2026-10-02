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
use App\Service\CategoryService;

final class CategoryController extends Controller
{
    private const LIST_PATH = '/categories';

    public function __construct(Auth $auth, private readonly CategoryService $categories)
    {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireLogin();

        $search = $request->query('q');
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->categories->paginate($search, $page, $perPage);
        $isAdmin = $this->auth->user()?->role === Role::Admin;

        $rows = '';
        foreach ($pagination->items as $cat) {
            $rows .= View::renderFile('categories.row', ['cat' => $cat, 'isAdmin' => $isAdmin]);
        }

        $resultsData = [
            'columns' => ['Nama', 'Deskripsi'],
            'hasActions' => $isAdmin,
            'actionsAlign' => 'center',
            'rows' => $rows,
            'emptyMessage' => 'Belum ada kategori.',
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

        return $this->view('categories.index', [
            'title' => 'Kategori',
            'tableHtml' => $tableHtml,
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('categories.form', ['title' => 'Tambah Kategori', 'mode' => 'create', 'target' => null]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, '/categories/create')) !== null) {
            return $rejected;
        }

        try {
            $this->categories->create($request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors('/categories/create', $e->errors(), $request->all());
        }

        Session::flash('success', 'Kategori berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('categories.form', [
            'title' => 'Edit Kategori',
            'mode' => 'edit',
            'target' => $this->categories->find($id),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/categories/{$id}/edit")) !== null) {
            return $rejected;
        }

        try {
            $this->categories->update($id, $request->all());
        } catch (ValidationException $e) {
            return $this->backWithErrors("/categories/{$id}/edit", $e->errors(), $request->all());
        }

        Session::flash('success', 'Kategori berhasil diperbarui.');

        return $this->redirect(self::LIST_PATH);
    }
}
