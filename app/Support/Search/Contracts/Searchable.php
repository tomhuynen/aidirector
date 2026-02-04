<?php

declare(strict_types=1);

namespace App\Support\Search\Contracts;

interface Searchable
{
    public function searchableColumns(): array;
}
