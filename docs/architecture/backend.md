# 백엔드 스펙 — 계층 · 판정 객체 · 거부 사유 · 물리 매핑 · HTTP 표면

> `apps/backend` 의 **아키텍처 정본**이다. 코드가 어느 계층에 놓이는지, 규칙을 어떤 객체가 판정하는지, 데이터 모델의 불변식을 DB 가 어떻게 강제하는지, 바깥에 어떤 HTTP 표면을 내는지를 정한다.
>
> 되풀이하지 않는 것: 제품 규칙과 값 → 루트 [`README.md`](../../README.md) §3 · 용어와 코드 이름 → [`domain-glossary.md`](../business/domain-glossary.md) · 엔티티·관계·상태 → [`data-model.md`](data-model.md) · 업무 흐름 → [`system-overview.md`](system-overview.md) · 테스트 티어와 라벨 규약 → [`coding/test-as-specification.md`](../coding/test-as-specification.md) · OpenAPI 생성 경로 → [`api-contract.md`](api-contract.md) · 만드는 순서 → [`BUILD-PLAN.md`](../BUILD-PLAN.md)
>
> 스택: **PHP 8.4 · Symfony 7.4(LTS) · Doctrine ORM 3 · MariaDB 12.3**

---

## 1. 계층과 의존 방향

```text
apps/backend/src/
├── Domain/                     # 규칙. 프레임워크를 모른다
│   ├── Member/                 #   Member · MemberType · MemberRepository(인터페이스)
│   ├── Equipment/              #   Equipment · EquipmentCode · EquipmentKind · EquipmentRepository
│   ├── Usage/                  #   UsageSession · EndReason · UsageSessionRepository
│   ├── Queue/                  #   QueueEntry · QueueStatus · CancelReason · QueueEntryRepository
│   ├── Tagging/                #   TagPolicy · TagFacts · TagDecision
│   ├── Settlement/             #   SettlementPolicy — 지연 정리 계산
│   ├── Extension/              #   ExtensionPolicy
│   └── Shared/                 #   QueueRules(규칙 값) · RejectionReason · DbStatus · DomainException
├── Application/                # 유스케이스. 트랜잭션 경계
│   ├── Tagging/                #   TagEquipment
│   ├── Usage/                  #   EndSession · ExtendSession · ForceEndSession
│   ├── Queue/                  #   CancelQueueEntry
│   ├── Equipment/              #   RegisterEquipment · UpdateEquipment · DeactivateEquipment
│   └── Status/                 #   GetEquipmentStatus · GetEquipmentDetail — 조회는 계산만 한다
├── Infrastructure/
│   ├── Doctrine/               #   리포지토리 구현 · 타입 매핑
│   └── Security/               #   SecurityUser(Member 를 감싼다) · 사용자 공급자
└── Ui/Http/                    # 컨트롤러 · 요청/응답 DTO · 예외 → HTTP 변환
```

의존은 **안쪽으로만** 흐른다.

| 계층 | 알아도 되는 것 | 절대 모르는 것 |
|---|---|---|
| `Domain` | PHP 표준 · `Psr\Clock` | Doctrine 의 동작 · Symfony · HTTP · 다른 계층 |
| `Application` | `Domain` (인터페이스로만 밖을 부른다) | Doctrine · HTTP |
| `Infrastructure` | `Domain` · Doctrine · Symfony Security | `Ui` |
| `Ui/Http` | `Application` · DTO | `Domain` 엔티티를 응답에 직접 싣지 않는다 |

엔티티는 `Domain` 에 두고 Doctrine 매핑 어트리뷰트만 붙인다(§4.1). 어트리뷰트는 메타데이터일 뿐이라 도메인이 Doctrine 의 동작을 알게 되지는 않는다.

### 계층마다 제 티어가 있다

| 계층 | 여기서 묻는 것 | 티어 | 대역 |
|---|---|---|---|
| `Domain` — 판정 객체 셋 · 엔티티 | 판정과 상태 전이가 옳은가 | T1 | 없음 |
| `Application` — 유스케이스 | 도메인과 저장소를 엮은 **흐름의 결과**가 옳은가 | T2 | 경계만 가짜(fake) |
| `Infrastructure` · `Ui/Http` | 실제 DB·HTTP 에서 흐름·제약·동시성이 성립하는가 | T3 | 없음 (실 DB) |
| 전체 | 좌표·라벨·의존 방향이 지켜지는가 | T4 | 없음 |

"목 없이 검증한다" 는 **T1 에 붙은 조건이지 백엔드 전체에 붙은 조건이 아니다.** 유스케이스는 T2 로 검증하고, 거기서는 인메모리 가짜 저장소와 고정 시계를 쓴다. 금지된 것은 **T2 가 결과 대신 호출 절차를 단언하는 것** 하나다([`test-as-specification.md` §2](../coding/test-as-specification.md)).

의존 방향은 이 대응을 유지하기 위한 것이다. 규칙 객체가 Doctrine 을 부르면 규칙 테스트가 DB 를 끌고 오고, **T1 이 사실상 T3 가 된다** — 티어가 무너지는 첫 지점이 여기다. 저장소 인터페이스를 `Domain` 에 두고 구현을 `Infrastructure` 에 두는 이유도 같다. T2 의 인메모리 가짜는 이 인터페이스를 구현한 것이지 목이 아니다.

---

## 2. 규칙을 판정하는 객체

엔티티의 필드·관계·상태는 [`data-model.md`](data-model.md) 가 정본이다. 여기서는 **규칙을 어떤 객체가 판정하는가** 만 정한다.

### 2.1 판정 객체 셋

| 객체 | 판정하는 것 | 받는 것 | 돌려주는 것 |
|---|---|---|---|
| `TagPolicy` | 태깅 한 번이 무엇이 되는가 | `TagFacts` · 지금 시각 | `TagDecision` |
| `SettlementPolicy` | 지금 시각 기준으로 기록을 어떻게 확정해야 하는가 | 기구 E·회원 M 의 진행 중 기록 · 지금 시각 | 적용할 변경 목록 |
| `ExtensionPolicy` | 연장이 되는가 | 사용 세션 · 지금 시각 | 통과, 또는 `RejectionReason` |

셋 다 `final readonly class` 이고, 생성자로는 규칙 값(`QueueRules`, §2.4)만 받는다. 저장소도 시계도 주입받지 않는다. 지금 시각은 **인자로** 받는다 — 응용 서비스가 `ClockInterface` 에서 읽어 넘긴다.

### 2.2 `TagPolicy` — 그림 한 장이 곧 클래스 하나

```php
final readonly class TagPolicy
{
    public function __construct(private QueueRules $rules) {}

    public function decide(TagFacts $facts, DateTimeImmutable $now): TagDecision;
}
```

`decide()` 의 판정 순서는 [`system-overview.md` §2.1](system-overview.md) 의 흐름도와 **같은 순서로 고정**한다. 여러 조건이 한꺼번에 맞아도 결과가 하나로 정해져야 T1 이 결정적이다.

```text
equipment_inactive → 내가 사용 중 → 내가 호출됨 → 내가 대기 중 → 비어 있음
                   → already_waiting_elsewhere → requeue_blocked → 대기 등록
```

| `TagDecision` | 뜻 |
|---|---|
| `StartSession` | 사용 시작. 회원이 다른 기구를 쓰고 있었다면 그 세션을 전환 종료한다는 표시를 함께 담는다 |
| `Enqueue` | 대기 등록. 첫 대기라면 사용 중 세션의 만료 시각을 정해야 한다는 표시를 함께 담는다 |
| `Unchanged` | 변화 없음. 이미 그 기구를 쓰는 중(→ 화면에 종료 버튼)이거나 기다리는 중(→ 순번) |
| `Rejected` | 거부. `RejectionReason` 하나를 담는다 |

- **사용 중에 다시 태깅해도 본인 종료가 되지 않는다.** QR 페이지를 새로고침할 때마다 태깅이 다시 오기 때문이다. 본인 종료는 화면의 종료 버튼(`EndSession`)으로만 한다.
- 관리자는 `TagPolicy` 에 오지 않는다. 관리자의 기구 상세는 판정 없이 관리용 표현을 돌려준다(§5.1).
- 정책은 사유를 **반환**하고, 예외로 바꾸는 것은 응용 서비스다. 도메인이 HTTP 를 모르는 것과 같은 이유로, 판정이 제어 흐름을 결정하지 않는다.

### 2.3 `SettlementPolicy` — 지연 정리

워커가 없으므로, 시간이 흘러 생긴 일(만료·노쇼·차례 호출)은 **다음 태깅 때 기록한다**([`system-overview.md` §2.2](system-overview.md)). 그 계산을 이 객체 하나가 맡는다.

- **받는 것**: 태깅한 기구 E 와 태깅한 회원 M 에 걸린 진행 중인 사용 세션과 대기(`dbstatus = 'A'`), 지금 시각.
- **하는 일**: `expires_at` 이 지난 세션을 `EXPIRED` 로 끝내고, 대기열을 앞에서부터 따라가며 호출 시각을 계산해 `CALLED` 와 `NO_SHOW` 를 확정한다. 앞 대기가 노쇼면 다음 대기의 호출 시각은 앞 대기의 `called_at + 노쇼 유예` 다.
- **돌려주는 것**: 적용할 변경 목록. 적용과 저장은 응용 서비스가 한다.

**조회도 같은 객체를 쓴다.** `GetEquipmentStatus` · `GetEquipmentDetail` 은 `SettlementPolicy` 를 돌려 결과를 화면에 쓰되, **저장하지 않는다.** "조회는 상태를 바꾸지 않으면서도 기록과 같은 답을 낸다" 는 약속이, 같은 함수를 쓰기 때문에 지켜진다.

같은 입력과 같은 시각이면 결과가 같다(멱등). 동시에 들어온 태깅 두 개가 같은 세션을 정리해도 같은 값을 쓴다(§4.4).

### 2.4 규칙 값 — `QueueRules`

규칙 값의 정본은 루트 [`README.md`](../../README.md) §3 이다. 여기서는 **이름과 주입 방식**만 정한다.

| 필드 | README §3 의 규칙 |
|---|---|
| `noShowGraceMinutes` | 노쇼 유예 |
| `extensionMinutes` | 연장 |
| `nearingExpiryMinutes` | 마감 임박 |
| `requeueBlockMinutes` | 재대기 제한 |
| `overtimeGraceMinutes` | 초과 중 대기 |

**상수로 박지 않는다.** `QueueRules` 는 값 객체이고, 값은 `config/services.yaml` 의 파라미터에서 주입한다. T1 이 값을 바꿔 가며 경계를 검증할 수 있어야 한다. 최대 사용시간은 기구마다 다르므로 여기 없고 `Equipment` 에 있다.

### 2.5 사실은 응용 서비스가 모은다

판정 객체는 **인자로 받은 것만 안다.** 판정에 필요한 사실(이 회원이 다른 기구에 대기가 있는가, 이 기구가 사용 중인가, 재대기 제한이 언제 풀리는가)을 저장소에서 모아 `TagFacts` 로 만드는 것은 `TagEquipment` 의 책임이다.

`TagEquipment` 한 번의 흐름:

```text
트랜잭션 시작
  → 기구 E · 회원 M 의 진행 중 기록 읽기
  → SettlementPolicy 로 정리하고 적용
  → 정리된 기록으로 TagFacts 만들기
  → TagPolicy.decide()
  → 결정 적용 (세션 시작·전환 종료·대기 등록·만료 시각 설정)
트랜잭션 커밋
```

대가: 판정 객체의 인자가 늘고, 응용 서비스가 "무엇을 조회해야 하는지" 를 안다. 규칙이 바뀌면 조회하는 쪽도 함께 바뀐다. 그 결합을 판정 객체 안으로 숨기면 T1 이 저장소를 끌고 오므로, 밖에 드러난 채로 둔다.

### 2.6 만료 시각이 바뀌는 곳

`expires_at` 은 여러 유스케이스가 건드리므로, **바꾸는 방법을 `UsageSession` 의 메서드로 한정한다.** 응용 서비스가 필드를 직접 쓰지 않는다.

| 사건 | 유스케이스 | `UsageSession` 메서드 | 결과 |
|---|---|---|---|
| 첫 대기 등록 | `TagEquipment` | `onWaiterArrived(now, maxMinutes, rules)` | 최대 사용시간 전이면 `started_at + 최대 사용시간`, 이미 넘겼으면 `now + 초과 중 대기` |
| 마지막 대기가 취소 | `CancelQueueEntry` | `onWaitersGone()` | `null` |
| 연장 | `ExtendSession` | `extend(now, rules)` | `+ 연장`, `extension_count + 1` |
| 종료(모든 사유) | 여러 곳 | `end(reason, now, hasWaiters, rules, ?admin)` | `ended_at`·`end_reason`, 대기자가 있으면 `requeue_blocked_until` |

---

## 3. 거부 사유(`reason`) — 계약의 정본

판정의 소유자가 백엔드이므로, **거부 사유 목록의 정본도 여기다.** 프론트는 이 문자열을 그대로 노출하고 자체 판정을 두지 않는다([`api-contract.md`](api-contract.md)).

| `reason` | HTTP | 언제 | 어디서 |
|---|---|---|---|
| `equipment_not_found` | 404 | 기구 코드가 없거나 삭제됨(`dbstatus = 'D'`) | 태깅 · 조회 · 관리 |
| `equipment_inactive` | 422 | 비활성 기구를 태깅 | 태깅 |
| `already_waiting_elsewhere` | 409 | 다른 기구에 진행 중인 대기가 있는데 대기 등록하려 함 | 태깅 |
| `requeue_blocked` | 422 | 재대기 제한이 풀리기 전에 같은 기구에 대기 등록하려 함 | 태깅 |
| `equipment_taken` | 409 | 같은 기구에 대한 동시 태깅이 DB 제약에 걸림. 다시 태깅하면 판정이 새로 난다(§4.4) | 태깅 |
| `concurrent_request` | 409 | 같은 회원의 요청 두 개가 동시에 들어와 DB 제약에 걸림 | 태깅 |
| `usage_session_not_found` | 404 | 끝내거나 연장할 진행 중 사용 세션이 없음 | 종료 · 연장 · 강제 종료 |
| `extension_not_available` | 422 | 만료 시각이 없음 — 대기자가 없어 연장할 필요가 없다 | 연장 |
| `not_nearing_expiry` | 422 | 마감 임박 구간 전 | 연장 |
| `extension_limit_reached` | 422 | 이미 한 번 연장함 | 연장 |
| `queue_entry_not_found` | 404 | 취소할 진행 중 대기가 없음 | 대기 취소 |
| `duplicate_equipment_code` | 409 | 같은 코드의 기구가 이미 있음(삭제된 것 포함) | 기구 등록 |
| `member_only` | 403 | 관리자가 회원 조작(태깅·종료·연장·대기 취소)을 부름 | 회원 조작 |
| `admin_only` | 403 | 회원이 관리 조작을 부름 | 관리 |
| `unauthenticated` | 401 | 로그인하지 않음 | 전부 |
| `invalid_credentials` | 401 | 로그인 실패 | 로그인 |
| `invalid_request` | 422 | 입력 형식 오류. 필드별 오류 목록을 덧붙인다 | 전부 |

- `409` 는 **다른 데이터와 부딪혀서** 거부된 것, `422` 는 **요청 자체가 규칙을 어긴** 것이다.
- **남의 것은 404 다.** 사용 세션·대기는 "내 진행 중인 것" 으로만 찾으므로(§5.1), 남의 것은 존재 여부부터 드러내지 않는다. 옛 `not_owner`(403)는 두지 않는다.
- 사유는 `RejectionReason` **enum(string)** 하나로 두고, 값이 곧 응답 문자열이다. 새 사유는 enum 에 추가되므로 문자열 오타가 타입 검사에서 걸린다.
- 표기는 **snake_case** 다. 응답에 실리는 문자열이라 JSON 관례를 따르고, 도메인 enum(상태·종료 사유, UPPER_SNAKE)과 일부러 다르게 둔다 — 둘을 섞어 읽지 않게 하려는 것이다.

---

## 4. 영속화 (Doctrine ORM 3 · MariaDB)

### 4.1 매핑 원칙

- 매핑은 **어트리뷰트**로 한다(XML·YAML 매핑을 쓰지 않는다). 엔티티는 `Domain` 에 두되, 라이프사이클 콜백·프록시 의존 코드는 쓰지 않는다.
- 테이블 이름은 엔티티의 snake_case 단수형이다 — `member` · `equipment` · `usage_session` · `queue_entry`.
- PK 컬럼 이름은 `<엔티티>_id` 다([`data-model.md` §3](data-model.md)).
- 엔진 InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.

### 4.2 논리 타입 → 물리 타입

| 논리 타입([`data-model.md`](data-model.md)) | MariaDB | 비고 |
|---|---|---|
| `uuid` | `BINARY(16)` | `symfony/uid` 의 Doctrine 타입. UUIDv7 |
| `datetime` | `DATETIME` | 서울 시간 그대로(§4.5). 초 단위 |
| `string` (enum) | `VARCHAR(20)` | 상태·종료 사유·취소 사유·회원 유형 |
| `string` | `VARCHAR(n)` | 길이는 엔티티 매핑에서 정한다 |
| `int` | `INT` | |
| `boolean` | `TINYINT(1)` | |
| `dbstatus` | `CHAR(1) NOT NULL DEFAULT 'A'` | `'A'` · `'D'` |

유일 제약: `equipment.code`, `member.login_id`. **삭제된 행도 포함한다**([`data-model.md` 가정](data-model.md)).

### 4.3 불변식을 DB 로 — 생성 컬럼 + 유니크

[`data-model.md` §4.3](data-model.md) 에서 "DB 제약" 이 최종 보증인 불변식 셋을, **진행 중일 때만 값이 있는 생성 컬럼**에 유니크를 걸어 강제한다. MariaDB 에는 조건부 유니크 인덱스(`WHERE …`)가 없고, 유니크 인덱스는 NULL 을 여럿 허용하기 때문이다.

```sql
-- usage_session: 기구당 · 회원당 진행 중인 사용 세션 1개
active_equipment_id BINARY(16) AS (IF(ended_at IS NULL AND dbstatus = 'A', equipment_id, NULL)) PERSISTENT,
active_member_id    BINARY(16) AS (IF(ended_at IS NULL AND dbstatus = 'A', member_id,    NULL)) PERSISTENT,
UNIQUE KEY uniq_usage_session_active_equipment (active_equipment_id),
UNIQUE KEY uniq_usage_session_active_member    (active_member_id),

-- queue_entry: 회원당 진행 중인 대기 1개
active_member_id BINARY(16) AS (IF(status IN ('WAITING', 'CALLED') AND dbstatus = 'A', member_id, NULL)) PERSISTENT,
UNIQUE KEY uniq_queue_entry_active_member (active_member_id)
```

- 끝난 행·삭제된 행은 생성 컬럼이 NULL 이라 몇 개든 남을 수 있고, **진행 중인 것만 하나로 제한된다.**
- 옛 `active_slot` 은 앱이 상태와 함께 값을 맞춰야 했고, 어긋나면 제약이 조용히 무력해졌다. 생성 컬럼은 **DB 가 계산하므로 어긋날 수 없다.**
- "기록상 진행 중" 을 센다. 그래서 판정 전에 지연 정리가 먼저 돈다(§2.5) — 이미 만료된 세션이 새 세션을 막지 않게.
- 생성 컬럼은 도메인이 쓰지 않는다. 정의는 마이그레이션에 두고, 엔티티 매핑에서 어떻게 다룰지는 §8.

그 밖의 인덱스:

| 테이블 | 인덱스 | 쓰는 곳 |
|---|---|---|
| `queue_entry` | `(equipment_id, status, enqueued_at)` | 대기열 조회·순번 계산 |
| `usage_session` | `(equipment_id, ended_at)` | 기구의 진행 중 세션 |
| `usage_session` | `(member_id, equipment_id, ended_at)` | 재대기 제한(그 회원의 그 기구 마지막 세션) |

### 4.4 트랜잭션과 동시성

- **유스케이스 하나 = 트랜잭션 하나.** 경계는 `Application` 에 둔다. 컨트롤러도 저장소도 트랜잭션을 열지 않는다. 지연 정리·판정·적용이 한 트랜잭션 안에서 일어난다.
- **낙관적으로 진행하고, 잠금(`SELECT … FOR UPDATE`)을 쓰지 않는다.** 판정은 먼저 도메인이 하고, 생성 컬럼 유니크는 판정을 함께 통과한 동시 요청 둘이 부딪혔을 때의 마지막 그물이다.
- `UniqueConstraintViolationException` 은 제약 이름으로 사유를 고른다.

  | 제약 | `reason` |
  |---|---|
  | `uniq_usage_session_active_equipment` | `equipment_taken` |
  | `uniq_usage_session_active_member` · `uniq_queue_entry_active_member` | `concurrent_request` |
  | `equipment.code` 유일 | `duplicate_equipment_code` |

- 지연 정리는 멱등이라, 두 트랜잭션이 같은 만료 세션을 동시에 정리해도 같은 값을 쓴다.
- **알려진 틈**: 대기 등록과 본인 종료가 같은 순간에 겹치면, 종료 쪽이 새 대기자를 보지 못해 `requeue_blocked_until` 을 비워 둘 수 있다. 결과는 재대기 제한 한 번이 빠지는 것이고, 주인이 둘이 되는 일은 없다. 이 틈을 막으려면 잠금이 필요한데, 데모 규모에서 그 비용을 치르지 않는다.

### 4.5 시간대

- 저장·판정·표시 모두 **서울 시간(`Asia/Seoul`)** 이다. 근거는 [`data-model.md`](data-model.md) §5·§7. 앱(PHP `date.timezone`)과 DB 세션 시간대(`time_zone = '+09:00'`)를 같은 값으로 맞춘다.
- `DateTimeImmutable` 만 쓰고 가변 `DateTime` 은 도메인에 들이지 않는다.
- 현재 시각은 **`Psr\Clock\ClockInterface`** 로만 얻는다. `new DateTimeImmutable()` 을 도메인·응용에서 직접 부르지 않는다. T1 은 시각을 인자로 넘기고, T2 는 `symfony/clock` 의 `MockClock` 으로 시간을 고정한다 — 노쇼 유예·마감 임박·재대기 제한을 결정적으로 시험할 수 있게 하는 유일한 장치다.

### 4.6 마이그레이션

`doctrine/doctrine-migrations-bundle` 을 쓴다. **스키마 도구(`doctrine:schema:update`)로 운영 스키마를 만들지 않는다.**

AGENTS.md §5 의 정지선이 여기 걸린다 — **AI 는 마이그레이션 파일 생성과 검토까지만 하고, 실제 적용(`doctrine:migrations:migrate`)은 사람이 한다.**

---

## 5. HTTP 표면

### 5.1 엔드포인트

| 메서드 · 경로 | 하는 일 | 권한 |
|---|---|---|
| `POST /api/login` | 로그인(`login_id` · `password`) | 누구나 |
| `POST /api/logout` | 로그아웃 | 로그인 |
| `GET /api/me` | 현재 계정과 회원 유형 | 로그인 |
| `GET /api/equipment-status` | **현황 목록** — 활성 기구마다 사용 중 / 비어 있음, 대기 인원. 폴링 대상 | 로그인 |
| `GET /api/equipment/{code}` | **기구 상세** — 회원에게는 내 상태(사용 중이면 남은 시간·마감 임박·연장 가능 여부, 대기 중이면 순번·예상 대기 시간·내 차례·남은 유예). 관리자에게는 관리용 표현 | 로그인 |
| `POST /api/equipment/{code}/tag` | **태깅.** 결과(`started` · `enqueued` · `unchanged`)와 새 기구 상세를 돌려준다 | 회원 |
| `POST /api/usage-sessions/current/end` | 본인 종료(종료 버튼) | 회원 |
| `POST /api/usage-sessions/current/extend` | 연장 | 회원 |
| `POST /api/queue-entries/current/cancel` | 본인 대기 취소 | 회원 |
| `GET /api/admin/equipment` | 기구 목록(비활성 포함) | 관리자 |
| `POST /api/admin/equipment` | 기구 등록 | 관리자 |
| `PATCH /api/admin/equipment/{code}` | 이름·종류·최대 사용시간 수정 | 관리자 |
| `POST /api/admin/equipment/{code}/deactivate` | 비활성화 — 진행 중인 사용 세션은 강제 종료, 대기는 모두 취소 | 관리자 |
| `POST /api/admin/equipment/{code}/force-end` | 진행 중인 사용 세션 강제 종료 | 관리자 |

- **바깥에 노출하는 기구 식별자는 `code` 다.** UUID 는 내부 참조용이다([`data-model.md` §3](data-model.md)).
- **`current` 로 찾는다.** 회원에게 진행 중인 사용 세션·대기는 각각 최대 하나이므로, 식별자를 주고받지 않는다. 남의 것을 가리킬 방법이 없으니 소유자 검사도 필요 없다.
- **`GET` 은 상태를 바꾸지 않는다.** 지연 정리를 계산만 하고 저장하지 않는다(§2.3).
- **태깅은 새로고침에 안전하다.** QR 페이지(`/e/{code}`)는 들어올 때 태깅을 한 번 보내고, 이후로는 기구 상세를 폴링한다. 새로고침으로 태깅이 다시 와도, 이미 쓰는 중이거나 기다리는 중이면 `unchanged` 다(§2.2).

### 5.2 인증 — 세션 로그인

- `symfony/security-bundle` 의 **`json_login`** 으로 `/api/login` 을 처리하고, 로그인 상태는 **세션 쿠키**로 유지한다(`HttpOnly` · `SameSite=Lax`, 운영에서는 `Secure`).
- 사용자 공급자는 `Infrastructure/Security` 에 둔다. `Member` 를 `login_id` 로 읽어 `SecurityUser` 로 감싼다. **`Domain` 의 `Member` 가 Symfony 의 `UserInterface` 를 구현하지 않는다** — 도메인이 프레임워크를 모르게 하려는 것이다.
- 회원 유형은 역할로 바꾼다 — `MEMBER` → `ROLE_MEMBER`, `ADMIN` → `ROLE_ADMIN`. `access_control` 로 `/api/admin` 은 `ROLE_ADMIN`, 회원 조작은 `ROLE_MEMBER` 로 막는다. 막힌 요청은 §3 의 `admin_only` · `member_only` 로 응답한다.
- 비밀번호는 Symfony PasswordHasher(`auto`)로 해시한다.
- **CSRF 토큰을 두지 않는다.** 쿠키가 `SameSite=Lax` 이고 모든 변경 요청이 `Content-Type: application/json` 을 요구하므로, 다른 사이트의 폼 전송으로는 요청을 만들 수 없다.
- 로그인하지 않은 요청은 `401` + `unauthenticated` 로 응답한다(§5.3). 기본 로그인 페이지로 돌려보내지 않는다.

### 5.3 응답 형태

성공은 자원 표현을 그대로 준다. 실패는 **한 가지 형태만** 쓴다.

```json
{ "error": "TagRejected", "reason": "equipment_inactive", "message": "사용할 수 없는 기구입니다" }
```

- `error` 는 예외 클래스의 짧은 이름, `reason` 은 §3 의 값, `message` 는 사람이 읽는 한국어 문장이다.
- 프론트가 분기에 쓰는 것은 **`reason` 뿐이다.** `message` 는 표시용이고 계약이 아니다.
- 변환은 `Ui/Http` 의 예외 리스너 한 곳에서 한다. 컨트롤러마다 try/catch 를 두지 않는다.
- 검증 실패(입력 형식)는 `422` + `reason: "invalid_request"` 와 필드별 오류 목록을 덧붙인다.

---

## 6. 프로젝트 구성

### 6.1 의존성

| 구분 | 패키지 | 용도 |
|---|---|---|
| 런타임 | `symfony/framework-bundle` `symfony/runtime` `symfony/console` `symfony/dotenv` `symfony/yaml` | Symfony 7.4 최소 구성 (full 스택 아님) |
| | `symfony/validator` `symfony/serializer` `symfony/property-access` | 요청 DTO 검증·직렬화 |
| | `symfony/uid` | UUIDv7 |
| | `symfony/clock` | PSR-20 구현 + 테스트용 `MockClock` |
| | `doctrine/orm` `doctrine/doctrine-bundle` `doctrine/doctrine-migrations-bundle` | 영속화 |
| | `symfony/security-bundle` | 세션 로그인(`json_login`) · 역할 (5-1 단계에서 추가) |
| | `nelmio/api-doc-bundle` | OpenAPI 생성 (5-2 단계에서 추가) |
| 개발 | `phpunit/phpunit` | 속성 기반 메타데이터가 필요하므로 10 이상 |
| | `phpstan/phpstan` `phpstan-symfony` `phpstan-doctrine` `extension-installer` | 정적 분석 |
| | `symfony/browser-kit` `symfony/css-selector` | T3 의 HTTP 흐름 검증 |

정확한 버전은 설치 시점의 안정판으로 고정하고 `composer.lock` 을 커밋한다. **twig·mailer 등 화면 번들은 넣지 않는다** — 이 앱은 API 하나다. security 는 세션 로그인에만 쓴다(§5.2).

### 6.2 composer 스크립트 (루트가 부르는 이름)

루트 `package.json` 이 `composer -d apps/backend run <이름>` 으로 부른다. **이름이 계약이다.**

| 이름 | 내용 |
|---|---|
| `stan` | `phpstan analyse` (level max) |
| `test` | `phpunit` — 전체 |
| `test:fast` | `phpunit --group unit --group collaboration --group structure` — DB 불필요 |
| `test:testdox` | `phpunit --testdox` — 사람이 읽는 명세 출력 |
| `api:spec` | `bin/console nelmio:apidoc:dump --format=json > openapi/openapi.json` (5단계) |

`lefthook.yml` 과 `.github/workflows/ci.yml` 이 이 이름들에 매여 있다. 이름을 바꾸면 세 곳을 함께 고친다.

### 6.3 정적 분석

`phpstan.neon.dist` — `level: max`, 대상은 `src` 와 `tests`. 베이스라인(`phpstan-baseline.neon`)을 만들지 않는다. **0에서 시작하는 저장소에 베이스라인을 두면 첫 커밋부터 예외 목록이 생긴다.**

### 6.4 테스트 배치

```text
apps/backend/tests/
├── Unit/           #[Group('unit')]           T1
├── Collaboration/  #[Group('collaboration')]  T2 — 인메모리 가짜 저장소 · MockClock
├── Integration/    #[Group('integration')]    T3 — 실 DB
├── Architecture/   #[Group('structure')]      T4 — FeatureCoverageTest
└── Support/        # 가짜 구현 · #[Feature] 어트리뷰트 정의 (테스트 아님)
```

- `autoload-dev` PSR-4: `App\Tests\` → `tests/`.
- `#[Feature]` 어트리뷰트는 `tests/Support/Feature.php` 에 정의한다. **테스트 메타데이터이므로 `src` 에 두지 않는다.**
- `Support/` 는 테스트 클래스가 아니므로 T4 의 라벨 검사에서 제외된다(화이트리스트).
- T4 가드는 저장소 루트의 `docs/features/` 를 읽는다. **`apps/backend` 는 모노레포 루트 안에 있음을 전제한다** — 백엔드만 따로 떼어 내면 이 검사가 깨진다.

---

### 6.5 환경 변수

**`.env.dist` 를 두지 않는다.** Symfony 의 Dotenv 는 `.env` 가 있으면 `.env.dist` 를 **아예 읽지 않는다**(둘은 병합이 아니라 택일이다). 그러면 견본에 새 변수를 추가해도 이미 `.env` 를 가진 사람에게는 전달되지 않고, 실패는 런타임의 빈 값으로만 드러난다. 대신 **커밋되는 `.env` 에 기본값을 두고, 덮어쓰기로 실제 값을 얹는다.**

| 파일 | 커밋 | 언제 로드되나 | 무엇을 담나 |
|---|---|---|---|
| `.env` | **예** | 항상 | 비밀 아닌 기본값·자리표시자 |
| `.env.local` | 아니오 | `APP_ENV` 가 `test` 가 **아닐 때** | 이 기기·이 배포 환경의 실제 값 |
| `.env.test` | **예** | `APP_ENV=test` | 테스트 고정값(`KERNEL_CLASS` 등) |
| `.env.test.local` | 아니오 | `APP_ENV=test` | 로컬 테스트 DB 접속 정보 |

로드 순서는 `.env` → `.env.local` → `.env.$APP_ENV` → `.env.$APP_ENV.local` 이고 **뒤가 앞을 덮는다.** 진짜 환경 변수는 이 모두를 이긴다 — CI 와 배포는 그 경로를 쓴다.

- **`.env` 에 실제 접속 정보나 시크릿을 적지 않는다.** 자리표시자만 둔다. 새 변수는 여기에 추가해야 모두에게 전파된다.
- 함정: **`.env.local` 은 test 환경에서 건너뛴다.** 로컬에서 T3 를 돌릴 접속 정보는 `.env.local` 이 아니라 `.env.test.local` 에 넣는다. 이걸 모르면 "앱은 뜨는데 테스트만 DB 를 못 찾는" 상태가 된다.
- 배포는 이 저장소의 범위 밖이지만, 방식은 정해 둔다 — `.env.local` 주입 또는 진짜 환경 변수. 커밋된 `.env` 는 어느 쪽에서도 비밀을 담지 않는다.

---

## 7. 범위 밖

| 없는 것 | 왜 |
|---|---|
| 만료 워커·스케줄러 | 지연 정리로 대신한다(§2.3). 워커를 들이면 배포층이 필요해진다([`BUILD-PLAN.md` §7](../BUILD-PLAN.md)) |
| 서버 푸시 | 폴링으로 먼저 만든다. Mercure 승격은 루트 README §6 의 계획 |
| 비관적 잠금 | 생성 컬럼 유니크가 최종 보증이다. 남는 틈은 §4.4 에 적었다 |
| 캐시·메시지 큐·이벤트 버스 | 기구 수십 대·엔드포인트 열네 개에 필요하지 않다 |
| 회원 가입·비밀번호 변경 | 계정은 시드로 고정(루트 README §5) |
| 다국어 메시지 | `message` 는 한국어 고정. `reason` 이 계약이므로 번역은 프론트 몫 |

---

## 8. 정하지 않은 것

| 항목 | 언제 정하나 | 무엇에 달렸나 |
|---|---|---|
| 생성 컬럼을 엔티티 매핑에서 다루는 법 | 4 단계 | 매핑하지 않으면 `doctrine:migrations:diff` 가 그 컬럼을 지우자고 제안한다. 읽기 전용 매핑(`insertable: false, updatable: false`)으로 둘지, diff 결과를 손으로 정리할지를 실제로 돌려 보고 정한다 |
| 시드 계정을 넣는 방법 | 5-1 단계 | 개발용 콘솔 명령과 데이터 마이그레이션 중 하나. 비밀번호 해시가 필요하므로 콘솔 명령이 유력하다 |

---

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | §1~§5·§7 재작성 — 예약 모델을 걷어내고 실시간 점유·대기열 기준으로. 판정 객체 셋(Tag · Settlement · Extension), 거부 사유 17개(snake_case), 생성 컬럼 + 유니크로 불변식 강제, 세션 로그인, 엔드포인트 열네 개. 엔티티 정의는 `data-model.md` 로 넘김 | 사용자 인터뷰 — 전부 지금 재작성, reason 은 snake_case, 동시성은 생성 컬럼 + 유니크, 판정 객체 하나, QR 진입 시 자동 태깅하되 사용 중이면 종료 버튼 |
