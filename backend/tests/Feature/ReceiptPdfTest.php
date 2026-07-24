<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    public function test_le_pdf_du_recu_est_genere(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        Sanctum::actingAs($cashier);
        $response = $this->get("/api/sales/{$sale['id']}/receipt-pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_la_vue_du_recu_contient_caissier_et_numero(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        $html = view('receipts.sale', ['sale' => $sale])->render();

        $this->assertStringContainsString($sale['receipt_number'], $html);
        $this->assertStringContainsString($cashier->name, $html);
        $this->assertStringContainsString('Boutique D', $html);
    }

    public function test_le_pdf_exige_une_authentification(): void
    {
        $cashier = $this->makeUser('caissier');
        $product = $this->makeProduct();
        $sale    = $this->makeSaleViaApi($cashier, $product);

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/sales/{$sale['id']}/receipt-pdf")->assertUnauthorized();
    }
}
