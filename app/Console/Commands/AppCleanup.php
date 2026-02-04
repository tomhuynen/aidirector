<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\AppCleaningUp;
use App\Models\Login;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class AppCleanup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up obsolete files & database records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->purgeExpiredPasswordResets();
        $this->cleanUserLogins();
        $this->cleanTmpDir();
        $this->cleanLogsDir();
        $this->removeEmptyStorageDirs();
        $this->pruneModels();

        Event::dispatch(new AppCleaningUp());
    }

    protected function cleanTmpDir()
    {
        $path = storage_path('tmp');
        $maxTime = Date::now()->subHours(12)->timestamp;

        $this->cleanDirectory($path, $maxTime);
    }

    private function cleanLogsDir()
    {
        $path = dirname((string) config('logging.channels.daily.path'));
        $maxTime = Date::now()->subWeeks(2)->timestamp;

        $this->cleanDirectory($path, $maxTime);
    }

    private function cleanDirectory(string $path, int $maxTimestamp)
    {
        if (! file_exists($path) || ! is_dir($path)) {
            return;
        }

        collect(File::files($path))
            ->filter(fn($f) => $f->getMTime() < $maxTimestamp)
            ->each(fn($f) => unlink($f->getPathname()));
    }

    private function purgeExpiredPasswordResets()
    {
        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            Artisan::call('auth:clear-resets', [], $this->output);
        });
    }

    private function cleanUserLogins()
    {
        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            Login::query()
                ->where('created_at', '<', Date::parse('12 months ago'))
                ->delete();
        });
    }

    protected function removeEmptyStorageDirs()
    {
        $dir = storage_path('app');
        if (! file_exists($dir)) {
            return;
        }

        $command = [
            'find',
            escapeshellarg($dir),
            '-empty',
            '-type d',
            '-delete',
        ];

        Process::run(implode(' ', $command))->throw();
    }

    private function pruneModels()
    {
        Tenant::all()->eachCurrent(function (Tenant $tenant) {
            Artisan::call('model:prune', [], $this->output);
        });
    }
}
