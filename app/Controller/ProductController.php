<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Entity\Role;
use App\Repository\Contracts\StockLedgerRepositoryInterface;
use App\Service\CategoryService;
use App\Service\ProductService;

final class ProductController extends Controller
{
    private const LIST_PATH = '/products';
    private const CREATE_PATH = '/products/create';

    public function __construct(
        Auth $auth,
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly StockLedgerRepositoryInterface $stockLedger,
    ) {
        parent::__construct($auth);
    }

    public function index(Request $request): string
    {
        $this->auth->requireLogin();

        return $this->galleryAjaxResponse($request) ?? $this->listPage($request);
    }

    /**
     * The AJAX gallery fragments (first batch / load-more), or null when the
     * request is not one of them and the normal table/page should be built.
     */
    private function galleryAjaxResponse(Request $request): ?string
    {
        if (!$request->isAjax()) {
            return null;
        }

        return match ($request->query('ajax_view')) {
            'gallery' => $this->galleryResponse($request),
            'gallery-more' => $this->galleryMoreResponse($request),
            default => null,
        };
    }

    private function listPage(Request $request): string
    {
        $search = $request->query('q');
        $categoryId = $request->query('category') !== null && $request->query('category') !== ''
            ? (int) $request->query('category') : null;
        $stockStatus = in_array($request->query('stock'), ['low', 'normal'], true) ? $request->query('stock') : null;
        // The table's own filter form is a real GET, not AJAX - it re-renders
        // this whole page, so without this the hardcoded Galeri default in
        // views/products/index.php would clobber the Tabel tab the user was
        // just filtering in.
        $defaultView = $request->query('view') === 'table' ? 'table' : 'gallery';
        $page = Pagination::normalizePage($request->query('page'));
        $perPage = Pagination::normalizePerPage($request->query('per_page'));

        $pagination = $this->products->paginate($search, $categoryId, $stockStatus, $page, $perPage);

        $stockTotals = [];
        foreach ($pagination->items as $product) {
            $stockTotals[$product->id] = $this->products->totalStock($product->id);
        }

        $isAdmin = $this->auth->user()?->role === Role::Admin;

        $rows = '';
        foreach ($pagination->items as $p) {
            $rows .= View::renderFile('products.row', [
                'p' => $p,
                'stock' => $stockTotals[$p->id] ?? 0,
                'isAdmin' => $isAdmin,
            ]);
        }

        $resultsData = [
            'columns' => [
                'SKU', 'Nama', 'Kategori',
                ['label' => 'Harga Jual', 'align' => 'right'],
                ['label' => 'Stok', 'align' => 'right'],
                'Status',
            ],
            'hasThumbnail' => true,
            'hasActions' => true,
            'rows' => $rows,
            'emptyMessage' => 'Belum ada produk.',
            'pagination' => $pagination,
            'queryBase' => $request->queryStringWithout('page', 'per_page'),
        ];

        if ($request->isAjax()) {
            // Only the rows/pagination fragment - the toolbar (search input,
            // entries picker) lives outside [data-ajax-table] and must never
            // be replaced, or the search box loses focus mid-keystroke.
            return View::renderFile('components.data-table-results', $resultsData);
        }

        $tableHtml = View::renderFile('components.data-table', [
            ...$resultsData,
            'search' => [
                'name' => 'q',
                'value' => $search ?? '',
                'action' => self::LIST_PATH,
                'extra' => [
                    'category' => $categoryId !== null ? (string) $categoryId : null,
                    'stock' => $stockStatus,
                ],
            ],
        ]);

        return $this->view('products.index', [
            'title' => 'Produk',
            'tableHtml' => $tableHtml,
            'categories' => $this->categories->list(),
            'filters' => ['q' => $search, 'category' => $categoryId, 'stock' => $stockStatus],
            'defaultView' => $defaultView,
            // Rendered with 0 products per group on purpose - the page's
            // real viewport width isn't known server-side, so gallery-load-
            // more.js measures the actual row size with a probe element and
            // fetches the first batch itself, rather than this guessing a
            // fixed count that's then wrong for most screens.
            'galleryGroups' => $this->buildGallery(null, 0),
        ]);
    }

    /**
     * The gallery's own search is a separate AJAX request from the table's
     * (both hit the index route) - same "ajax_view" marker convention as the
     * SO/PO board view.
     */
    private function galleryResponse(Request $request): string
    {
        $gallerySearch = trim((string) ($request->query('gallery_q') ?? ''));

        return View::renderFile('components.gallery-results', [
            'groups' => $this->buildGallery($gallerySearch !== '' ? $gallerySearch : null, $this->galleryLimit($request)),
            'isAdmin' => $this->auth->user()?->role === Role::Admin,
        ]);
    }

    /**
     * "Muat Lebih Banyak" on one category group - fetches the next batch of
     * that category's cards from the server on demand, rather than shipping
     * the whole catalog up front and hiding the rest with CSS (real lazy
     * loading, not just a client-side reveal).
     */
    private function galleryMoreResponse(Request $request): string
    {
        $categoryId = (int) $request->query('category_id');
        $offset = max(0, (int) $request->query('offset'));
        $gallerySearch = trim((string) ($request->query('gallery_q') ?? ''));
        $limit = $this->galleryLimit($request);

        $categoryProducts = array_values(array_filter(
            $this->galleryProducts($gallerySearch !== '' ? $gallerySearch : null),
            static fn (array $item): bool => $item['categoryId'] === $categoryId,
        ));

        $slice = array_slice($categoryProducts, $offset, $limit);
        $isAdmin = $this->auth->user()?->role === Role::Admin;

        $html = '';
        foreach ($slice as $p) {
            $html .= View::renderFile('components.gallery-card', ['p' => $p, 'isAdmin' => $isAdmin]);
        }

        // total is requeried fresh right here (not carried over from the
        // page's initial render), so the client's "hide the button once
        // every product is shown" check stays correct even if another
        // user added/removed products in this category since the page
        // first loaded.
        return Response::json([
            'html' => $html,
            'total' => count($categoryProducts),
            'hasMore' => ($offset + count($slice)) < count($categoryProducts),
            'nextOffset' => $offset + count($slice),
        ]);
    }

    /**
     * The client measures how many cards fit in one row of the grid it has
     * on screen and sends that as "limit", so each batch completes a full
     * row. 5 is just the fallback if that measurement is missing/invalid.
     */
    private function galleryLimit(Request $request): int
    {
        return max(1, min(50, (int) $request->query('limit', '5')));
    }

    /**
     * Every product matching $search (SKU/name only - narrower than the
     * table's multi-column search, the gallery is for browsing, not precise
     * filtering), flattened and mapped to the shape the gallery cards need.
     * Shared by buildGallery() (initial page) and the gallery-more AJAX
     * action (one category's next batch), so both filter identically.
     *
     * @return array<int,array<string,mixed>>
     */
    private function galleryProducts(?string $search): array
    {
        $pagination = $this->products->paginate(null, null, null, 1, 100000);
        $needle = $search !== null ? mb_strtolower($search) : null;

        $items = [];
        foreach ($pagination->items as $p) {
            if ($needle !== null && !str_contains(mb_strtolower($p->sku . ' ' . $p->name), $needle)) {
                continue;
            }

            $stock = $this->products->totalStock($p->id);

            $items[] = [
                'id' => $p->id,
                'categoryId' => $p->categoryId,
                'categoryName' => $p->categoryName ?? 'Tanpa Kategori',
                'href' => "/products/{$p->id}",
                'image' => $p->image,
                'uploadUrl' => "/products/{$p->id}/image",
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => 'Rp ' . number_format($p->sellPrice, 0, ',', '.'),
                'stock' => $stock,
                'lowStock' => $stock <= $p->reorderPoint,
                'isActive' => $p->isActive,
            ];
        }

        return $items;
    }

    /**
     * Groups matching products into a Tokopedia/Shopee-style gallery by
     * category, each group capped at $limitPerGroup for the initial render -
     * the rest is fetched on demand via the gallery-more AJAX action as the
     * user clicks "Muat Lebih Banyak", never shipped up front.
     *
     * @return array<int,array{categoryId:int,name:string,products:array<int,array<string,mixed>>,total:int,hasMore:bool}>
     */
    private function buildGallery(?string $search, int $limitPerGroup = 5): array
    {
        $groups = [];
        foreach ($this->galleryProducts($search) as $item) {
            $groups[$item['categoryId']]['name'] = $item['categoryName'];
            $groups[$item['categoryId']]['products'][] = $item;
        }

        $result = [];
        foreach ($groups as $categoryId => $group) {
            $total = count($group['products']);
            $result[] = [
                'categoryId' => $categoryId,
                'name' => $group['name'],
                'products' => array_slice($group['products'], 0, $limitPerGroup),
                'total' => $total,
                'hasMore' => $total > $limitPerGroup,
            ];
        }

        usort($result, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $result;
    }

    public function show(Request $request): string
    {
        $this->auth->requireLogin();
        $id = (int) $request->param('id');
        $product = $this->products->find($id);

        return $this->view('products.show', [
            'title' => $product->name,
            'product' => $product,
            'stocks' => $this->products->stockBreakdown($id),
            'ledger' => $this->stockLedger->findByProduct($id, 10),
        ]);
    }

    public function create(): string
    {
        $this->auth->requireRole(Role::Admin);

        return $this->view('products.form', [
            'title' => 'Tambah Produk',
            'mode' => 'create',
            'target' => null,
            'categories' => $this->categories->list(),
        ]);
    }

    public function store(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);

        if (($rejected = $this->rejectInvalidCsrf($request, self::CREATE_PATH)) !== null) {
            return $rejected;
        }

        $failure = null;
        try {
            $this->products->create($request->all(), $request->file('image'));
        } catch (ValidationException $e) {
            $failure = $this->backWithErrors(self::CREATE_PATH, $e->errors(), $request->all());
        } catch (\InvalidArgumentException $e) {
            $failure = $this->backWithErrors(self::CREATE_PATH, ['image' => $e->getMessage()], $request->all());
        }

        if ($failure !== null) {
            return $failure;
        }

        Session::flash('success', 'Produk berhasil dibuat.');

        return $this->redirect(self::LIST_PATH);
    }

    public function edit(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        return $this->view('products.form', [
            'title' => 'Edit Produk',
            'mode' => 'edit',
            'target' => $this->products->find($id),
            'categories' => $this->categories->list(),
        ]);
    }

    public function update(Request $request): string
    {
        $this->auth->requireRole(Role::Admin);
        $id = (int) $request->param('id');

        if (($rejected = $this->rejectInvalidCsrf($request, "/products/{$id}/edit")) !== null) {
            return $rejected;
        }

        $failure = null;
        try {
            $this->products->update($id, $request->all(), $request->file('image'));
        } catch (ValidationException $e) {
            $failure = $this->backWithErrors("/products/{$id}/edit", $e->errors(), $request->all());
        } catch (\InvalidArgumentException $e) {
            $failure = $this->backWithErrors("/products/{$id}/edit", ['image' => $e->getMessage()], $request->all());
        }

        if ($failure !== null) {
            return $failure;
        }

        Session::flash('success', 'Produk berhasil diperbarui.');

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

        $this->products->setActive($id, $active);
        Session::flash('success', $active ? 'Produk diaktifkan.' : 'Produk dinonaktifkan.');

        return $this->redirect(self::LIST_PATH);
    }

    /**
     * POST /products/{id}/image - inline "Ganti Gambar" upload from the
     * image preview popup (table thumbnail, product detail photo). JSON
     * in/out rather than a redirect, since it's called via fetch() from
     * inside the modal, not a full form navigation.
     */
    public function updateImage(Request $request): string
    {
        return $this->imageUploadError($request) ?? $this->storeImage($request);
    }

    private function storeImage(Request $request): string
    {
        try {
            $product = $this->products->updateImage((int) $request->param('id'), $request->file('image'));

            return Response::json(['image' => $product->image]);
        } catch (NotFoundException) {
            return Response::json(['error' => 'Produk tidak ditemukan.'], 404);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Guard checks for updateImage(): returns the JSON error response, or
     * null when the caller may proceed.
     */
    private function imageUploadError(Request $request): ?string
    {
        $user = $this->auth->user();

        $failure = match (true) {
            $user === null => ['Unauthorized', 401],
            $user->role !== Role::Admin => ['Forbidden', 403],
            !Csrf::verify($request->post('_csrf')) => ['Sesi tidak valid, silakan muat ulang halaman.', 419],
            $request->file('image') === null => ['Pilih gambar terlebih dahulu.', 422],
            default => null,
        };

        return $failure === null ? null : Response::json(['error' => $failure[0]], $failure[1]);
    }
}
