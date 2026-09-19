<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 허용 목록의 정본은 docs/architecture/backend.md §1. 표가 바뀌면 여기를 같은 커밋에서 고친다.
 */
#[Group('structure')]
final class DependencyDirectionTest extends TestCase
{
    #[TestDox('도메인은 허용된 외부 이름공간만 쓴다')]
    public function testDomainUsesOnlyAllowedNamespaces(): void
    {
        self::assertSame([], self::violations('Domain', [
            'App\\Domain',
            'Psr\\Clock',
            'Doctrine\\ORM\\Mapping',
            'Symfony\\Component\\Uid',
        ]));
    }

    #[TestDox('응용 계층은 Doctrine 과 HTTP 와 바깥 계층을 모른다')]
    public function testApplicationKnowsNeitherDoctrineNorHttpNorOuterLayers(): void
    {
        self::assertSame([], self::violations('Application', [
            'App\\Application',
            'App\\Domain',
            'Psr\\Clock',
            'Symfony\\Component\\Uid',
        ]));
    }

    /**
     * @param list<string> $allowedNamespaces
     *
     * @return list<string>
     */
    private static function violations(string $layer, array $allowedNamespaces): array
    {
        $srcDirectory = dirname(__DIR__, 2).'/src';
        $layerDirectory = $srcDirectory.'/'.$layer;
        if (!is_dir($layerDirectory)) {
            return [];
        }

        $violations = [];
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($layerDirectory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($srcDirectory) + 1);
            foreach (self::referencedNames((string) file_get_contents($file->getPathname())) as $name) {
                if (!self::isAllowed($name, $allowedNamespaces)) {
                    $violations[] = "{$relativePath}: {$name}";
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $allowedNamespaces
     */
    private static function isAllowed(string $name, array $allowedNamespaces): bool
    {
        if (!str_contains($name, '\\')) {
            return true;
        }

        foreach ($allowedNamespaces as $namespace) {
            if ($name === $namespace || str_starts_with($name, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 최상위 use 로 가져온 이름과, 본문에 \ 로 시작해 적은 이름.
     * 별칭(as)과 상대 이름은 use 쪽에서 이미 검사되므로 따로 풀지 않는다.
     *
     * @return list<string>
     */
    private static function referencedNames(string $source): array
    {
        $tokens = token_get_all($source);
        $names = [];
        $depth = 0;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                $depth += match ($token) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                continue;
            }

            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($token[0] === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($token[1], '\\');
            } elseif ($token[0] === T_USE && $depth === 0) {
                [$imported, $i] = self::importsFrom($tokens, $i + 1);
                array_push($names, ...$imported);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: list<string>, 1: int} 가져온 이름들, 끝난 위치
     */
    private static function importsFrom(array $tokens, int $start): array
    {
        $names = [];
        $prefix = '';
        $current = '';
        $skipAlias = false;

        for ($i = $start, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $kind = is_array($token) ? $token[0] : null;

            if ($kind === T_AS) {
                $skipAlias = true;
            } elseif (in_array($kind, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                if ($skipAlias) {
                    $skipAlias = false;
                } else {
                    $current .= $text;
                }
            } elseif ($text === '{') {
                $prefix = $current;
                $current = '';
            } elseif (in_array($text, [',', '}', ';'], true)) {
                if ($current !== '') {
                    $names[] = ltrim($prefix.$current, '\\');
                }
                $current = '';
                if ($text === ';') {
                    return [$names, $i];
                }
            }
        }

        return [$names, $i];
    }
}
