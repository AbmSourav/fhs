<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\GasInventoryPurchase;
use App\Models\User;
use App\Services\CatalogueService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording stock purchases.
 *
 * Stock is the sum of the movement log, so these assert the derived counts
 * rather than the purchase rows themselves.
 */
class InventoryPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InventoryService $inventory;

    private Catalogue $cylinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Inventory routes are behind the `admin` gate, which reads a config
        // list of email addresses rather than a role column.
        config(['app.admin_emails' => [strtolower($this->user->email)]]);

        $this->inventory = app(InventoryService::class);

        $brand = Brand::create(['name' => 'Jamuna', 'slug' => 'jamuna']);
        $this->cylinder = Catalogue::create([
            'type'          => 'lpg_cylinder',
            'brand_id'      => $brand->id,
            'weight'        => 12.5,
            'is_gas'        => true,
            'is_returnable' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function purchase(array $overrides = []): array
    {
        return [
            'catalogue_id'    => $this->cylinder->id,
            'purchased_at'    => now()->toDateString(),
            'filled_quantity' => 10,
            'empty_quantity'  => 5,
            'shell_unit_cost' => 900,
            'gas_unit_cost'   => 340,
            ...$overrides,
        ];
    }

    public function test_a_purchase_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->user)
            ->post('/inventories', $this->purchase([
                // Stock that has not arrived yet must not count toward what is
                // on the shelf.
                'purchased_at' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('purchased_at');

        $this->assertSame(0, GasInventoryPurchase::count());
    }

    public function test_a_purchase_can_be_dated_today(): void
    {
        $this->actingAs($this->user)
            ->post('/inventories', $this->purchase())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, GasInventoryPurchase::count());
    }

    public function test_a_purchase_can_be_backdated(): void
    {
        // Goods that arrived last week, entered today.
        $this->actingAs($this->user)
            ->post('/inventories', $this->purchase([
                'purchased_at' => now()->subWeek()->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, GasInventoryPurchase::count());
    }

    public function test_a_purchase_puts_stock_on_the_books(): void
    {
        $this->inventory->record($this->purchase(), $this->user->id);

        $stock = Catalogue::withStock()->find($this->cylinder->id);

        $this->assertSame(10, $stock->filledStock());
        $this->assertSame(5, $stock->emptyStock());
    }

    public function test_the_catalogue_reports_the_average_cost_across_every_purchase(): void
    {
        $this->inventory->record($this->purchase(), $this->user->id);

        // A second delivery at a higher price moves the average.
        $this->inventory->record($this->purchase([
            'filled_quantity' => 10,
            'empty_quantity'  => 0,
            'gas_unit_cost'   => 440,
            'shell_unit_cost' => 1100,
        ]), $this->user->id);

        $item = app(CatalogueService::class)->listWithStock()->first();

        // Gas: (340 x 10 + 440 x 10) / 20.
        $this->assertSame(390.0, $item['average_gas_cost']);
        // Shell: (900 x 15 + 1100 x 10) / 25.
        $this->assertSame(980.0, $item['average_shell_cost']);
    }

    public function test_the_average_ignores_superseded_versions_of_a_purchase(): void
    {
        $purchase = $this->inventory->record($this->purchase(), $this->user->id);

        $this->inventory->edit($purchase, $this->purchase([
            'gas_unit_cost' => 400,
        ]), $this->user->id);

        $item = app(CatalogueService::class)->listWithStock()->first();

        // Corrections append a replacement row, so without current() the
        // original 340 would be averaged in alongside the corrected 400.
        $this->assertSame(400.0, $item['average_gas_cost']);
    }

    public function test_a_swap_purchase_does_not_dilute_the_shell_average(): void
    {
        $this->inventory->record($this->purchase(), $this->user->id);

        // A refill acquires no shells, though the row still carries a cost.
        $this->inventory->record($this->purchase([
            'swap_catalogue_id' => $this->cylinder->id,
            'filled_quantity'   => 5,
            'empty_quantity'    => 5,
            'shell_unit_cost'   => 2000,
        ]), $this->user->id);

        $item = app(CatalogueService::class)->listWithStock()->first();

        // Still the 900 from the only purchase that actually bought cylinders.
        $this->assertSame(900.0, $item['average_shell_cost']);
    }

    public function test_a_product_never_purchased_has_no_cost_basis(): void
    {
        $item = app(CatalogueService::class)->listWithStock()->first();

        // Nothing bought yet is a cost of zero, not a division by zero.
        $this->assertSame(0.0, $item['average_gas_cost']);
        $this->assertSame(0.0, $item['average_shell_cost']);
    }
}
