<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use LogicException;

/**
 * 도메인 불변식을 어겼다 — 판정 객체를 거치지 않고 사건 메서드를 부른 버그다.
 * 사용자에게 보일 거부는 판정 객체가 돌려주는 거부 사유로 나타낸다.
 */
final class DomainException extends LogicException
{
    public static function invariant(string $message): self
    {
        return new self($message);
    }
}
