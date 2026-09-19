---
status: done
user_validated: true
---

# 2-4 도메인 기반 — 규칙 값과 엔티티, 사용 세션의 만료 시각 전이

> 완료 조건은 [`BUILD-PLAN.md`](../BUILD-PLAN.md) §3 의 2-4 행이 정본이다 — **해당 보장의 T1 초록.**
>
> 덮는 보장: [`equipment-queue`](../features/equipment-queue/README.md) **Q3 의 엔티티 쪽** — 만료 시각은 정해진 경로로만 바뀌고(대기자가 생길 때 생기고, 모두 빠지면 없어진다), 연장은 한 번만 적용되며, 끝난 사용 세션은 바뀌지 않는다. "연장이 되는가" 의 판정은 2-5 `ExtensionPolicy` 의 몫이다.

## 기대는 설계 절

이 계획의 `user_validated: true` 는 아래 절까지 확인했다는 뜻이다([`AGENTS.md`](../../AGENTS.md) §5).

| 문서 §절 | 이 칸에서 쓰는 것 |
|---|---|
| [`data-model.md`](../architecture/data-model.md) §1 · §3 · §6 | `Member` · `Equipment` · `UsageSession` 의 필드, UUIDv7, `created_at`·`updated_at`·`dbstatus` |
| [`data-model.md`](../architecture/data-model.md) §4.1 · §4.3 | 사용 세션의 상태(시각 두 개), 도메인이 지키는 불변식 중 사용 세션 줄 |
| [`data-model.md`](../architecture/data-model.md) §5 "만료 시각을 바꾸는 방법" | 사건 메서드 넷과 각각이 검사하는 불변식 |
| [`backend.md`](../architecture/backend.md) §1 · §2.4 · §2.6 · §8 D1~D4 | 폴더 배치, `QueueRules`, 사건 메서드 원칙, 엔티티·관계·식별자·ORM 없음 |
| [`domain-glossary.md`](../business/domain-glossary.md) §1~§3 | 코드 이름(`MemberType` · `EquipmentCode` · `EndReason` 등) |
| 루트 [`README.md`](../../README.md) §3 | 테스트가 쓰는 규칙 값(연장 5분·재대기 제한 5분 등) |

## 만드는 것

```text
apps/backend/src/Domain/
├── Shared/     QueueRules · DbStatus · DomainException
├── Member/     Member · MemberType
├── Equipment/  Equipment · EquipmentCode
└── Usage/      UsageSession · EndReason

apps/backend/tests/
├── Support/    DomainFixtures          # 회원·관리자·기구·시각·규칙 값을 짧게 만드는 도우미
└── Unit/Domain/
    ├── Shared/QueueRulesTest
    └── Usage/UsageSessionTest
```

`UsageSession` 의 사건 메서드 — 모양은 [`data-model.md` §5](../architecture/data-model.md) 표 그대로다.

| 메서드 | 받는 것 | 검사하는 불변식 (어기면 `DomainException`) |
|---|---|---|
| `start` (정적 생성) | 회원 · 기구 · 지금 | 회원은 `MEMBER` 유형이다 |
| `assignExpiry` | 만료 시각 · 지금 | 진행 중이다. 만료 시각은 지금보다 뒤다. 이미 있으면 **바꾸지 않는다**(첫 대기만) |
| `clearExpiry` | 지금 | 진행 중이다 |
| `extendTo` | 새 만료 시각 · 지금 | 진행 중이다. 만료 시각이 있다. 아직 연장하지 않았다. 새 시각이 지금 만료 시각보다 뒤다 |
| `end` | 종료 사유 · 종료 시각 · 재대기 제한 시각 · 지금 · 종료한 관리자(선택) | 진행 중이다. `FORCE_ENDED` ⟺ 종료한 관리자가 있고 그는 `ADMIN` 이다. `EXPIRED` 면 종료 시각 = 만료 시각. 재대기 제한 시각은 없거나 종료 시각보다 뒤다 |

## 세부 단계

| # | 할 일 | 산출물 | 확인 방법 |
|---|---|---|---|
| 1 | `composer require symfony/uid:7.4.*` · `config/services.yaml` 에서 `src/Domain/` 을 서비스 등록에서 뺀다(D3) | `composer.json` · `composer.lock` · `services.yaml` | `stan` · `test:fast` 초록 |
| 2 | `Shared` — `DbStatus`(`A`·`D`) · `DomainException` · `QueueRules`(규칙 값 다섯 + `requeueBlockedUntil`) | `src/Domain/Shared/*` · `QueueRulesTest` | 테스트 문장 10 초록 |
| 3 | `Member` · `MemberType` · `Equipment` · `EquipmentCode` — 생성과 읽기만 | `src/Domain/Member/*` · `src/Domain/Equipment/*` | `stan` 초록 (테스트 없음, D2) |
| 4 | `UsageSession` · `EndReason` — 사건 메서드와 불변식 · `DomainFixtures` | `src/Domain/Usage/*` · `tests/Support/DomainFixtures.php` · `UsageSessionTest` | 테스트 문장 1~9 초록 |
| 5 | 마무리 — 가드(`DependencyDirectionTest`)가 도메인의 uid 사용을 통과시키는지, `test:testdox` 가 한국어로 읽히는지 | — | `test:fast` · `test:testdox` |
| 6 | 문서 — BUILD-PLAN 2-4 행을 D1 에 맞추고, 이 계획을 `done` 으로 | 같은 커밋 | 링크 검사 |

커밋은 2 · 3 · 4 단위로 나눈다. 각 커밋이 초록이어야 하고, 가드가 라벨을 검사한다.

## 결정 사항

| # | 결정 | 버린 선택지 | 근거 |
|---|---|---|---|
| D1 | **`QueueEntry` 는 2-6 에서 만든다.** 2-4 의 엔티티는 셋(`Member` · `Equipment` · `UsageSession`) | BUILD-PLAN 행대로 넷 모두 | `QueueEntry` 의 첫 사용처는 2-6 `TagPolicy`(대기 등록)다. 지금 만들면 행동도 테스트도 없는 코드가 두 칸 동안 놓인다. "계획은 다음 칸 것만" 과 같은 이유 |
| D2 | **테스트는 행동이 있는 것만 쓴다** — `UsageSession` 의 사건 메서드와 `QueueRules` 의 계산. `Member` · `Equipment` · 값 객체의 생성 검사는 테스트하지 않는다 | 모든 클래스에 테스트 | 새로 보장되는 것이 없는 테스트를 늘리지 않는다([`AGENTS.md`](../../AGENTS.md) §4). 생성 검사가 틀리면 그것을 쓰는 사건 메서드 테스트가 먼저 깨진다 |
| D3 | **`services.yaml` 에서 `src/Domain/` 전체를 서비스 등록에서 뺀다.** 판정 객체를 서비스로 쓰는 4단계에서 범위를 좁힌다 | 엔티티만 골라 뺀다([`persistence.md`](../architecture/persistence.md) §1 의 최종 모양) | 지금 `Domain` 에는 서비스로 쓸 것이 없다. 엔티티를 서비스로 등록하면 생성자 인자를 자동 주입하려다 컨테이너 컴파일이 흔들린다 |
| D4 | **불변식 위반은 `DomainException` 을 던진다. 사용자에게 보일 거부 사유(`RejectionReason`)는 여기서 쓰지 않는다** — 2-5 에서 처음 들인다 | 엔티티가 `RejectionReason` 을 담아 던진다 | 엔티티 예외는 "판정 객체를 거치지 않고 불렀다" 는 버그 신호다([`backend.md`](../architecture/backend.md) §8 D1). 사용자 거부와 섞으면 버그가 정상 거부처럼 보인다 |
| D5 | **`end` 는 종료 시각을 받아 검사한다**([`data-model.md` §5](../architecture/data-model.md) 그대로) | 엔티티가 사유를 보고 종료 시각을 스스로 정한다 | 만료 종료 시각은 지연 정리(`SettlementPolicy`)가 계산하는 값이다(D1 의 원칙). 엔티티는 "만료면 만료 시각이어야 한다" 만 지킨다 |
| D6 | **테스트 도우미 `DomainFixtures` 를 `tests/Support/` 에 둔다** — `aMember()` · `anAdmin()` · `anEquipment(maxUsageMinutes)` · `at('09:05')`(고정 날짜의 서울 시각) · `rules()`(README §3 값) | 테스트마다 직접 조립 | 관계가 객체(`ManyToOne`)라 사용 세션 하나에 회원·기구가 따라온다. 준비 코드가 길면 테스트 문장과 본문이 멀어진다 |

## 테스트 문장 (`#[TestDox]`)

모두 `#[Feature('equipment-queue')]` · `#[Group('unit')]`.

**`UsageSessionTest`**

1. 사용 세션은 만료 시각 없이 시작한다
2. 관리자는 사용 세션을 시작할 수 없다
3. 첫 대기자가 정한 만료 시각은 대기자가 더 와도 바뀌지 않는다
4. 대기자가 모두 빠지면 만료 시각이 없어진다
5. 연장하면 만료 시각이 뒤로 밀리고, 연장은 한 번만 된다
6. 만료 시각이 없는 사용 세션은 연장할 수 없다
7. 끝난 사용 세션은 더 이상 바뀌지 않는다
8. 만료로 끝난 사용 세션의 종료 시각은 만료 시각이다
9. 종료한 관리자는 강제 종료에만 기록되고, 관리자여야 한다

**`QueueRulesTest`**

10. 재대기 제한은 대기자가 있던 채로 끝났을 때만, 종료 시각부터 재대기 제한 시간 동안 걸린다

Q3 와의 대응: 1·3·4 가 "만료 시각은 대기자가 있을 때만 생기고, 모두 빠지면 없어진다", 5·6 이 "연장은 한 번만" 의 엔티티 쪽이다. 7~9 는 [`data-model.md` §4.3](../architecture/data-model.md) 의 도메인 불변식이다.

## 확인 결과

2026-09-19 개발자 확인 — D1(`QueueEntry` 를 2-6 으로)과 테스트 문장 10개를 받아들인다.
