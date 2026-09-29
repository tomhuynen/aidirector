<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Processes\Exceptions\ProcessException;
use App\Console\Processes\Git;
use App\Support\EnvFile;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;
use Laravel\Prompts\Progress;

use function Laravel\Prompts\{confirm, progress, text};

class AppCoreInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:core-init {--force : Force configuration without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure the application after cloning the repository.';

    /**
     * The new remote name.
     *
     * @var string
     */
    protected $remoteName = 'blueprint';

    protected EnvFile $envFile;

    public function __construct(protected Git $git)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(EnvFile $envFile)
    {
        $this->envFile = $envFile;

        if ($this->isAlreadyConfigured() && $this->envFile->exists()) {
            $this->info('Git remotes and .env file already configured.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmConfiguration()) {
            $this->info('Configuration cancelled.');

            return Command::SUCCESS;
        }

        $this->configureGitRemotes();
        $this->configureEnvironment();

        return Command::SUCCESS;
    }

    private function confirmConfiguration(): bool
    {
        return confirm(
            label: 'Are you sure you want to continue?',
            hint: "This will set the origin push URL to no-pushing, rename the origin to $this->remoteName and create an .env file.",
        );
    }

    private function configureEnvironment(): void
    {
        if ($this->envFile->exists()) {
            $this->info('.env file already exists, skipping.');

            return;
        }

        $directory = basename(base_path());

        $name = text(
            label: 'Application name',
            default: Str::headline($directory),
            required: true,
        );

        $title = text(
            label: 'Application title',
            default: $name,
            required: true,
            hint: 'Shown in the browser tab',
        );

        $url = text(
            label: 'Application URL',
            default: "https://{$directory}.test",
            required: true,
            hint: 'Tenant domains default to subdomains of this host',
        );

        $database = text(
            label: 'Landlord database name',
            default: Str::slug($name, '_'),
            required: true,
            hint: 'Tenant databases are derived from this database',
        );

        $this->envFile->createFromExample([
            'APP_NAME' => $name,
            'APP_TITLE' => $title,
            'APP_URL' => $url,
            'DB_DATABASE' => $database,
        ]);

        $this->info('.env file created.');

        $this->call('key:generate', ['--ansi' => true]);
    }

    private function configureGitRemotes(): void
    {
        if ($this->isAlreadyConfigured()) {
            $this->info('Git remotes already configured, skipping.');

            return;
        }

        $progress = progress(
            label: 'Configuring Git remotes...',
            steps: 3,
        );

        $progress->start();

        $this->advanceProgress(
            progress: $progress,
            label: 'Setting origin push URL to no-pushing',
            callback: fn() => $this->runGitCommand(['remote', 'set-url', '--push', 'origin', 'no-pushing']),
        );

        $this->advanceProgress(
            progress: $progress,
            label: "Renaming origin to $this->remoteName",
            callback: fn() => $this->runGitCommand(['remote', 'rename', 'origin', $this->remoteName]),
        );

        $this->advanceProgress(
            progress: $progress,
            label: 'Adding new origin',
            callback: function () {
                $newOriginUrl = text(
                    label: 'New project repository URL',
                    placeholder: 'git@github.com:your-username/your-project.git',
                    hint: 'Leave blank to skip adding new origin remote',
                );

                if ($newOriginUrl) {
                    $this->runGitCommand(['remote', 'add', 'origin', $newOriginUrl]);
                }
            },
        );

        $progress->finish();

        $this->info('Git remotes configured successfully, don\'t forget to publish the repository.');
    }

    private function advanceProgress(Progress $progress, string $label, ?Closure $callback = null): void
    {
        $progress->label($label);

        if ($callback) {
            $callback();
        }

        $progress->advance();
    }

    private function runGitCommand(array $command): ProcessResult
    {
        return $this->git->runGitCommand($command);
    }

    private function isAlreadyConfigured(): bool
    {
        try {
            return $this->git->runGitCommand(['remote', 'show', $this->remoteName])->successful();
        } catch (ProcessException $e) {
            return false;
        }
    }
}
