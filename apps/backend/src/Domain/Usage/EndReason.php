<?php

declare(strict_types=1);

namespace App\Domain\Usage;

enum EndReason: string
{
    case EndedByMember = 'ENDED_BY_MEMBER';
    case Expired = 'EXPIRED';
    case Switched = 'SWITCHED';
    case ForceEnded = 'FORCE_ENDED';
}
