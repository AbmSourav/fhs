<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /** This month's trading, and how it got here. */
    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'month'  => $this->dashboard->monthlyFigures(),
            'trends' => $this->dashboard->trends(),
        ]);
    }
}
