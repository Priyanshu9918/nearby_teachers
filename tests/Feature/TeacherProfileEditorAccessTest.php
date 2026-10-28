<?php

namespace Tests\Feature;

use Tests\TestCase;

class TeacherProfileEditorAccessTest extends TestCase
{
    public function test_teacher_profile_editor_routes_require_authentication(): void
    {
        $this->getJson('/teacher/profile')->assertUnauthorized();
        $this->patchJson('/teacher/profile/steps/1', ['fullName' => 'Ravi Kumar'])->assertUnauthorized();
        $this->postJson('/teacher/profile/publish', ['agreeToTerms' => true])->assertUnauthorized();
        $this->postJson('/teacher/profile/photo', [])->assertUnauthorized();
    }

    public function test_step_route_only_accepts_the_five_editable_steps(): void
    {
        $this->patchJson('/teacher/profile/steps/6', ['agreeToTerms' => true])->assertNotFound();
    }
}
