<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPagasaWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_matching_active_pagasa_warnings_once_and_leaves_them_pending(): void
    {
        $this->fakePagasaPages(
            '<div class="article-content"><p>Weather Advisory for Oriental Mindoro. Issued at: 4:00 PM, 27 October 2026.</p></div>',
        );

        $this->artisan('pagasa:sync-warnings')->assertSuccessful();
        $this->artisan('pagasa:sync-warnings')->assertSuccessful();

        $this->assertDatabaseCount('hazard_sources', 1);
        $this->assertDatabaseHas('hazard_sources', [
            'organization' => 'PAGASA',
            'title' => 'PAGASA Weather Advisory — Bongabong coverage',
            'verification_status' => 'pending',
            'created_by' => null,
            'source_url' => 'https://bagong.pagasa.dost.gov.ph/weather/weather-advisory',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => null,
            'action' => 'hazard_source.imported',
        ]);
    }

    public function test_it_ignores_nonregional_and_inactive_pagasa_notices(): void
    {
        $this->fakePagasaPages(
            '<div class="article-content"><p>Weather Advisory for Palawan only. Issued at: 4:00 PM, 27 October 2026.</p></div>',
            '<div class="article-content"><p>No active Gale Warning is in effect for MIMAROPA.</p></div>',
        );

        $this->artisan('pagasa:sync-warnings')->assertSuccessful();

        $this->assertDatabaseCount('hazard_sources', 0);
    }

    public function test_it_surfaces_pagasa_server_errors_instead_of_silently_skipping_them(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'bagong.pagasa.dost.gov.ph/*' => Http::response('PAGASA unavailable', 503),
        ]);

        $this->expectException(RequestException::class);

        $this->artisan('pagasa:sync-warnings');
    }

    private function fakePagasaPages(
        string $weatherArticle,
        string $otherPageArticle = '<div class="article-content"><p>No active warning is currently issued.</p></div>',
    ): void {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($weatherArticle, $otherPageArticle) {
            $content = str_ends_with($request->url(), '/weather/weather-advisory')
                ? $weatherArticle
                : $otherPageArticle;

            return Http::response($content, 200, ['Content-Type' => 'text/html']);
        });
    }
}
