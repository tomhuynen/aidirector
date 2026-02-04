<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;
use Illuminate\Support\Facades\App;
use Locale as LocaleClass;

enum Locale: string
{
    use EnumHelpers;

    case EN = 'en';

    case FR = 'fr';

    public function description(): string
    {
        return LocaleClass::getDisplayName($this->value, App::getLocale());
    }
}
