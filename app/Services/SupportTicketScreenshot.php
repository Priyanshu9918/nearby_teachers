<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SupportTicketScreenshot
{
    public static function rules(): array
    {
        return ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'];
    }

    public static function payload(object $ticket): object
    {
        $ticket = clone $ticket;
        $ticket->screenshotAvailable = filled($ticket->screenshot_path ?? null);
        $ticket->screenshotUrl = $ticket->screenshotAvailable ? route('support.screenshot', ['id' => $ticket->id]) : null;
        unset($ticket->screenshot_path);

        return $ticket;
    }

    public static function response(?string $path): BinaryFileResponse
    {
        abort_unless(is_string($path) && preg_match('#^support-screenshots/[a-zA-Z0-9]+\.(?:png|jpe?g|webp)$#', $path) === 1, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
