<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;

/**
 * 규칙 값의 정본은 루트 README.md §3. 값은 config 파라미터로 주입하고 상수로 박지 않는다.
 */
final readonly class QueueRules
{
    public function __construct(
        public int $noShowGraceMinutes,
        public int $extensionMinutes,
        public int $nearingExpiryMinutes,
        public int $requeueBlockMinutes,
        public int $overtimeGraceMinutes,
    ) {
        $values = [$noShowGraceMinutes, $extensionMinutes, $nearingExpiryMinutes, $requeueBlockMinutes, $overtimeGraceMinutes];
        if (min($values) <= 0) {
            throw DomainException::invariant('규칙 값은 모두 양수 분이어야 한다: '.implode(', ', $values));
        }
    }

    public function requeueBlockedUntil(DateTimeImmutable $endedAt, bool $hadWaiters): ?DateTimeImmutable
    {
        return $hadWaiters ? $endedAt->modify("+{$this->requeueBlockMinutes} minutes") : null;
    }
}
