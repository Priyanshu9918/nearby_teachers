<?php

namespace Tests\Feature;

use Tests\TestCase;

class NumericIdRoutesTest extends TestCase
{
    public function test_old_or_malformed_record_ids_are_rejected_before_controllers_and_database_queries(): void
    {
        $this->withSession(['admin_authenticated' => true]);
        foreach (['old-record', '00000000-0000-4000-8000-000000000001', '0', '-1', '1.5', str_repeat('9', 30)] as $id) {
            $this->get('/admin/teachers/'.$id.'/edit')->assertNotFound();
            $this->put('/admin/teachers/'.$id)->assertNotFound();
            $this->delete('/admin/teachers/'.$id)->assertNotFound();
            $this->getJson('/profiles/teacher/'.$id)->assertNotFound();
            $this->getJson('/feed/'.$id.'/comments')->assertNotFound();
        }
    }
}
