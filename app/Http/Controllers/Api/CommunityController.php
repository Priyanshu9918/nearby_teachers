<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationEmail;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate(['city' => ['required', 'string', 'max:160']]);
        $user = $request->attributes->get('authenticated_user');
        $posts = DB::table('posts as p')
            ->join('users as u', 'u.id', '=', 'p.author_id')
            ->where('p.city', $data['city'])
            ->select('p.*', 'u.full_name as author_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS author_avatar")
            ->selectSub(DB::table('post_likes')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'like_count')
            ->selectSub(DB::table('post_comments')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'comment_count')
            ->selectRaw('EXISTS (SELECT 1 FROM post_likes WHERE post_id = p.id AND user_id = ?) AS liked_by_me', [$user->id])
            ->orderByDesc('p.created_at')->orderByDesc('p.id');

        return CursorPage::respond($request, $posts, 'posts');
    }

    public function createPost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:160'],
            'subjectId' => ['nullable', 'integer', 'exists:subjects,id'],
            'body' => ['required', 'string', 'max:5000'],
            'imageUrl' => ['nullable', 'url', 'max:2048'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $post = DB::transaction(function () use ($data, $user): array|JsonResponse {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $recent = DB::table('posts')->where('author_id', $user->id)
                ->where('created_at', '>', now()->subDays(7))
                ->orderByDesc('created_at')
                ->value('created_at');
            if ($recent !== null) {
                return response()->json([
                    'error' => 'You can post once every 7 days',
                    'nextAllowedAt' => Carbon::parse($recent)->addDays(7)->toIso8601String(),
                ], 429);
            }

            $id = DB::table('posts')->insertGetId([
                'author_id' => $user->id,
                'post_type' => $user->account_type === 'learner' ? 'request' : 'offer',
                'city' => $data['city'],
                'subject_id' => $data['subjectId'] ?? null,
                'body' => $data['body'],
                'image_url' => $data['imageUrl'] ?? null,
                'created_at' => now(),
            ]);

            return ['post' => DB::table('posts')->where('id', $id)->first()];
        });

        return $post instanceof JsonResponse ? $post : response()->json($post);
    }

    public function like(Request $request, int $postId): JsonResponse
    {
        DB::table('post_likes')->insertOrIgnore([
            'post_id' => $postId,
            'user_id' => $request->attributes->get('authenticated_user')->id,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function unlike(Request $request, int $postId): JsonResponse
    {
        DB::table('post_likes')->where('post_id', $postId)
            ->where('user_id', $request->attributes->get('authenticated_user')->id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function comments(Request $request, int $postId): JsonResponse
    {
        $comments = DB::table('post_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.post_id', $postId)
            ->select('c.*', 'u.full_name as author_name')
            ->orderBy('c.created_at')->orderBy('c.id');

        return CursorPage::respond($request, $comments, 'comments');
    }

    public function createComment(Request $request, int $postId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parentCommentId' => ['nullable', 'integer', 'min:1', 'max:9007199254740991', 'exists:post_comments,id'],
        ]);
        if (! DB::table('posts')->where('id', $postId)->exists()) {
            return response()->json(['error' => 'Post not found'], 404);
        }
        if (isset($data['parentCommentId']) && ! DB::table('post_comments')
            ->where('id', $data['parentCommentId'])->where('post_id', $postId)->exists()) {
            return response()->json(['error' => 'Parent comment not found for this post'], 404);
        }
        $user = $request->attributes->get('authenticated_user');
        $id = DB::table('post_comments')->insertGetId([
            'post_id' => $postId,
            'user_id' => $user->id,
            'parent_comment_id' => $data['parentCommentId'] ?? null,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        $comment = DB::table('post_comments')->where('id', $id)->first();
        if (isset($data['parentCommentId'])) {
            $parent = DB::table('post_comments as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.id', $data['parentCommentId'])
                ->select('c.user_id', 'u.email')
                ->first();
            if ($parent !== null && $parent->user_id !== $user->id && $parent->email !== null) {
                $this->sendReplyNotification($parent->email, $user->full_name, $data['body']);
            }
        }

        return response()->json(['comment' => $comment]);
    }

    public function createReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bookingId' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'exists:bookings,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $review = DB::transaction(function () use ($data, $user): ?object {
            $booking = DB::table('bookings')->where('id', $data['bookingId'])
                ->where('learner_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($booking === null) {
                return null;
            }
            $id = DB::table('reviews')->insertGetId([
                'booking_id' => $booking->id,
                'learner_id' => $user->id,
                'provider_id' => $booking->provider_id,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'created_at' => now(),
            ]);
            foreach (['teacher_profiles', 'institute_profiles'] as $profileTable) {
                DB::table($profileTable)->where('user_id', $booking->provider_id)->update([
                    'rating_avg' => DB::raw('(rating_avg * rating_count + '.$data['rating'].') / (rating_count + 1)'),
                    'rating_count' => DB::raw('rating_count + 1'),
                ]);
            }

            return DB::table('reviews')->where('id', $id)->first();
        });
        if ($review === null) {
            return response()->json(['error' => 'Not allowed to review this booking'], 403);
        }

        return response()->json(['review' => $review]);
    }

    public function providerReviews(Request $request, int $providerId): JsonResponse
    {
        $reviews = DB::table('reviews as r')
            ->join('users as u', 'u.id', '=', 'r.learner_id')
            ->where('r.provider_id', $providerId)
            ->select('r.*', 'u.full_name as learner_name')
            ->orderByDesc('r.created_at')->orderByDesc('r.id');

        return CursorPage::respond($request, $reviews, 'reviews');
    }

    private function sendReplyNotification(string $email, string $author, string $body): void
    {
        SendNotificationEmail::dispatch($email, $author.' replied to your comment', '"'.$body.'" - open the app to see the full thread.');
    }
}
