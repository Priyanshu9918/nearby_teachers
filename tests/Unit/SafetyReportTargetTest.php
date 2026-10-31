<?php

namespace Tests\Unit;

use App\Support\SafetyReportTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafetyReportTargetTest extends TestCase
{
    public static function unusableTargets(): array
    {
        return [
            [null, null],
            ['unexpected', 42],
            ['message', 'not-a-number'],
            ['user', 0],
            ['user', 9007199254740992],
        ];
    }

    #[DataProvider('unusableTargets')]
    public function test_missing_or_unsupported_target_is_saved_unlinked(mixed $type, mixed $id): void
    {
        $this->assertSame([null, null], SafetyReportTarget::normalize($type, $id));
    }

    public function test_valid_target_is_preserved_for_admin_context(): void
    {
        $this->assertSame(['message', 123], SafetyReportTarget::normalize(' Message ', '123'));
    }
}
