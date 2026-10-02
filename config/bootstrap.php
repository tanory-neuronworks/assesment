<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\CustomerController;
use App\Controller\DashboardController;
use App\Controller\LookupController;
use App\Controller\ProductController;
use App\Controller\PurchaseOrderController;
use App\Controller\ReportController;
use App\Controller\SalesOrderController;
use App\Controller\SupplierController;
use App\Controller\UserController;
use App\Controller\WarehouseController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Router;
use App\Core\View;
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

EnvLoader::load(__DIR__ . '/../.env');

View::init(__DIR__ . '/../views');

$pdo = Database::connect([
    'host' => (string) EnvLoader::get('DB_HOST', 'mysql'),
    'port' => (string) EnvLoader::get('DB_PORT', '3306'),
    'name' => (string) EnvLoader::get('DB_NAME', 'inventory'),
    'user' => (string) EnvLoader::get('DB_USER', 'inventory_user'),
    'pass' => (string) EnvLoader::get('DB_PASS', ''),
]);

$userRepository = new MysqlUserRepository($pdo);
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

$auth = new Auth($userRepository);
$authService = new AuthService($userRepository);
$userService = new UserService($userRepository);
$categoryService = new CategoryService($categoryRepository);
$warehouseService = new WarehouseService($warehouseRepository);
$imageUploader = new ImageUploader(__DIR__ . '/../public/uploads');
$productService = new ProductService($productRepository, $categoryRepository, $productStockRepository, $imageUploader);
$supplierService = new SupplierService($supplierRepository);
$customerService = new CustomerService($customerRepository);
$purchaseOrderService = new PurchaseOrderService($purchaseOrderRepository, $supplierRepository, $warehouseRepository, $productRepository);
$goodsReceiptService = new GoodsReceiptService($transactionManager, $purchaseOrderRepository, $productStockRepository, $stockLedgerRepository);
$salesOrderService = new SalesOrderService($salesOrderRepository, $customerRepository, $warehouseRepository, $productRepository);
$goodsIssueService = new GoodsIssueService($transactionManager, $salesOrderRepository, $productStockRepository, $stockLedgerRepository);
$dashboardService = new DashboardService($productRepository, $purchaseOrderRepository, $salesOrderRepository);

$authController = new AuthController($auth, $authService);
$dashboardController = new DashboardController($auth, $dashboardService);
$userController = new UserController($auth, $userService);
$categoryController = new CategoryController($auth, $categoryService);
$warehouseController = new WarehouseController($auth, $warehouseService);
$productController = new ProductController($auth, $productService, $categoryService, $stockLedgerRepository);
$supplierController = new SupplierController($auth, $supplierService);
$customerController = new CustomerController($auth, $customerService);
$purchaseOrderController = new PurchaseOrderController(
    $auth,
    $purchaseOrderService,
    $goodsReceiptService,
    $warehouseService,
);
$salesOrderController = new SalesOrderController(
    $auth,
    $salesOrderService,
    $goodsIssueService,
    $warehouseService,
);
$reportController = new ReportController($auth, $stockLedgerRepository, $purchaseOrderRepository, $salesOrderRepository);
$apiController = new ApiController($auth, $productRepository, $productStockRepository);
$lookupController = new LookupController($auth, $productService, $supplierService, $customerService, $warehouseService, $categoryService);

$router = new Router();

$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/', [$dashboardController, 'index']);

$router->get('/users', [$userController, 'index']);
$router->get('/users/create', [$userController, 'create']);
$router->post('/users', [$userController, 'store']);
$router->get('/users/{id}/edit', [$userController, 'edit']);
$router->post('/users/{id}', [$userController, 'update']);
$router->post('/users/{id}/toggle-active', [$userController, 'toggleActive']);

$router->get('/categories', [$categoryController, 'index']);
$router->get('/categories/create', [$categoryController, 'create']);
$router->post('/categories', [$categoryController, 'store']);
$router->get('/categories/{id}/edit', [$categoryController, 'edit']);
$router->post('/categories/{id}', [$categoryController, 'update']);

$router->get('/warehouses', [$warehouseController, 'index']);
$router->get('/warehouses/create', [$warehouseController, 'create']);
$router->post('/warehouses', [$warehouseController, 'store']);
$router->get('/warehouses/{id}/edit', [$warehouseController, 'edit']);
$router->post('/warehouses/{id}', [$warehouseController, 'update']);
$router->post('/warehouses/{id}/toggle-active', [$warehouseController, 'toggleActive']);

$router->get('/products', [$productController, 'index']);
$router->get('/products/create', [$productController, 'create']);
$router->post('/products', [$productController, 'store']);
$router->get('/products/{id}', [$productController, 'show']);
$router->get('/products/{id}/edit', [$productController, 'edit']);
$router->post('/products/{id}', [$productController, 'update']);
$router->post('/products/{id}/toggle-active', [$productController, 'toggleActive']);
$router->post('/products/{id}/image', [$productController, 'updateImage']);

$router->get('/suppliers', [$supplierController, 'index']);
$router->get('/suppliers/create', [$supplierController, 'create']);
$router->post('/suppliers', [$supplierController, 'store']);
$router->get('/suppliers/{id}/edit', [$supplierController, 'edit']);
$router->post('/suppliers/{id}', [$supplierController, 'update']);
$router->post('/suppliers/{id}/toggle-active', [$supplierController, 'toggleActive']);

$router->get('/customers', [$customerController, 'index']);
$router->get('/customers/create', [$customerController, 'create']);
$router->post('/customers', [$customerController, 'store']);
$router->get('/customers/{id}/edit', [$customerController, 'edit']);
$router->post('/customers/{id}', [$customerController, 'update']);
$router->post('/customers/{id}/toggle-active', [$customerController, 'toggleActive']);

$router->get('/purchase-orders', [$purchaseOrderController, 'index']);
$router->get('/purchase-orders/create', [$purchaseOrderController, 'create']);
$router->post('/purchase-orders', [$purchaseOrderController, 'store']);
$router->get('/purchase-orders/{id}', [$purchaseOrderController, 'show']);
$router->post('/purchase-orders/{id}/receive', [$purchaseOrderController, 'receive']);
$router->post('/purchase-orders/{id}/cancel', [$purchaseOrderController, 'cancel']);

$router->get('/sales-orders', [$salesOrderController, 'index']);
$router->get('/sales-orders/create', [$salesOrderController, 'create']);
$router->post('/sales-orders', [$salesOrderController, 'store']);
$router->get('/sales-orders/{id}', [$salesOrderController, 'show']);
$router->post('/sales-orders/{id}/submit', [$salesOrderController, 'submit']);
$router->post('/sales-orders/{id}/approve', [$salesOrderController, 'approve']);
$router->post('/sales-orders/{id}/reject', [$salesOrderController, 'reject']);
$router->post('/sales-orders/{id}/cancel', [$salesOrderController, 'cancel']);
$router->post('/sales-orders/{id}/issue', [$salesOrderController, 'issue']);

$router->get('/reports/stock-ledger', [$reportController, 'stockLedger']);
$router->get('/reports/orders', [$reportController, 'orders']);

$router->get('/api/products/{sku}/availability', [$apiController, 'productAvailability']);
$router->get('/api/lookup/{type}', [$lookupController, 'search']);

return [
    'router' => $router,
    'auth' => $auth,
];
