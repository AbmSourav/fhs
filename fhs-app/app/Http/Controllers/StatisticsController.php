<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /** Life-of-the-business trading and what the business holds. */
    public function __invoke(): Response
    {
        return Inertia::render('statistics/index', [
            'position' => $this->dashboard->allTimePosition(),
        ]);
    }
}
