<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class SaleFlowTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_une_vente_complete_se_cree_via_l_api(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct(retail: 500, stockQty: 50);

        $sale = $this->makeSaleViaApi($cashier, $product, qty: 3);

        $this->assertSame(1500, $sale['total']);
        $this->assertStringStartsWith('VTE-', $sale['receipt_number']);
        $this->assertSame($cashier->name, $sale['cashier']);
        $this->assertCount(1, $sale['items']);
        $this->assertSame(47, $product->stock->fresh()->quantity);
    }
}
