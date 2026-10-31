<?php

namespace App\Http\Controllers\Api;

use App\Events\FeedActivity;
use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationEmail;
use App\Reporting\BusinessActivityRecorder;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:160'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $posts = DB::table('posts as p')
            ->join('users as u', 'u.id', '=', 'p.author_id')
            ->leftJoin('teacher_profiles as teacher', function ($join): void {
                $join->on('teacher.user_id', '=', 'u.id')->where('u.account_type', 'teacher');
            })
            ->leftJoin('institute_profiles as institute', function ($join): void {
                $join->on('institute.user_id', '=', 'u.id')->where('u.account_type', 'institute');
            })
            ->leftJoin('subjects as s', 's.id', '=', 'p.subject_id')
            ->where('p.city', $data['city'])
            ->select('p.id', 'p.author_id', 'p.post_type', 'p.city', 'p.body', 'p.image_url', 'p.created_at',
                'u.full_name as author_name', 'u.account_type as author_type', 'u.referral_points', 's.name as subject')
            ->selectRaw('COALESCE(teacher.rating_avg, institute.rating_avg) AS rating_avg')
            ->selectRaw('COALESCE(teacher.rating_count, institute.rating_count, 0) AS rating_count')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS author_avatar")
            ->selectSub(DB::table('post_likes')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'like_count')
            ->selectSub(DB::table('post_comments')->selectRaw('count(*)')->whereColumn('post_id', 'p.id'), 'comment_count')
            ->selectRaw($user === null
                ? '0 AS liked_by_me'
                : 'EXISTS (SELECT 1 FROM post_likes WHERE post_id = p.id AND user_id = ?) AS liked_by_me',
                $user === null ? [] : [$user->id]);
        // Use an outer query so cursor pagination can compare the computed score as a normal column.
        $posts = DB::query()->fromSub($posts, 'city_posts')->select('city_posts.*')
            ->orderByDesc('referral_points')->orderByDesc('created_at')->orderByDesc('id');

        $timezone = $data['timezone'] ?? config('app.timezone', 'UTC');
        $sourceTimezone = config('app.timezone', 'UTC');

        return CursorPage::respond(
            $request,
            $posts,
            'posts',
            transformItems: static function (array $items) use ($timezone, $sourceTimezone): array {
                $postIds = array_map(static fn (object $post): int => (int) $post->id, $items);
                $likesByPost = [];
                if ($postIds !== []) {
                    $likes = DB::table('post_likes as l')
                        ->join('users as u', 'u.id', '=', 'l.user_id')
                        ->whereIn('l.post_id', $postIds)
                        ->orderBy('l.created_at')->orderBy('l.user_id')
                        ->get(['l.post_id', 'u.id as user_id', 'u.full_name as name', 'u.account_type as type']);

                    foreach ($likes as $like) {
                        $likesByPost[$like->post_id][] = [
                            'user_id' => $like->user_id,
                            'name' => $like->name,
                            'type' => $like->type,
                        ];
                    }
                }

                foreach ($items as $post) {
                    $post->likes = $likesByPost[$post->id] ?? [];
                    $post->liked_by_me = (bool) $post->liked_by_me;
                    $post->likedByMe = $post->liked_by_me;
                    $post->referral_points = (int) $post->referral_points;
                    $post->rating_avg = $post->rating_avg === null ? null : (float) $post->rating_avg;
                    $post->rating_count = (int) $post->rating_count;
                    $post->created_at = Carbon::parse($post->created_at, $sourceTimezone)->timezone($timezone)->toIso8601String();
                    $post->created_at_timezone = $timezone;
                }

                return $items;
            },
        );
    }

    public function createPost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:160'],
            'subjectId' => ['nullable', 'integer', 'min:1', 'exists:subjects,id'],
            'body' => ['required', 'string', 'max:5000'],
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
                'created_at' => now(),
            ]);

            $createdPost = DB::table('posts as p')->leftJoin('subjects as s', 's.id', '=', 'p.subject_id')
                ->where('p.id', $id)->select('p.id', 'p.author_id', 'p.post_type', 'p.city', 'p.body', 'p.image_url',
                    'p.created_at', 's.name as subject')->first();

            return ['post' => $createdPost];
        });

        return $post instanceof JsonResponse ? $post : response()->json($post);
    }

    public function like(Request $request, int $postId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($postId, $user): ?array {
            if (DB::table('posts')->where('id', $postId)->lockForUpdate()->first(['id']) === null) {
                return null;
            }

            $inserted = DB::table('post_likes')->insertOrIgnore([
                'post_id' => $postId,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            $likeCount = (int) DB::table('post_likes')->where('post_id', $postId)->count();
            if ($inserted === 1) {
                app(BusinessActivityRecorder::class)->record(
                    'post.like_changed', $postId, $user->id,
                    before: ['liked' => false], after: ['liked' => true],
                );
                event(new FeedActivity('feed:post.like_changed', $postId, [
                    'postId' => $postId,
                    'user' => ['user_id' => $user->id, 'name' => $user->full_name, 'type' => $user->account_type],
                    'liked' => true,
                    'likeCount' => $likeCount,
                ]));
            }

            return ['liked' => true, 'likeCount' => $likeCount];
        });

        if ($result === null) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        return response()->json(['ok' => true, ...$result, 'like_count' => $result['likeCount']]);
    }

    public function unlike(Request $request, int $postId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($postId, $user): ?array {
            if (DB::table('posts')->where('id', $postId)->lockForUpdate()->first(['id']) === null) {
                return null;
            }

            $deleted = DB::table('post_likes')->where('post_id', $postId)
                ->where('user_id', $user->id)->delete();
            $likeCount = (int) DB::table('post_likes')->where('post_id', $postId)->count();
            if ($deleted > 0) {
                app(BusinessActivityRecorder::class)->record(
                    'post.like_changed', $postId, $user->id,
                    before: ['liked' => true], after: ['liked' => false],
                );
                event(new FeedActivity('feed:post.like_changed', $postId, [
                    'postId' => $postId,
                    'user' => ['user_id' => $user->id, 'name' => $user->full_name, 'type' => $user->account_type],
                    'liked' => false,
                    'likeCount' => $likeCount,
                ]));
            }

            return ['liked' => false, 'likeCount' => $likeCount];
        });

        if ($result === null) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        return response()->json(['ok' => true, ...$result, 'like_count' => $result['likeCount']]);
    }

    public function comments(Request $request, int $postId): JsonResponse
    {
        $user = $request->attributes->get('authenticated_user');
        $comments = DB::table('post_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.post_id', $postId)
            ->select('c.id', 'c.post_id', 'c.user_id', 'c.parent_comment_id', 'c.body', 'c.created_at',
                'u.full_name as author_name', 'u.account_type as author_type')
            ->selectSub(DB::table('comment_likes')->selectRaw('count(*)')->whereColumn('comment_id', 'c.id'), 'like_count')
            ->selectRaw($user === null
                ? '0 AS liked_by_me'
                : 'EXISTS (SELECT 1 FROM comment_likes WHERE comment_id = c.id AND user_id = ?) AS liked_by_me',
                $user === null ? [] : [$user->id])
            ->orderBy('c.created_at')->orderBy('c.id');

        return CursorPage::respond(
            $request,
            $comments,
            'comments',
            transformItems: static function (array $items): array {
                $commentIds = array_map(static fn (object $comment): int => (int) $comment->id, $items);
                $likesByComment = [];
                if ($commentIds !== []) {
                    $likes = DB::table('comment_likes as l')
                        ->join('users as u', 'u.id', '=', 'l.user_id')
                        ->whereIn('l.comment_id', $commentIds)
                        ->orderBy('l.created_at')->orderBy('l.user_id')
                        ->get(['l.comment_id', 'u.id as user_id', 'u.full_name as name', 'u.account_type as type']);

                    foreach ($likes as $like) {
                        $likesByComment[$like->comment_id][] = [
                            'user_id' => $like->user_id,
                            'name' => $like->name,
                            'type' => $like->type,
                        ];
                    }
                }

                foreach ($items as $comment) {
                    $comment->likes = $likesByComment[$comment->id] ?? [];
                    $comment->liked_by_me = (bool) $comment->liked_by_me;
                    $comment->likedByMe = $comment->liked_by_me;
                }

                return $items;
            },
        );
    }

    public function likeComment(Request $request, int $commentId): JsonResponse
    {
        $data = $request->validate(['postId' => ['sometimes', 'required', 'integer', 'min:1', 'max:9007199254740991']]);
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($commentId, $user, $data): ?array {
            $comment = DB::table('post_comments')->where('id', $commentId)->lockForUpdate()->first();
            if ($comment === null || (isset($data['postId']) && (int) $comment->post_id !== (int) $data['postId'])) {
                return null;
            }

            $inserted = DB::table('comment_likes')->insertOrIgnore([
                'comment_id' => $commentId,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            $likeCount = (int) DB::table('comment_likes')->where('comment_id', $commentId)->count();
            if ($inserted === 1) {
                app(BusinessActivityRecorder::class)->record(
                    'comment.like_changed', $commentId, $user->id, $comment->user_id,
                    before: ['liked' => false], after: ['liked' => true],
                );
                event(new FeedActivity('feed:comment.like_changed', (int) $comment->post_id, [
                    'postId' => (int) $comment->post_id,
                    'commentId' => $commentId,
                    'user' => ['user_id' => $user->id, 'name' => $user->full_name, 'type' => $user->account_type],
                    'liked' => true,
                    'likeCount' => $likeCount,
                ]));
            }

            return ['postId' => (int) $comment->post_id, 'liked' => true,
                'likeCount' => $likeCount];
        });

        if ($result === null) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        return response()->json(['ok' => true, ...$result, 'like_count' => $result['likeCount']]);
    }

    public function unlikeComment(Request $request, int $commentId): JsonResponse
    {
        $data = $request->validate(['postId' => ['sometimes', 'required', 'integer', 'min:1', 'max:9007199254740991']]);
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($commentId, $user, $data): ?array {
            $comment = DB::table('post_comments')->where('id', $commentId)->lockForUpdate()->first();
            if ($comment === null || (isset($data['postId']) && (int) $comment->post_id !== (int) $data['postId'])) {
                return null;
            }

            $deleted = DB::table('comment_likes')->where('comment_id', $commentId)
                ->where('user_id', $user->id)->delete();
            $likeCount = (int) DB::table('comment_likes')->where('comment_id', $commentId)->count();
            if ($deleted === 1) {
                app(BusinessActivityRecorder::class)->record(
                    'comment.like_changed', $commentId, $user->id, $comment->user_id,
                    before: ['liked' => true], after: ['liked' => false],
                );
                event(new FeedActivity('feed:comment.like_changed', (int) $comment->post_id, [
                    'postId' => (int) $comment->post_id,
                    'commentId' => $commentId,
                    'user' => ['user_id' => $user->id, 'name' => $user->full_name, 'type' => $user->account_type],
                    'liked' => false,
                    'likeCount' => $likeCount,
                ]));
            }

            return ['postId' => (int) $comment->post_id, 'liked' => false,
                'likeCount' => $likeCount];
        });

        if ($result === null) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        return response()->json(['ok' => true, ...$result, 'like_count' => $result['likeCount']]);
    }

    public function createComment(Request $request, int $postId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parentCommentId' => ['nullable', 'integer', 'min:1', 'max:9007199254740991', 'exists:post_comments,id'],
        ]);
        $user = $request->attributes->get('authenticated_user');
        $result = DB::transaction(function () use ($data, $postId, $user): ?array {
            if (DB::table('posts')->where('id', $postId)->lockForUpdate()->first(['id']) === null) {
                return null;
            }
            $parent = null;
            if (isset($data['parentCommentId'])) {
                $parent = DB::table('post_comments')->where('id', $data['parentCommentId'])
                    ->where('post_id', $postId)->lockForUpdate()->first(['id', 'user_id']);
                if ($parent === null) {
                    return ['invalid_parent' => true];
                }
            }

            $id = DB::table('post_comments')->insertGetId([
                'post_id' => $postId,
                'user_id' => $user->id,
                'parent_comment_id' => $data['parentCommentId'] ?? null,
                'body' => $data['body'],
                'created_at' => now(),
            ]);
            $comment = DB::table('post_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.id', $id)->select('c.id', 'c.post_id', 'c.user_id', 'c.parent_comment_id', 'c.body', 'c.created_at',
                    'u.full_name as author_name', 'u.account_type as author_type')->first();
            $comment->like_count = 0;
            $comment->liked_by_me = false;
            $comment->likedByMe = false;
            $comment->likes = [];

            app(BusinessActivityRecorder::class)->record(
                'feed.comment_created', $id, $user->id, $parent?->user_id,
                after: ['post_id' => $postId, 'parent_comment_id' => $data['parentCommentId'] ?? null],
            );
            event(new FeedActivity('feed:comment.created', $postId, ['postId' => $postId, 'comment' => (array) $comment]));

            return ['comment' => $comment];
        });

        if ($result === null) {
            return response()->json(['error' => 'Post not found'], 404);
        }
        if (isset($result['invalid_parent'])) {
            return response()->json(['error' => 'Parent comment not found for this post'], 404);
        }

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

        return response()->json(['comment' => $result['comment']]);
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
