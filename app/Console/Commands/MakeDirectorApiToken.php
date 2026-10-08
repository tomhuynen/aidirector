<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Director;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Creates a token for the read-only project API, for a director of one
 * tenant. The token is shown once; it only works on that tenant's domain.
 */
class MakeDirectorApiToken extends Command
{
    /**
     * @var string
     */
    protected $signature = 'directors:api-token
        {email : The director\'s email address}
        {--tenant= : The id of the tenant the director belongs to}
        {--name=e-learning : What the token is for}';

    /**
     * @var string
     */
    protected $description = 'Create a read-only API token for a director.';

    public function handle(): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));

        if ($tenant === null) {
            $this->error('No tenant with that id. Pass it with --tenant=.');

            return self::FAILURE;
        }

        $tenant->makeCurrent();
        $director = Director::query()->where('email', $this->argument('email'))->first();

        if ($director === null) {
            $this->error("No director with that email address in {$tenant->name}.");

            return self::FAILURE;
        }

        $token = $director->createToken((string) $this->option('name'))->plainTextToken;

        $this->info("Token for {$director->email} at {$tenant->domain}, shown only once:");
        $this->line($token);

        return self::SUCCESS;
    }
}
