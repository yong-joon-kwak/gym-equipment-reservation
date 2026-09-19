<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

use App\Domain\Shared\DomainException;

final readonly class EquipmentCode
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw DomainException::invariant('기구 코드는 비어 있을 수 없다');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
