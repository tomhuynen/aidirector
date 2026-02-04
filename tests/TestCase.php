<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshMultitenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshMultitenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantContext();
    }
}
