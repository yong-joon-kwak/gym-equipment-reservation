<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Usage;

use App\Domain\Member\Member;
use App\Domain\Shared\DomainException;
use App\Domain\Usage\EndReason;
use App\Domain\Usage\UsageSession;
use App\Tests\Support\DomainFixtures as Given;
use App\Tests\Support\Feature;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[Feature('equipment-queue')]
#[Group('unit')]
final class UsageSessionTest extends TestCase
{
    #[TestDox('사용 세션은 만료 시각 없이 시작한다')]
    public function testStartsWithoutExpiry(): void
    {
        $session = self::startedAt('09:00');

        self::assertNull($session->expiresAt());
        self::assertFalse($session->isEnded());
    }

    #[TestDox('관리자는 사용 세션을 시작할 수 없다')]
    public function testAdminCannotStartASession(): void
    {
        $this->expectException(DomainException::class);

        UsageSession::start(Given::anAdmin(), Given::anEquipment(), Given::at('09:00'));
    }

    #[TestDox('첫 대기자가 정한 만료 시각은 대기자가 더 와도 바뀌지 않는다')]
    public function testFirstWaiterFixesTheExpiry(): void
    {
        $session = self::startedAt('09:00');

        $session->assignExpiry(Given::at('09:30'), Given::at('09:05'));
        $session->assignExpiry(Given::at('09:40'), Given::at('09:12'));

        self::assertEquals(Given::at('09:30'), $session->expiresAt());
    }

    #[TestDox('대기자가 모두 빠지면 만료 시각이 없어진다')]
    public function testExpiryDisappearsWhenWaitersAreGone(): void
    {
        $session = self::startedAt('09:00');
        $session->assignExpiry(Given::at('09:30'), Given::at('09:05'));

        $session->clearExpiry(Given::at('09:10'));

        self::assertNull($session->expiresAt());
    }

    #[TestDox('연장하면 만료 시각이 뒤로 밀리고, 연장은 한 번만 된다')]
    public function testExtensionPushesExpiryOnlyOnce(): void
    {
        $session = self::startedAt('09:00');
        $session->assignExpiry(Given::at('09:30'), Given::at('09:05'));

        $session->extendTo(Given::at('09:35'), Given::at('09:28'));

        self::assertEquals(Given::at('09:35'), $session->expiresAt());
        $this->assertRejected(static fn () => $session->extendTo(Given::at('09:40'), Given::at('09:32')));
    }

    #[TestDox('만료 시각이 없는 사용 세션은 연장할 수 없다')]
    public function testCannotExtendWithoutExpiry(): void
    {
        $session = self::startedAt('09:00');

        $this->assertRejected(static fn () => $session->extendTo(Given::at('09:35'), Given::at('09:28')));
        self::assertNull($session->expiresAt());
    }

    #[TestDox('끝난 사용 세션은 더 이상 바뀌지 않는다')]
    public function testEndedSessionNoLongerChanges(): void
    {
        $session = self::startedAt('09:00');
        $session->end(EndReason::EndedByMember, Given::at('09:20'), null, Given::at('09:20'));

        $this->assertRejected(static fn () => $session->assignExpiry(Given::at('09:40'), Given::at('09:21')));
        $this->assertRejected(static fn () => $session->clearExpiry(Given::at('09:21')));
        $this->assertRejected(static fn () => $session->extendTo(Given::at('09:40'), Given::at('09:21')));
        $this->assertRejected(static fn () => $session->end(EndReason::Switched, Given::at('09:21'), null, Given::at('09:21')));
        self::assertEquals(Given::at('09:20'), $session->endedAt());
        self::assertSame(EndReason::EndedByMember, $session->endReason());
    }

    #[TestDox('만료로 끝난 사용 세션의 종료 시각은 만료 시각이다')]
    public function testExpiredSessionEndsAtItsExpiry(): void
    {
        $session = self::startedAt('09:00');
        $session->assignExpiry(Given::at('09:30'), Given::at('09:05'));

        $this->assertRejected(static fn () => $session->end(EndReason::Expired, Given::at('09:47'), null, Given::at('09:47')));
        $session->end(EndReason::Expired, Given::at('09:30'), Given::at('09:35'), Given::at('09:47'));

        self::assertEquals(Given::at('09:30'), $session->endedAt());
    }

    #[TestDox('종료한 관리자는 강제 종료에만 기록되고, 관리자여야 한다')]
    public function testOnlyForceEndRecordsAnAdmin(): void
    {
        $session = self::startedAt('09:00');
        $admin = Given::anAdmin();
        $end = static fn (EndReason $reason, ?Member $by) => $session->end($reason, Given::at('09:20'), null, Given::at('09:20'), $by);

        $this->assertRejected(static fn () => $end(EndReason::ForceEnded, null));
        $this->assertRejected(static fn () => $end(EndReason::EndedByMember, $admin));
        $this->assertRejected(static fn () => $end(EndReason::ForceEnded, Given::aMember()));
        $end(EndReason::ForceEnded, $admin);

        self::assertSame($admin, $session->endedBy());
    }

    private static function startedAt(string $time): UsageSession
    {
        return UsageSession::start(Given::aMember(), Given::anEquipment(), Given::at($time));
    }

    private function assertRejected(callable $change): void
    {
        try {
            $change();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail('불변식을 어긴 변경이 받아들여졌다');
    }
}
