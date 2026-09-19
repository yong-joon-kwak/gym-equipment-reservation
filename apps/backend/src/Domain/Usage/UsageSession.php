<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Domain\Equipment\Equipment;
use App\Domain\Member\Member;
use App\Domain\Member\MemberType;
use App\Domain\Shared\DbStatus;
use App\Domain\Shared\DomainException;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * 사건 메서드는 계산하지 않는다. 값은 판정 객체가 정해 넘기고, 여기서는 불변식만 지킨다
 * (docs/architecture/backend.md §8 D1, data-model.md §5).
 */
class UsageSession
{
    private readonly Uuid $id;

    private ?DateTimeImmutable $expiresAt = null;

    private int $extensionCount = 0;

    private ?DateTimeImmutable $endedAt = null;

    private ?EndReason $endReason = null;

    private ?DateTimeImmutable $requeueBlockedUntil = null;

    private ?Member $endedBy = null;

    private DbStatus $dbStatus = DbStatus::Alive;

    private readonly DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly Member $member,
        private readonly Equipment $equipment,
        private readonly DateTimeImmutable $startedAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $startedAt;
        $this->updatedAt = $startedAt;
    }

    public static function start(Member $member, Equipment $equipment, DateTimeImmutable $now): self
    {
        if ($member->type() !== MemberType::Member) {
            throw DomainException::invariant('사용 세션의 주인은 일반 회원이어야 한다');
        }

        return new self($member, $equipment, $now);
    }

    public function assignExpiry(DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->assertInProgress();
        if ($this->expiresAt !== null) {
            return;
        }
        if ($expiresAt <= $now) {
            throw DomainException::invariant('만료 시각은 지금보다 뒤여야 한다');
        }

        $this->expiresAt = $expiresAt;
        $this->updatedAt = $now;
    }

    public function clearExpiry(DateTimeImmutable $now): void
    {
        $this->assertInProgress();

        $this->expiresAt = null;
        $this->updatedAt = $now;
    }

    public function extendTo(DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->assertInProgress();
        if ($this->expiresAt === null) {
            throw DomainException::invariant('만료 시각이 없는 사용 세션은 연장할 수 없다');
        }
        if ($this->extensionCount > 0) {
            throw DomainException::invariant('연장은 한 번만 된다');
        }
        if ($expiresAt <= $this->expiresAt) {
            throw DomainException::invariant('연장한 만료 시각은 지금 만료 시각보다 뒤여야 한다');
        }

        $this->expiresAt = $expiresAt;
        ++$this->extensionCount;
        $this->updatedAt = $now;
    }

    public function end(
        EndReason $reason,
        DateTimeImmutable $endedAt,
        ?DateTimeImmutable $requeueBlockedUntil,
        DateTimeImmutable $now,
        ?Member $endedBy = null,
    ): void {
        $this->assertInProgress();
        if (($reason === EndReason::ForceEnded) !== ($endedBy !== null)) {
            throw DomainException::invariant('종료한 관리자는 강제 종료에만 기록된다');
        }
        if ($endedBy !== null && $endedBy->type() !== MemberType::Admin) {
            throw DomainException::invariant('강제 종료는 관리자만 한다');
        }
        if ($reason === EndReason::Expired && ($this->expiresAt === null || $endedAt != $this->expiresAt)) {
            throw DomainException::invariant('만료로 끝난 사용 세션의 종료 시각은 만료 시각이다');
        }
        if ($requeueBlockedUntil !== null && $requeueBlockedUntil <= $endedAt) {
            throw DomainException::invariant('재대기 제한이 풀리는 시각은 종료 시각보다 뒤여야 한다');
        }

        $this->endedAt = $endedAt;
        $this->endReason = $reason;
        $this->requeueBlockedUntil = $requeueBlockedUntil;
        $this->endedBy = $endedBy;
        $this->updatedAt = $now;
    }

    public function isEnded(): bool
    {
        return $this->endedAt !== null;
    }

    private function assertInProgress(): void
    {
        if ($this->isEnded()) {
            throw DomainException::invariant('끝난 사용 세션은 바뀌지 않는다');
        }
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function member(): Member
    {
        return $this->member;
    }

    public function equipment(): Equipment
    {
        return $this->equipment;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function extensionCount(): int
    {
        return $this->extensionCount;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function endReason(): ?EndReason
    {
        return $this->endReason;
    }

    public function requeueBlockedUntil(): ?DateTimeImmutable
    {
        return $this->requeueBlockedUntil;
    }

    public function endedBy(): ?Member
    {
        return $this->endedBy;
    }

    public function dbStatus(): DbStatus
    {
        return $this->dbStatus;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
