<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Catalogue;
use App\Models\GasInventoryPurchase;
use App\Models\User;
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
}
