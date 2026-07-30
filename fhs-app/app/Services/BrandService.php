<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandService
{
    /**
     * Validation rules for creating a brand.
     *
     * `slug` is absent deliberately — it is derived from the name, because it is
     * unique in the database and accepting it from a client would surface a raw
     * constraint violation instead of a readable validation error.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A brand name is required.',
        ];
    }

    /**
     * Create a brand from validated input.
     *
     * @throws ValidationException if a brand with this name already exists.
     */
    public function create(array $data): Brand
    {
        $name = trim((string) ($data['name'] ?? ''));

        $this->assertNameIsAvailable($name);

        return Brand::create([
            'name'      => $name,
            'slug'      => $this->uniqueSlugFor($name),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /** Active brands, for select inputs. */
    public function activeOptions(): Collection
    {
        return Brand::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Names are compared case-insensitively: without this, "Jamuna" and "jamuna"
     * could both exist and split one brand's products across two records.
     *
     * @throws ValidationException
     */
    private function assertNameIsAvailable(string $name): void
    {
        $exists = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'A brand with this name already exists.',
            ]);
        }
    }

    /**
     * Two different names can slugify identically ("Omera Gas" and "Omera-Gas"),
     * so append a counter rather than letting the unique index reject the insert.
     */
    private function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $suffix = 2;

        while (Brand::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
