<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

use App\Domain\Shared\DbStatus;
use App\Domain\Shared\DomainException;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

class Equipment
{
    private readonly Uuid $id;

    private bool $active = true;

    private DbStatus $dbStatus = DbStatus::Alive;

    private readonly DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly EquipmentCode $code,
        private string $name,
        private string $kind,
        private int $maxUsageMinutes,
        DateTimeImmutable $now,
    ) {
        if ($maxUsageMinutes <= 0) {
            throw DomainException::invariant("최대 사용시간은 양수 분이어야 한다: {$maxUsageMinutes}");
        }

        $this->id = Uuid::v7();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function register(
        EquipmentCode $code,
        string $name,
        string $kind,
        int $maxUsageMinutes,
        DateTimeImmutable $now,
    ): self {
        return new self($code, $name, $kind, $maxUsageMinutes, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function code(): EquipmentCode
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function maxUsageMinutes(): int
    {
        return $this->maxUsageMinutes;
    }

    public function isActive(): bool
    {
        return $this->active;
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
