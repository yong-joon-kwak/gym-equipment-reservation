<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Equipment\Equipment;
use App\Domain\Equipment\EquipmentCode;
use App\Domain\Member\Member;
use App\Domain\Member\MemberType;
use App\Domain\Shared\QueueRules;
use DateTimeImmutable;
use DateTimeZone;

final class DomainFixtures
{
    private const string DATE = '2026-09-19';

    private static int $sequence = 0;

    public static function aMember(): Member
    {
        $n = ++self::$sequence;

        return Member::register("member{$n}", 'hash', "회원 {$n}", MemberType::Member, self::at('08:00'));
    }

    public static function anAdmin(): Member
    {
        $n = ++self::$sequence;

        return Member::register("admin{$n}", 'hash', "관리자 {$n}", MemberType::Admin, self::at('08:00'));
    }

    public static function anEquipment(int $maxUsageMinutes = 30): Equipment
    {
        $n = ++self::$sequence;

        return Equipment::register(EquipmentCode::fromString(sprintf('TM-%02d', $n)), "러닝머신 {$n}", '러닝머신', $maxUsageMinutes, self::at('08:00'));
    }

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
