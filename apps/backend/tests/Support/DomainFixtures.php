<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Shared\QueueRules;
use DateTimeImmutable;
use DateTimeZone;

final class DomainFixtures
{
    private const string DATE = '2026-09-19';

    public static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DATE.' '.$time, new DateTimeZone('Asia/Seoul'));
    }

    public static function rules(): QueueRules
    {
        return new QueueRules(
            noShowGraceMinutes: 2,
            extensionMinutes: 5,
            nearingExpiryMinutes: 5,
            requeueBlockMinutes: 5,
            overtimeGraceMinutes: 5,
        );
    }
}
