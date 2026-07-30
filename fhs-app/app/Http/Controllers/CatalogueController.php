<?php

namespace App\Http\Controllers;

use App\Services\BrandService;
use App\Services\CatalogueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueController extends Controller
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly BrandService $brands,
    ) {}

    public function index(): Response
    {
        return Inertia::render('catalogue/index', [
            'items' => $this->catalogue->listWithStock(),
        ]);
    }

    /** The setup form for adding catalogue items. */
    public function setup(): Response
    {
        return Inertia::render('catalogue/setup', [
            'types'  => $this->catalogue->typeOptions(),
            'brands' => $this->brands->activeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->catalogue->rules(),
            $this->catalogue->messages(),
        );

        // Duplicate detection throws ValidationException, which Inertia turns
        // into form errors on the redirect back.
        $item = $this->catalogue->create($data);

        return back()->with('success', "{$item->displayName()} added to the catalogue.");
    }
}
