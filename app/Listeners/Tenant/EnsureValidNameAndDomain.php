<?php

declare(strict_types=1);

namespace App\Listeners\Tenant;

use App\Events\TenantCreating;
use App\Models\Tenant;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EnsureValidNameAndDomain
{
    /**
     * Handle the event.
     */
    public function handle(TenantCreating $event): void
    {
        $this->formatAndValidatedDomain($event->tenant);
        $this->validateLengthAndUniqueness($event->tenant);
    }

    private function formatAndValidatedDomain(Tenant $tenant)
    {
        if (filter_var($tenant->domain, FILTER_VALIDATE_URL)) {
            $tenant->domain = new Uri($tenant->domain)->getHost();
        }

        $urlValidator = Validator::make(
            ['domain' => "https://{$tenant->domain}"],
            ['domain' => [
                'required',
                'url',
            ]]
        );

        if ($urlValidator->fails()) {
            throw ValidationException::withMessages([
                'domain' => $urlValidator->errors()->all(),
            ]);
        }
    }

    private function validateLengthAndUniqueness(Tenant $tenant)
    {
        if (empty($tenant->name)) {
            throw ValidationException::withMessages([
                'name' => [__('Name is required')],
            ]);
        }

        if (empty($tenant->domain)) {
            throw ValidationException::withMessages([
                'domain' => [__('Domain is required')],
            ]);
        }

        if (strlen($tenant->domain) > 255) {
            throw ValidationException::withMessages([
                'domain' => [__('Domain must be less than 255 characters')],
            ]);
        }

        $exists = Tenant::query()
            ->where('domain', '=', $tenant->domain)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'domain' => [__('Tenant with domain :domain already exists', ['domain' => $tenant->domain])],
            ]);
        }
    }
}
