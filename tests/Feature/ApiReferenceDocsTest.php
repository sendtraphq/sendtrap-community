<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The /docs/api/* routes expose the OpenAPI contract shipped inside
 * sendtrap/core. On a core version that predates the openapi/ directory
 * they must 404 cleanly (never 500), and once the contract ships they must
 * serve it — the assertions key off the installed vendor tree so the suite
 * is green on both sides of the composer bump.
 */
class ApiReferenceDocsTest extends TestCase
{
    public function test_unknown_contract_formats_are_rejected(): void
    {
        $this->get('/docs/api/openapi.xml')->assertNotFound();
    }

    public function test_contract_routes_track_the_installed_core_version(): void
    {
        $shipped = file_exists(base_path('vendor/sendtrap/core/openapi/sendtrap.yaml'));
        $expected = $shipped ? 200 : 404;

        $this->get('/docs/api/reference')->assertStatus($expected);
        $this->get('/docs/api/openapi.yaml')->assertStatus($expected);
        $this->get('/docs/api/openapi.json')->assertStatus($expected);
        $this->get('/docs/api/sendtrap.postman_collection.json')->assertStatus($expected);
    }

    public function test_reference_page_embeds_the_contract_url_when_shipped(): void
    {
        if (! file_exists(base_path('vendor/sendtrap/core/openapi/sendtrap.yaml'))) {
            $this->markTestSkipped('installed sendtrap/core predates the shipped OpenAPI contract');
        }

        $this->get('/docs/api/reference')
            ->assertOk()
            ->assertSee('/docs/api/openapi.yaml')
            ->assertSee('/vendor/scalar/standalone.js');
    }
}
