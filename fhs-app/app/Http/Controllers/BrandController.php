<?php

namespace App\Http\Controllers;

use App\Services\BrandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(
        private readonly BrandService $brands,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            $this->brands->rules(),
            $this->brands->messages(),
        );

        $brand = $this->brands->create($data);

        // Redirect back so the setup page reloads with the new brand in its
        // select options.
        return back()->with('success', "{$brand->name} added.");
    }
}
