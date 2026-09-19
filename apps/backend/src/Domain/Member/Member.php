<?php

declare(strict_types=1);

namespace App\Domain\Member;

use App\Domain\Shared\DbStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

class Member
{
    private readonly Uuid $id;

    private DbStatus $dbStatus = DbStatus::Alive;

    private readonly DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private function __construct(
        private readonly string $loginId,
        private string $passwordHash,
        private string $displayName,
        private readonly MemberType $type,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function register(
        string $loginId,
        string $passwordHash,
        string $displayName,
        MemberType $type,
        DateTimeImmutable $now,
    ): self {
        return new self($loginId, $passwordHash, $displayName, $type, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function loginId(): string
    {
        return $this->loginId;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function type(): MemberType
    {
        return $this->type;
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
