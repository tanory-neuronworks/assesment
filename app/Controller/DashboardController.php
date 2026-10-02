<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Entity\Role;
use App\Service\DashboardService;

final class DashboardController extends Controller
{
    public function __construct(Auth $auth, private readonly DashboardService $dashboard)
    {
        parent::__construct($auth);
    }

    public function index(): string
    {
        $user = $this->auth->requireLogin();

        $stats = match ($user->role) {
            Role::Admin => $this->dashboard->forAdmin(),
            Role::Sales => $this->dashboard->forSales($user),
            Role::WarehouseStaff => $this->dashboard->forWarehouse(),
        };

        return $this->view('dashboard.index', ['title' => 'Dashboard', 'user' => $user, 'stats' => $stats]);
    }
}
