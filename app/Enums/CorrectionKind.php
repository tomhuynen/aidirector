<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumHelpers;

/**
 * A correction restores what the plan or a rule already asked for, so the
 * pipeline can learn from it; an instruction is a new creative choice for one
 * shot and stays with that shot.
 */
enum CorrectionKind: string
{
    use EnumHelpers;

    case CORRECTION = 'correction';

    case INSTRUCTION = 'instruction';
}
