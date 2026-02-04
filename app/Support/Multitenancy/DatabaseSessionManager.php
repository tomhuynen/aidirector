<?php

declare(strict_types=1);

namespace App\Support\Multitenancy;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Session\DatabaseSessionHandler;

class DatabaseSessionManager extends DatabaseSessionHandler
{
    /**
     * Get a fresh query builder instance for the table with tenant scope.
     *
     * @return Builder
     */
    protected function getQuery()
    {
        $query = parent::getQuery();

        if ($this->hasTenant()) {
            $query->where(function ($query) {
                $query
                    ->whereNull('tenant_id')
                    ->orWhere('tenant_id', $this->getTenantId());
            });
        }

        return $query;
    }

    /**
     * Get the default payload for the session.
     *
     * @param  string  $data
     * @return array
     */
    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);

        if ($this->hasTenant()) {
            $payload['tenant_id'] = $this->getTenantId();
        }

        return $payload;
    }

    /**
     * Check if tenant is initialized.
     */
    private function hasTenant(): bool
    {
        return Tenant::checkCurrent();
    }

    /**
     * Get current tenant ID safely.
     */
    private function getTenantId(): int
    {
        return Tenant::current()->id;
    }
}
