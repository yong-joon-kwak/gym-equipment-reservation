---
status: done
user_validated: true
---

# 2-3 T4 가드 — 기능 라벨과 의존 방향을 테스트로 강제한다

> 완료 조건은 [`BUILD-PLAN.md`](../BUILD-PLAN.md) §3 의 2-3 행이 정본이다 — **테스트 0건으로 초록, 라벨 없는 테스트 클래스를 일부러 넣으면 실패.**
>
> 첫 규칙 테스트(2-4)보다 먼저 들어가서, 이후의 모든 테스트가 처음부터 라벨을 달고 태어나게 한다([`BUILD-PLAN.md`](../BUILD-PLAN.md) §0 "가드를 나중에 붙이지 않는다").

## 기대는 설계 절

이 계획의 `user_validated: true` 는 아래 절까지 확인했다는 뜻이다([`AGENTS.md`](../../AGENTS.md) §5).

| 문서 §절 | 이 칸에서 쓰는 것 |
|---|---|
| [`test-as-specification.md`](../coding/test-as-specification.md) §2 "연결 어트리뷰트" · "가드" · "테스트 배치" | 어트리뷰트 모양, 검사 셋, 화이트리스트(`Support/`), 테스트 폴더 |
| [`docs/README.md`](../README.md) §2 · §3 | 커버리지 하한이 읽는 `status` 값, 슬러그 규칙 |
| [`backend.md`](../architecture/backend.md) §1 · §7 "의존 방향 가드" | 계층별 허용 목록 — 두 번째 가드의 검사 기준 |
| [`BUILD-PLAN.md`](../BUILD-PLAN.md) §6 | pre-commit 이 `--group structure` 를 돌린다 |

## 세부 단계

| # | 할 일 | 산출물 | 확인 방법 |
|---|---|---|---|
| 1 | `#[Feature]` 어트리뷰트 정의 | `tests/Support/Feature.php` | `composer run stan` 초록 |
| 2 | 기능 라벨 가드 — 검사 넷(결정 D1~D5) | `tests/Architecture/FeatureCoverageTest.php` | `composer run test:fast` 초록 (규칙 테스트 0건) |
| 3 | 의존 방향 가드 (결정 D6) | `tests/Architecture/DependencyDirectionTest.php` | `test:fast` 초록 (`src/Domain` 이 비어 있어도 초록) |
| 4 | **고의 실패 확인** — ① 라벨 없는 임시 테스트 ② 없는 슬러그를 단 임시 테스트 ③ 티어 라벨이 없는 임시 테스트 ④ `src/Domain` 에 `use Doctrine\ORM\EntityManagerInterface` 를 넣은 임시 클래스. 각각 넣고 돌려 실패를 본 뒤 지운다 | 커밋 메시지에 네 경우의 실패 메시지 요약 | 실패 메시지에 **문제의 클래스·메서드 이름**이 나온다 |
| 5 | pre-commit 이 가드를 돌리는지 확인 — 첫 `*Test.php` 가 생겨 lefthook 의 `phpunit-fast` skip 이 풀린다 | — | 커밋 시 lefthook 출력에 `phpunit-fast` 가 초록으로 나온다 |
| 6 | 문서 반영 — `test-as-specification.md` §2 의 검사 표에 넷째 검사(D4)와 어트리뷰트 위치(D1) · `backend.md` §7 에서 "의존 방향 가드" 행을 지우고 §1 에 가드 이름을 적음 · `docs/README.md` §2 에 D5 한 줄 | 같은 커밋 | 링크 검사 |

## 결정 사항

| # | 결정 | 버린 선택지 | 근거 |
|---|---|---|---|
| D1 | **`#[Feature]` 는 클래스와 메서드 둘 다에 붙일 수 있다.** 메서드의 것이 클래스의 것을 덮는다. 검사는 테스트 **메서드** 단위로 "라벨이 결정되는가" 를 본다 | 클래스에만 붙인다 | `TagPolicyTest` 한 파일이 `equipment-queue`(Q1·Q2·Q5)와 `equipment-catalog`(C3)를 함께 다룬다. 클래스에만 붙이면 기능마다 파일을 쪼개야 하고, 같은 판정 객체의 테스트가 흩어진다 |
| D2 | **테스트 클래스는 `tests/` 의 `*Test.php` 를 PSR-4(`App\Tests\` → `tests/`)로 클래스 이름에 대응시켜 리플렉션으로 읽는다.** 추상 클래스는 건너뛴다 | PHPUnit 의 테스트 목록 API · 소스 문자열 검색 | PHPUnit 내부 API 는 판마다 바뀐다. 문자열 검색은 주석 속 어트리뷰트에 속는다. 리플렉션은 PHP 가 실제로 읽은 어트리뷰트만 본다 |
| D3 | **화이트리스트는 `tests/Support/` 와 `tests/Architecture/`** 다 | `Support/` 만 | 가드 자신은 특정 기능의 보장이 아니라 저장소 전체의 구조를 본다. 라벨을 달면 가짜 좌표가 된다 |
| D4 | **넷째 검사 "티어 라벨" 을 더한다** — 모든 테스트 메서드는 `unit` · `collaboration` · `integration` · `structure` 중 **정확히 하나**의 `#[Group]` 을 가진다(클래스·메서드 어느 쪽이든) | 검사 셋만 둔다 | pre-commit 은 `--group` 으로 빠른 티어를 고른다. 티어 라벨이 빠진 테스트는 **어느 가드에서도 돌지 않고 조용히 사라진다.** 커버리지 하한도 `integration` 라벨로 T3 를 센다 |
| D5 | **2단계(2-4~2-7) 동안 두 기능 문서는 `planned` 로 둔다.** `in-progress` 로 바꾸는 것은 첫 T3 가 생기는 4단계 | 2-4 에서 바로 `in-progress` 로 | `in-progress` 는 커버리지 하한(T3 최소 1개)의 대상이다. 2단계에는 T3 가 없으므로 바꾸는 순간 가드가 실패한다. 도메인 규칙만 있는 상태는 아직 "흐름이 도는 기능" 이 아니다 — 가드가 그 사실을 그대로 말하게 둔다 |
| D6 | **의존 방향 가드를 이 칸에서 함께 만든다.** `src/Domain` · `src/Application` 의 PHP 파일을 `token_get_all` 로 읽어, 등장하는 모든 이름(`use` 와 본문의 정규화 이름)을 계층별 허용 목록과 대조한다 | ① 4단계로 미룸 ② `deptrac`·`phpat` 같은 도구 | ①: 도메인 코드가 생긴 뒤에 붙이면 이미 어긴 코드가 예외 목록이 된다(D4 와 같은 이유). ②: 도구 하나와 그 설정 형식을 더 들인다. 검사가 둘뿐이라 한 테스트 클래스로 충분하고, 결과가 같은 `testdox` 문장으로 읽힌다 |

D6 의 허용 목록 — [`backend.md`](../architecture/backend.md) §1 을 옮긴 것이다. 표가 바뀌면 이 테스트를 같은 커밋에서 고친다.

| 계층 | 외부 이름공간 허용 | 그 밖은 실패 |
|---|---|---|
| `App\Domain` | PHP 내장 · `Psr\Clock` · `Doctrine\ORM\Mapping` · `Symfony\Component\Uid` | 예: `Doctrine\ORM\EntityManagerInterface`, `Symfony\Component\HttpFoundation` |
| `App\Application` | `App\Domain` · PHP 내장 · `Psr\Clock` · `Symfony\Component\Uid` | `Doctrine\` 전부 · `Symfony\Component\HttpFoundation` · `App\Infrastructure` · `App\Ui` |

## 테스트 문장 (`#[TestDox]`)

이 목록이 이 칸의 명세다. 확인할 때 이 문장들만 읽으면 된다.

**`FeatureCoverageTest`** (`#[Group('structure')]`)

1. 모든 기능 라벨은 docs/features 에 실재하는 기능을 가리킨다
2. 진행 중이거나 완료된 기능은 통합 테스트를 하나 이상 가진다
3. 지원 코드와 구조 가드를 뺀 모든 테스트는 기능 라벨을 가진다
4. 모든 테스트는 티어 라벨을 정확히 하나 가진다

**`DependencyDirectionTest`** (`#[Group('structure')]`)

5. 도메인은 허용된 외부 이름공간만 쓴다
6. 응용 계층은 Doctrine 과 HTTP 와 바깥 계층을 모른다

실패 메시지는 문장이 아니라 **위반 목록**을 보인다 — 예: `라벨 없음: App\Tests\Unit\FooTest::testBar`.

## 확인 결과

2026-09-19 개발자 확인 — D4(티어 라벨 검사)·D6(의존 방향 가드)을 이 칸에 넣고, D5(2단계 동안 `planned` 유지)를 받아들인다.
