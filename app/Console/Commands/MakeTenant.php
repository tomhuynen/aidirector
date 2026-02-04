<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class MakeTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:tenant';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new tenant.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenant = null;

        while (true) {
            try {
                $name = text(
                    label: 'Tenant name',
                    required: true,
                );

                $domain = text(
                    label: 'Tenant domain',
                    default: $this->getDomain($name),
                    required: true,
                    hint: 'The domain of the tenant without the protocol',
                );

                $confirm = confirm(
                    label: 'Are you sure you want to create this tenant?',
                    hint: 'This will create a new database and migrate it.',
                );

                if (! $confirm) {
                    $this->info('Tenant creation cancelled.');

                    return Command::SUCCESS;
                }

                $tenant = Tenant::create([
                    'name' => $name,
                    'domain' => $domain,
                ]);

                break;
            } catch (ValidationException $e) {
                warning($e->getMessage());
            }
        }

        $this->info("Tenant {$tenant->name} created successfully.");
    }

    private function getDomain(string $name): string
    {
        $uri = new Uri(Config::get('app.url'));
        $host = $uri->getHost();
        $subdomain = Str::slug($name);

        return (string) $subdomain . '.' . $host;
    }
}
