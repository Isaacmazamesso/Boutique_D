<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesShopData;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase, CreatesShopData;

    // ── Ventes ───────────────────────────────────────────────────────────

    public function test_le_rapport_ventes_json_est_inchange_apres_le_refactor(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/reports/sales?period=today');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(1000, $data['resume']['total_ca']);
        $this->assertSame(1, $data['resume']['nb_ventes']);
        $this->assertCount(1, $data['ventes']);
        $this->assertArrayHasKey('periode', $data);
        $this->assertArrayHasKey('par_type', $data);
        $this->assertArrayHasKey('par_paiement', $data);
    }

    public function test_le_pdf_du_rapport_ventes_est_genere(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/sales/pdf?period=today');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_le_pdf_du_rapport_ventes_exige_le_role_proprietaire(): void
    {
        $cashier = $this->makeUser('caissier');

        Sanctum::actingAs($cashier);
        $this->getJson('/api/reports/sales/pdf?period=today')->assertForbidden();
    }

    public function test_l_excel_du_rapport_ventes_est_genere(): void
    {
        $owner   = $this->makeUser('proprietaire');
        $product = $this->makeProduct(retail: 500);
        $this->makeSaleViaApi($owner, $product, qty: 2);

        Sanctum::actingAs($owner);
        $response = $this->get('/api/reports/sales/excel?period=today');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }
}
