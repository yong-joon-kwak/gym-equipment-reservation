<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Feature
{
    /**
     * @param non-empty-string $slug docs/features/<slug>/ 와 같은 문자열
     */
    public function __construct(public string $slug)
    {
    }
}
