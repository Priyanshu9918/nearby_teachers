<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class SetupAdminLogin extends Command
{
    protected $signature = 'admin:setup-login';

    protected $description = 'Securely set the browser admin portal credentials in .env';

    public function handle(): int
    {
        $email = trim((string) $this->ask('Admin email address'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('Enter a valid admin email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Admin password (minimum 12 characters)');
        if (strlen($password) < 12) {
            $this->components->error('Admin password must contain at least 12 characters.');

            return self::FAILURE;
        }

        $envPath = app()->environmentFilePath();
        if (! File::isFile($envPath) || ! File::isWritable($envPath)) {
            $this->components->error('The application .env file is missing or not writable.');

            return self::FAILURE;
        }

        $contents = File::get($envPath);
        $settings = [
            'ADMIN_EMAIL' => $this->quoteEnvValue($email),
            'ADMIN_PASSWORD_HASH' => $this->quoteEnvValue(password_hash($password, PASSWORD_BCRYPT)),
        ];

        foreach ($settings as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $replacement = $key.'='.$value;
            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace_callback($pattern, static fn (): string => $replacement, $contents, 1);
            } else {
                $contents = rtrim($contents).PHP_EOL.$replacement.PHP_EOL;
            }
        }

        if (File::put($envPath, $contents) === false) {
            $this->components->error('Could not write admin credentials to the application .env file.');

            return self::FAILURE;
        }

        if (Artisan::call('config:clear') !== self::SUCCESS) {
            $this->components->error('Credentials were saved, but the cached configuration could not be cleared. Run php artisan config:clear.');

            return self::FAILURE;
        }

        $this->components->info('Browser admin credentials saved to .env. The plaintext password was not stored.');

        return self::SUCCESS;
    }

    private function quoteEnvValue(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
