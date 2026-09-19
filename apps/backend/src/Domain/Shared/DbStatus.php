<?php

declare(strict_types=1);

namespace App\Domain\Shared;

enum DbStatus: string
{
    case Alive = 'A';
    case Deleted = 'D';
}
