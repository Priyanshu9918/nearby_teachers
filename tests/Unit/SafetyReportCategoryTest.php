<?php

namespace Tests\Unit;

use App\Support\SafetyReportCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafetyReportCategoryTest extends TestCase
{
    public static function labels(): array
    {
        return [
            ['Fraud or scam', 'fraud'],
            ['Fake profile / not who they claim', 'fake_profile'],
            ['Inappropriate behavior', 'inappropriate'],
            ['Spam', 'spam'],
            ['Other', 'other'],
            ['  FAKE_PROFILE  ', 'fake_profile'],
            ['fraud_or_scam', 'fraud'],
        ];
    }

    #[DataProvider('labels')]
    public function test_screen_labels_normalize_to_supported_api_categories(string $label, string $expected): void
    {
        $this->assertSame($expected, SafetyReportCategory::normalize($label));
    }

    public function test_unknown_categories_are_left_for_validation_to_reject(): void
    {
        $this->assertSame('not-a-category', SafetyReportCategory::normalize('not-a-category'));
        $this->assertSame(null, SafetyReportCategory::normalize(null));
    }
}
