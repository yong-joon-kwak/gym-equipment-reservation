<?php

declare(strict_types=1);

namespace App\Domain\Member;

enum MemberType: string
{
    case Member = 'MEMBER';
    case Admin = 'ADMIN';
}
