<?php

namespace Tests\Unit;

use App\Services\ProfileDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileDraftStepDataTest extends TestCase
{
    public static function providers(): array
    {
        return [['teacher'], ['institute']];
    }

    #[DataProvider('providers')]
    public function test_step_data_is_allowlisted_and_uses_the_account_specific_inputs(string $type): void
    {
        $user = (object) ['full_name' => 'Saved Name', 'email' => 'saved@example.test', 'terms_accepted_at' => '2026-10-28 10:00:00',
            'password_hash' => 'PRIVATE_PASSWORD', 'parental_consent_token' => 'PRIVATE_TOKEN'];
        $profile = (object) ['listing_name' => 'Public Name', 'contact_person' => 'Contact Person', 'is_profile_complete' => false,
            'completed_profile_steps' => '[1,2,3]', 'lesson_modes' => '["online"]', 'city' => 'Kanpur', 'district' => 'Kanpur Nagar',
            'rate_per_hour' => '600.00', 'starting_rate' => '700.00', 'trial_days' => 2, 'currency' => 'INR',
            'bio' => 'Teacher story', 'description' => 'Institute story', 'linkedin_url' => 'https://example.test/teacher',
            'website_url' => 'https://example.test/institute', 'instructors_text' => 'Teaching team', 'location_details' => '{"private":"PRIVATE_ENRICHMENT"}'];
        $draft = ProfileDraft::forOwner($profile, $user, $type, ['7', 9]);
        $this->assertSame(range(1, 6), array_column($draft['steps'], 'step'));
        $this->assertSame([true, true, true, false, false, false], array_column($draft['steps'], 'completed'));
        $this->assertSame('Saved Name', $draft['steps'][0]['data']['fullName']);
        $this->assertSame([7, 9], $draft['steps'][1]['data'][$type === 'teacher' ? 'primarySubjects' : 'subjectIds']);
        $this->assertSame(['online'], $draft['steps'][2]['data']['lessonModes']);
        $this->assertSame('Kanpur', $draft['steps'][2]['data']['city']);
        $this->assertFalse($draft['steps'][5]['data']['agreeToTerms']);
        if ($type === 'teacher') {
            $this->assertArrayNotHasKey('contactPerson', $draft['steps'][0]['data']);
            $this->assertArrayNotHasKey('description', $draft['steps'][4]['data']);
            $this->assertSame('600.00', $draft['steps'][3]['data']['ratePerHour']);
        } else {
            $this->assertSame('Contact Person', $draft['steps'][0]['data']['contactPerson']);
            $this->assertSame('700.00', $draft['steps'][3]['data']['startingRate']);
            $this->assertArrayNotHasKey('bio', $draft['steps'][4]['data']);
        }
        $this->assertStringNotContainsString('PRIVATE_', json_encode($draft));
        $this->assertArrayNotHasKey('steps', ProfileDraft::forProfile($profile));
    }

    public function test_missing_profiles_and_legacy_published_profiles_do_not_fabricate_saved_data(): void
    {
        $user = (object) ['full_name' => 'Registered Name', 'terms_accepted_at' => null];
        $draft = ProfileDraft::forOwner(null, $user, 'institute', []);
        $this->assertCount(6, $draft['steps']);
        $this->assertSame([], $draft['steps'][1]['data']['subjectIds']);
        $this->assertNull($draft['steps'][4]['data']['description']);
        $this->assertSame([], $draft['completedSteps']);
        $published = ProfileDraft::forOwner((object) ['is_profile_complete' => true], $user, 'institute', []);
        $this->assertTrue($published['steps'][5]['completed']);
        $this->assertFalse($published['steps'][5]['data']['agreeToTerms']);
        $user->terms_accepted_at = '2026-10-28 10:00:00';
        $published = ProfileDraft::forOwner((object) ['is_profile_complete' => true, 'lesson_modes' => ['online']], $user, 'institute', []);
        $this->assertTrue($published['steps'][5]['data']['agreeToTerms']);
        $this->assertSame(['online'], $published['steps'][2]['data']['lessonModes']);
    }
}
