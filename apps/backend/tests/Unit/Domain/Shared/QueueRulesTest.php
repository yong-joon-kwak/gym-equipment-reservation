<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Tests\Support\DomainFixtures as Given;
use App\Tests\Support\Feature;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Feature('equipment-queue')]
#[Group('unit')]
final class QueueRulesTest extends TestCase
{
    #[TestDox('재대기 제한은 대기자가 있던 채로 끝났을 때만, 종료 시각부터 재대기 제한 시간 동안 걸린다')]
    public function testRequeueBlockAppliesOnlyWhenSomeoneWasWaiting(): void
    {
        $rules = Given::rules();

        self::assertEquals(Given::at('09:40'), $rules->requeueBlockedUntil(Given::at('09:35'), hadWaiters: true));
        self::assertNull($rules->requeueBlockedUntil(Given::at('09:35'), hadWaiters: false));
    }
}
