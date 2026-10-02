<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../Support/ResponseCapture.php';

use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\CustomerController;
use App\Controller\DashboardController;
use App\Controller\ErrorController;
use App\Controller\LookupController;
use App\Controller\ProductController;
use App\Controller\PurchaseOrderController;
use App\Controller\ReportController;
use App\Controller\SalesOrderController;
use App\Controller\SupplierController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Repository\Mysql\PdoTransactionManager;
use App\Service\AuthService;
use App\Service\CategoryService;
use App\Service\CustomerService;
use App\Service\DashboardService;
use App\Service\GoodsIssueService;
use App\Service\GoodsReceiptService;
use App\Service\ImageUploader;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\SupplierService;
use App\Service\UserService;
use App\Service\WarehouseService;
use Tests\Support\ResponseCapture;

/**
 * Value object returned by ControllerTestCase::request().
 */
final class HttpResult
{
    /**
     * @param array<string,string> $headers lower-cased name => value
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    public function location(): ?string
    {
        return $this->headers['location'] ?? null;
    }

    public function isRedirect(): bool
    {
        return $this->location() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function json(): array
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Base class for controller-level integration tests.
 *
 * Wires the real Router + controllers + services + MySQL repositories (same
 * graph as config/bootstrap.php) on top of the transactional PDO from
 * IntegrationTestCase, so every DB write made through a controller is rolled
 * back in tearDown. Session is the plain $_SESSION array (no session_start).
 */
abstract class ControllerTestCase extends IntegrationTestCase
{
    protected Router $router;
    protected Auth $auth;
    protected MysqlUserRepository $userRepository;
    protected UserService $userService;
    protected CategoryService $categoryService;
    protected ProductService $productService;
    protected string $uploadDir;

    private ErrorController $errorController;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $_GET = $_POST = $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        ResponseCapture::reset();

        View::init(__DIR__ . '/../../views');
        $this->uploadDir = sys_get_temp_dir() . '/ctrl_test_uploads_' . uniqid();
        $this->wire();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = $_POST = $_FILES = [];
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        ResponseCapture::reset();

        parent::tearDown();
    }

    private function wire(): void
    {
        $pdo = $this->pdo;

        $this->userRepository = new MysqlUserRepository($pdo);
        $warehouseRepository = new MysqlWarehouseRepository($pdo);
        $categoryRepository = new MysqlCategoryRepository($pdo);
        $productRepository = new MysqlProductRepository($pdo);
        $productStockRepository = new MysqlProductStockRepository($pdo);
        $supplierRepository = new MysqlSupplierRepository($pdo);
        $customerRepository = new MysqlCustomerRepository($pdo);
        $purchaseOrderRepository = new MysqlPurchaseOrderRepository($pdo);
        $salesOrderRepository = new MysqlSalesOrderRepository($pdo);
        $stockLedgerRepository = new MysqlStockLedgerRepository($pdo);
        $transactionManager = new PdoTransactionManager($pdo);

        $this->auth = new Auth($this->userRepository);
        $authService = new AuthService($this->userRepository);
        $this->userService = new UserService($this->userRepository);
        $this->categoryService = new CategoryService($categoryRepository);
        $warehouseService = new WarehouseService($warehouseRepository);
        $imageUploader = new ImageUploader($this->uploadDir);
        $this->productService = new ProductService($productRepository, $categoryRepository, $productStockRepository, $imageUploader);
        $supplierService = new SupplierService($supplierRepository);
        $customerService = new CustomerService($customerRepository);
        $purchaseOrderService = new PurchaseOrderService($purchaseOrderRepository, $supplierRepository, $warehouseRepository, $productRepository);
        $goodsReceiptService = new GoodsReceiptService($transactionManager, $purchaseOrderRepository, $productStockRepository, $stockLedgerRepository);
        $salesOrderService = new SalesOrderService($salesOrderRepository, $customerRepository, $warehouseRepository, $productRepository);
        $goodsIssueService = new GoodsIssueService($transactionManager, $salesOrderRepository, $productStockRepository, $stockLedgerRepository);
        $dashboardService = new DashboardService($productRepository, $purchaseOrderRepository, $salesOrderRepository);

        $auth = $this->auth;
        $authController = new AuthController($auth, $authService);
        $dashboardController = new DashboardController($auth, $dashboardService);
        $userController = new UserController($auth, $this->userService);
        $categoryController = new CategoryController($auth, $this->categoryService);
        $warehouseController = new WarehouseController($auth, $warehouseService);
        $productController = new ProductController($auth, $this->productService, $this->categoryService, $stockLedgerRepository);
        $supplierController = new SupplierController($auth, $supplierService);
        $customerController = new CustomerController($auth, $customerService);
        $purchaseOrderController = new PurchaseOrderController($auth, $purchaseOrderService, $goodsReceiptService, $warehouseService);
        $salesOrderController = new SalesOrderController($auth, $salesOrderService, $goodsIssueService, $warehouseService);
        $reportController = new ReportController($auth, $stockLedgerRepository, $purchaseOrderRepository, $salesOrderRepository);
        $apiController = new ApiController($auth, $productRepository, $productStockRepository);
        $lookupController = new LookupController($auth, $this->productService, $supplierService, $customerService, $warehouseService, $this->categoryService);
        $this->errorController = new ErrorController($auth);

        $r = $this->router = new Router();

        $r->get('/login', [$authController, 'showLogin']);
        $r->post('/login', [$authController, 'login']);
        $r->post('/logout', [$authController, 'logout']);
        $r->get('/', [$dashboardController, 'index']);

        $r->get('/users', [$userController, 'index']);
        $r->get('/users/create', [$userController, 'create']);
        $r->post('/users', [$userController, 'store']);
        $r->get('/users/{id}/edit', [$userController, 'edit']);
        $r->post('/users/{id}', [$userController, 'update']);
        $r->post('/users/{id}/toggle-active', [$userController, 'toggleActive']);

        $r->get('/categories', [$categoryController, 'index']);
        $r->get('/categories/create', [$categoryController, 'create']);
        $r->post('/categories', [$categoryController, 'store']);
        $r->get('/categories/{id}/edit', [$categoryController, 'edit']);
        $r->post('/categories/{id}', [$categoryController, 'update']);

        $r->get('/warehouses', [$warehouseController, 'index']);
        $r->get('/warehouses/create', [$warehouseController, 'create']);
        $r->post('/warehouses', [$warehouseController, 'store']);
        $r->get('/warehouses/{id}/edit', [$warehouseController, 'edit']);
        $r->post('/warehouses/{id}', [$warehouseController, 'update']);
        $r->post('/warehouses/{id}/toggle-active', [$warehouseController, 'toggleActive']);

        $r->get('/products', [$productController, 'index']);
        $r->get('/products/create', [$productController, 'create']);
        $r->post('/products', [$productController, 'store']);
        $r->get('/products/{id}', [$productController, 'show']);
        $r->get('/products/{id}/edit', [$productController, 'edit']);
        $r->post('/products/{id}', [$productController, 'update']);
        $r->post('/products/{id}/toggle-active', [$productController, 'toggleActive']);
        $r->post('/products/{id}/image', [$productController, 'updateImage']);

        $r->get('/suppliers', [$supplierController, 'index']);
        $r->get('/suppliers/create', [$supplierController, 'create']);
        $r->post('/suppliers', [$supplierController, 'store']);
        $r->get('/suppliers/{id}/edit', [$supplierController, 'edit']);
        $r->post('/suppliers/{id}', [$supplierController, 'update']);
        $r->post('/suppliers/{id}/toggle-active', [$supplierController, 'toggleActive']);

        $r->get('/customers', [$customerController, 'index']);
        $r->get('/customers/create', [$customerController, 'create']);
        $r->post('/customers', [$customerController, 'store']);
        $r->get('/customers/{id}/edit', [$customerController, 'edit']);
        $r->post('/customers/{id}', [$customerController, 'update']);
        $r->post('/customers/{id}/toggle-active', [$customerController, 'toggleActive']);

        $r->get('/purchase-orders', [$purchaseOrderController, 'index']);
        $r->get('/purchase-orders/create', [$purchaseOrderController, 'create']);
        $r->post('/purchase-orders', [$purchaseOrderController, 'store']);
        $r->get('/purchase-orders/{id}', [$purchaseOrderController, 'show']);
        $r->post('/purchase-orders/{id}/receive', [$purchaseOrderController, 'receive']);
        $r->post('/purchase-orders/{id}/cancel', [$purchaseOrderController, 'cancel']);

        $r->get('/sales-orders', [$salesOrderController, 'index']);
        $r->get('/sales-orders/create', [$salesOrderController, 'create']);
        $r->post('/sales-orders', [$salesOrderController, 'store']);
        $r->get('/sales-orders/{id}', [$salesOrderController, 'show']);
        $r->post('/sales-orders/{id}/submit', [$salesOrderController, 'submit']);
        $r->post('/sales-orders/{id}/approve', [$salesOrderController, 'approve']);
        $r->post('/sales-orders/{id}/reject', [$salesOrderController, 'reject']);
        $r->post('/sales-orders/{id}/cancel', [$salesOrderController, 'cancel']);
        $r->post('/sales-orders/{id}/issue', [$salesOrderController, 'issue']);

        $r->get('/reports/stock-ledger', [$reportController, 'stockLedger']);
        $r->get('/reports/orders', [$reportController, 'orders']);

        $r->get('/api/products/{sku}/availability', [$apiController, 'productAvailability']);
        $r->get('/api/lookup/{type}', [$lookupController, 'search']);
    }

    // ------------------------------------------------------------------
    // Users / session
    // ------------------------------------------------------------------

    /**
     * Creates a user via UserService (rolled back in tearDown).
     *
     * @return array{user:User,password:string}
     */
    protected function createUser(Role $role = Role::Admin, string $password = 'correct-password'): array
    {
        $tag = uniqid();
        $user = $this->userService->create([
            'name' => 'Ctrl Test ' . $role->value . ' ' . $tag,
            'username' => 'ctrl_' . $tag,
            'email' => 'ctrl_' . $tag . '@example.test',
            'role' => $role->value,
            'password' => $password,
        ]);

        return ['user' => $user, 'password' => $password];
    }

    /**
     * Creates a user with the given role and logs them in exactly as
     * AuthController::login does (Auth::login -> session user_id).
     */
    protected function loginAs(Role $role = Role::Admin): User
    {
        $user = $this->createUser($role)['user'];
        $this->auth->login($user);

        return $user;
    }

    /**
     * Clears the whole fake session (user, CSRF token, flashes).
     */
    protected function logout(): void
    {
        $_SESSION = [];
    }

    /**
     * A CSRF token valid for the current fake session.
     */
    protected function csrfToken(): string
    {
        return Csrf::token();
    }

    /**
     * @return array<string,string> flash messages currently in session (not consumed)
     */
    protected function flashes(): array
    {
        return $_SESSION['_flash'] ?? [];
    }

    /**
     * @return array<string,string> validation errors stored in session (not consumed)
     */
    protected function sessionErrors(): array
    {
        return $_SESSION['_errors'] ?? [];
    }

    /**
     * @return array<string,mixed> old input stored in session (not consumed)
     */
    protected function sessionOldInput(): array
    {
        return $_SESSION['_old_input'] ?? [];
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    /**
     * Dispatches through the real Router and maps the framework exceptions
     * the way public/index.php does: Unauthenticated -> 302 /login,
     * Forbidden -> 403 page, NotFound -> 404 page. Any other \Throwable
     * propagates so a real bug fails the test instead of becoming a 500.
     *
     * @param array<string,mixed> $post   POST body (see postWithCsrf() to add a valid token)
     * @param array<string,mixed> $query  GET query string params
     * @param array<string,array<string,mixed>> $files $_FILES-style entries
     */
    protected function request(
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        bool $ajax = false,
        array $files = [],
    ): HttpResult {
        try {
            return $this->requestRaw($method, $path, $post, $query, $ajax, $files);
        } catch (UnauthenticatedException) {
            ResponseCapture::$headers['location'] = '/login';
            ResponseCapture::$status = 302;

            return $this->httpResult('');
        } catch (ForbiddenException) {
            return $this->httpResult($this->errorController->forbidden());
        } catch (NotFoundException) {
            return $this->httpResult($this->errorController->notFound());
        }
    }

    /**
     * Like request() but lets ForbiddenException / UnauthenticatedException /
     * NotFoundException propagate so tests can use expectException().
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $query
     * @param array<string,array<string,mixed>> $files
     */
    protected function requestRaw(
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        bool $ajax = false,
        array $files = [],
    ): HttpResult {
        ResponseCapture::reset();

        $_GET = $query;
        $_POST = $post;
        $_FILES = $files;
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $path . ($query === [] ? '' : '?' . http_build_query($query));
        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        } else {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        $request = new Request();
        $body = $this->router->dispatch($request->method(), $request->path(), $request);

        return $this->httpResult($body);
    }

    /**
     * POST with a valid CSRF token merged into the body.
     *
     * @param array<string,mixed> $post
     * @param array<string,array<string,mixed>> $files
     */
    protected function postWithCsrf(string $path, array $post = [], bool $ajax = false, array $files = []): HttpResult
    {
        return $this->request('POST', $path, ['_csrf' => $this->csrfToken()] + $post, [], $ajax, $files);
    }

    /**
     * @param array<string,mixed> $query
     */
    protected function get(string $path, array $query = [], bool $ajax = false): HttpResult
    {
        return $this->request('GET', $path, [], $query, $ajax);
    }

    private function httpResult(string $body): HttpResult
    {
        return new HttpResult(ResponseCapture::$status, $body, ResponseCapture::$headers);
    }

    // ------------------------------------------------------------------
    // Assertions / fixtures
    // ------------------------------------------------------------------

    protected function assertRedirectTo(string $location, HttpResult $result): void
    {
        $this->assertSame(302, $result->status, 'Expected a redirect (302).');
        $this->assertSame($location, $result->location());
    }

    protected function assertFlash(string $type, string $message): void
    {
        $this->assertSame($message, $this->flashes()[$type] ?? null, "Flash '{$type}' mismatch.");
    }

    /**
     * Builds a $_FILES-style entry backed by a temp file with the given content.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
     */
    protected function fakeUpload(string $content, string $name = 'x.txt', int $error = UPLOAD_ERR_OK): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $content);

        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
    }
}
