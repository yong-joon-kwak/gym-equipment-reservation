<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\Feature;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

#[Group('structure')]
final class FeatureCoverageTest extends TestCase
{
    private const array TIERS = ['unit', 'collaboration', 'integration', 'structure'];

    private const array COVERAGE_STATUSES = ['in-progress', 'done'];

    private const array UNLABELED_DIRECTORIES = ['Support', 'Architecture'];

    /** @var list<TestMethod>|null */
    private static ?array $testMethods = null;

    #[TestDox('모든 기능 라벨은 docs/features 에 실재하는 기능을 가리킨다')]
    public function testEveryFeatureLabelPointsToAnExistingFeature(): void
    {
        $violations = [];
        foreach (self::testMethods() as $test) {
            if ($test->feature !== null && !is_dir(self::featuresDirectory().'/'.$test->feature)) {
                $violations[] = "없는 기능 '{$test->feature}': {$test->name}";
            }
        }

        self::assertSame([], $violations);
    }

    #[TestDox('진행 중이거나 완료된 기능은 통합 테스트를 하나 이상 가진다')]
    public function testFeaturesInProgressOrDoneHaveAnIntegrationTest(): void
    {
        $violations = [];
        foreach (self::featureStatuses() as $slug => $status) {
            if (!in_array($status, self::COVERAGE_STATUSES, true)) {
                continue;
            }

            $integrationTests = array_filter(
                self::testMethods(),
                static fn (TestMethod $test): bool => $test->feature === $slug && $test->tiers === ['integration'],
            );
            if ($integrationTests === []) {
                $violations[] = "통합 테스트 없음: {$slug} (status: {$status})";
            }
        }

        self::assertSame([], $violations);
    }

    #[TestDox('지원 코드와 구조 가드를 뺀 모든 테스트는 기능 라벨을 가진다')]
    public function testEveryTestOutsideTheWhitelistHasAFeatureLabel(): void
    {
        $violations = [];
        foreach (self::testMethods() as $test) {
            if ($test->feature === null && !in_array($test->directory, self::UNLABELED_DIRECTORIES, true)) {
                $violations[] = "기능 라벨 없음: {$test->name}";
            }
        }

        self::assertSame([], $violations);
    }

    #[TestDox('모든 테스트는 티어 라벨을 정확히 하나 가진다')]
    public function testEveryTestHasExactlyOneTierLabel(): void
    {
        $violations = [];
        foreach (self::testMethods() as $test) {
            if (count($test->tiers) !== 1) {
                $tiers = $test->tiers === [] ? '없음' : implode(', ', $test->tiers);
                $violations[] = "티어 라벨 {$tiers}: {$test->name}";
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return list<TestMethod>
     */
    private static function testMethods(): array
    {
        return self::$testMethods ??= self::collectTestMethods();
    }

    /**
     * @return list<TestMethod>
     */
    private static function collectTestMethods(): array
    {
        $testsDirectory = dirname(__DIR__);
        $methods = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDirectory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($testsDirectory) + 1);
            $className = 'App\\Tests\\'.str_replace('/', '\\', substr($relativePath, 0, -strlen('.php')));
            if (!class_exists($className)) {
                continue;
            }

            $class = new ReflectionClass($className);
            if ($class->isAbstract() || !$class->isSubclassOf(TestCase::class)) {
                continue;
            }

            $directory = explode('/', $relativePath)[0];
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (self::isTestMethod($method)) {
                    $methods[] = new TestMethod(
                        name: $class->getName().'::'.$method->getName(),
                        directory: $directory,
                        feature: self::featureOf($class, $method),
                        tiers: self::tiersOf($class, $method),
                    );
                }
            }
        }

        return $methods;
    }

    private static function isTestMethod(ReflectionMethod $method): bool
    {
        return $method->getDeclaringClass()->getName() !== TestCase::class
            && (str_starts_with($method->getName(), 'test') || $method->getAttributes(Test::class) !== []);
    }

    /**
     * @param ReflectionClass<TestCase> $class
     */
    private static function featureOf(ReflectionClass $class, ReflectionMethod $method): ?string
    {
        $attribute = $method->getAttributes(Feature::class)[0] ?? $class->getAttributes(Feature::class)[0] ?? null;

        return $attribute?->newInstance()->slug;
    }

    /**
     * @param ReflectionClass<TestCase> $class
     *
     * @return list<string>
     */
    private static function tiersOf(ReflectionClass $class, ReflectionMethod $method): array
    {
        $groups = array_map(
            static fn ($attribute): string => $attribute->newInstance()->name(),
            [...$class->getAttributes(Group::class), ...$method->getAttributes(Group::class)],
        );

        return array_values(array_unique(array_intersect($groups, self::TIERS)));
    }

    /**
     * @return array<string, string> 슬러그 => status
     */
    private static function featureStatuses(): array
    {
        $statuses = [];
        foreach (glob(self::featuresDirectory().'/*/README.md') ?: [] as $readme) {
            $slug = basename(dirname($readme));
            $content = (string) file_get_contents($readme);
            $frontmatter = preg_match('/\A---\n(.*?)\n---/s', $content, $match) === 1 ? Yaml::parse($match[1]) : null;
            $status = is_array($frontmatter) ? ($frontmatter['status'] ?? null) : null;
            $statuses[$slug] = is_string($status) ? $status : '(없음)';
        }

        return $statuses;
    }

    private static function featuresDirectory(): string
    {
        return dirname(__DIR__, 4).'/docs/features';
    }
}
