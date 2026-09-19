<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

final readonly class TestMethod
{
    /**
     * @param list<string> $tiers
     */
    public function __construct(
        public string $name,
        public string $directory,
        public ?string $feature,
        public array $tiers,
    ) {
    }
}
