<?php

namespace App\Http\Controllers;

use App\Services\CrmService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmController extends Controller
{
    public function __construct(
        private readonly CrmService $crm,
    ) {}

    /** Who is worth calling, and why. */
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate($this->crm->rules());

        $filter = $filters['filter'] ?? 'due';

        return Inertia::render('crm/index', [
            'customers' => $this->crm->paginate(
                $filter,
                $filters['days'] ?? null,
                $filters['min_orders'] ?? null,
            ),
            // Echoed back so the controls keep their values after a reload.
            'active' => [
                'filter'     => $filter,
                'days'       => $filters['days'] ?? null,
                'min_orders' => $filters['min_orders'] ?? null,
            ],
            'options' => $this->crm->filterOptions(),
        ]);
    }
}
