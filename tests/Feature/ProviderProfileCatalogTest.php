<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProviderProfileCatalogTest extends TestCase
{
    public function test_shared_editor_catalog_contains_role_specific_examples_and_saved_responses(): void
    {
        $catalog = $this->withSession(['developer_authenticated' => true])->get('/developer')->assertOk()->viewData('catalog');
        foreach (['Teacher', 'Institute'] as $role) {
            $entries = array_values(array_filter($catalog, fn ($entry) => $entry['category'] === $role
                && in_array($entry['routeKey'], ['GET /profiles/me', 'PATCH /profiles/me/steps/{step}', 'POST /profiles/me/photo', 'POST /profiles/me/publish'], true)));
            $this->assertCount(8, $entries);
            foreach ($entries as $entry) {
                $this->assertNotNull($entry['mockResponse'], $entry['id']);
                $response = json_decode($entry['mockResponse']['body'], true, flags: JSON_THROW_ON_ERROR);
                if ($entry['routeKey'] === 'PATCH /profiles/me/steps/{step}') {
                    $this->assertSame($entry['pathDefaults']['step'], $response['step']);
                    $this->assertSame($response['step'] + 1, $response['profileDraft']['nextStep']);
                    if ($response['step'] === 2) {
                        $this->assertArrayHasKey($role === 'Institute' ? 'subjectIds' : 'primarySubjects', $entry['bodyExample']);
                    }
                    if ($response['step'] === 4) {
                        $this->assertArrayHasKey($role === 'Institute' ? 'startingRate' : 'ratePerHour', $entry['bodyExample']);
                    }
                }
                if ($entry['routeKey'] === 'POST /profiles/me/publish') {
                    $this->assertArrayHasKey($role === 'Institute' ? 'instituteProfileUrl' : 'teacherProfileUrl', $response);
                }
            }
        }
    }
}
