<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfilesController;
use App\Services\ProviderPhotoRemoval;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderPhotoPublishingTest extends TestCase
{
    public static function providers(): array
    {
        return [['teacher'], ['institute']];
    }

    private function database(object $user, ?string $event, string $status): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('transactionLevel')->andReturn(1);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->andReturn($query);
        $query->shouldReceive('where')->with('id', $user->id)->andReturnSelf();
        $query->shouldReceive('lockForUpdate')->andReturnSelf();
        $query->shouldReceive('select')->andReturnSelf();
        $query->shouldReceive('update')->once()->with(Mockery::on(function ($data) use ($status): bool {
            return $data['avatar_status'] === $status && array_key_exists('avatar_url', $data)
                && ($status !== 'rejected' || $data['avatar_url'] === null);
        }))->andReturn(1);
        $query->shouldReceive('first')->andReturn($user);
        if ($event === null) {
            DB::shouldReceive('table')->with('business_activity')->never();

            return;
        }
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->andReturn($history);
        $history->shouldReceive('insertGetId')->once()->with(Mockery::on(function ($row) use ($event, $status): bool {
            $after = json_decode($row['after_data'], true);

            return $row['event'] === $event && $row['version'] === 1 && $after['avatar_status'] === $status
                && ! str_contains($row['after_data'], 'https://');
        }))->andReturn(1);
    }

    #[DataProvider('providers')]
    public function test_uploaded_provider_photos_are_approved_immediately_with_safe_history(string $type): void
    {
        Storage::fake('public');
        $user = (object) ['id' => 42, 'account_type' => $type, 'avatar_status' => 'approved'];
        $this->database($user, $type.'.avatar_submitted', 'approved');
        $image = UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $request = Request::create('/profiles/me/photo', 'POST', [], [], ['image' => $image]);
        $request->attributes->set('authenticated_user', $user);
        $response = app(ProfilesController::class)->uploadPhoto($request);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('approved', $response->getData(true)['user']['avatar_status']);
        $this->assertStringContainsString('now visible', $response->getData(true)['message']);
        $this->assertCount(1, Storage::disk('public')->allFiles($type.'-photos'));
    }

    #[DataProvider('providers')]
    public function test_avatar_url_endpoint_also_publishes_provider_photos(string $type): void
    {
        $user = (object) ['id' => 42, 'account_type' => $type, 'avatar_status' => 'approved'];
        $this->database($user, $type.'.avatar_submitted', 'approved');
        $request = Request::create('/auth/avatar', 'PATCH', ['avatarUrl' => 'https://example.test/photo.jpg']);
        $request->attributes->set('authenticated_user', $user);
        $response = app(AuthController::class)->setAvatar($request);
        $this->assertSame('approved', $response->getData(true)['user']['avatar_status']);
    }

    public function test_removal_clears_the_profile_reference_and_records_safe_history(): void
    {
        $user = (object) ['id' => 42, 'account_type' => 'teacher', 'avatar_status' => 'approved', 'avatar_url' => 'https://example.test/photo.jpg'];
        $this->database($user, 'provider.avatar_removed', 'rejected');
        $this->assertTrue(app(ProviderPhotoRemoval::class)->remove(42));
    }

    public function test_admin_view_offers_delete_for_providers_and_preserves_student_review(): void
    {
        $photos = new LengthAwarePaginator(collect([
            (object) ['id' => 1, 'full_name' => 'Provider Example', 'account_type' => 'teacher', 'avatar_url' => 'https://example.test/photo.jpg', 'updated_at' => null],
            (object) ['id' => 2, 'full_name' => 'Student Example', 'account_type' => 'learner', 'avatar_url' => 'https://example.test/student.jpg', 'updated_at' => null],
        ]), 2, 25);
        $html = view('admin.moderation.photos', ['photos' => $photos, 'errors' => new ViewErrorBag])->render();
        $this->assertSame(1, substr_count($html, '>Delete photo</button>'));
        $this->assertSame(1, substr_count($html, '>Approve</button>'));
        $this->assertSame(1, substr_count($html, '>Reject</button>'));
    }

    public function test_admin_photo_deletion_requires_an_admin_session(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->delete('/admin/moderation/photos/42')->assertRedirect(route('admin.login'));
        $this->deleteJson('/moderation/photos/42')->assertUnauthorized();
    }

    public function test_student_avatar_url_still_requires_review(): void
    {
        $user = (object) ['id' => 42, 'account_type' => 'learner', 'avatar_status' => 'pending'];
        $this->database($user, null, 'pending');
        $request = Request::create('/auth/avatar', 'PATCH', ['avatarUrl' => 'https://example.test/student.jpg']);
        $request->attributes->set('authenticated_user', $user);
        $response = app(AuthController::class)->setAvatar($request);
        $this->assertSame('pending', $response->getData(true)['user']['avatar_status']);
    }
}
