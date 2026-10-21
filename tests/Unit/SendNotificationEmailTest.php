<?php

namespace Tests\Unit;

use App\Jobs\SendNotificationEmail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendNotificationEmailTest extends TestCase
{
    public function test_temporary_mail_failure_is_not_swallowed_so_worker_can_retry(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Temporary transport failure'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Temporary transport failure');
        (new SendNotificationEmail('test@example.com', 'Test subject', 'Test body'))->handle();
    }
}
