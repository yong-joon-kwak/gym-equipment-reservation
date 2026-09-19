# 백엔드 아키텍처 — 계층 · 판정 객체 · 동시성 · 인증

> `apps/backend` 의 **아키텍처 정본**이다. 코드가 어느 계층에 놓이는지, 규칙을 어떤 객체가 판정하는지, 동시 요청을 어떻게 다루는지, 로그인을 어떻게 처리하는지를 정한다.
>
> 되풀이하지 않는 것: 제품 규칙과 값 → 루트 [`README.md`](../../README.md) §3 · 용어와 코드 이름 → [`domain-glossary.md`](../business/domain-glossary.md) · 엔티티·관계·상태 → [`data-model.md`](data-model.md) · 물리 매핑·DB 제약·마이그레이션 → [`persistence.md`](persistence.md) · 엔드포인트·응답 형태·거부 사유 → [`api-contract.md`](api-contract.md) · 업무 흐름과 판정 순서 → [`system-overview.md`](system-overview.md) · 테스트 티어·라벨·배치 → [`coding/test-as-specification.md`](../coding/test-as-specification.md) · 의존성·스크립트·환경 변수 → [`apps/backend/README.md`](../../apps/backend/README.md) · 만드는 순서 → [`BUILD-PLAN.md`](../BUILD-PLAN.md)
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
│   ├── Doctrine/               #   리포지토리 구현(ServiceEntityRepository 상속) · 타입 매핑
│   └── Security/               #   SecurityUser(Member 를 감싼다) · 사용자 공급자
└── Ui/Http/                    # 컨트롤러 · 요청/응답 DTO · 예외 → HTTP 변환
```

의존은 **안쪽으로만** 흐른다.

| 계층 | 알아도 되는 것 | 절대 모르는 것 |
|---|---|---|
| `Domain` | PHP 표준 · `Psr\Clock` · `Doctrine\ORM\Mapping` 어트리뷰트(메타데이터만) | Doctrine 의 동작(`EntityManager` · 리포지토리 기반 클래스 · 라이프사이클 콜백) · Symfony · HTTP · 다른 계층 |
| `Application` | `Domain` (인터페이스로만 밖을 부른다) | Doctrine · HTTP |
| `Infrastructure` | `Domain` · Doctrine · Symfony Security | `Ui` |
| `Ui/Http` | `Application` · DTO | `Domain` 엔티티를 응답에 직접 싣지 않는다 |

엔티티는 `Domain` 에 두고 Doctrine 매핑 어트리뷰트만 붙인다([`persistence.md`](persistence.md) §1). 어트리뷰트는 메타데이터일 뿐이라 도메인이 Doctrine 의 동작을 알게 되지는 않는다. `Domain` 에 허용되는 Doctrine 의존은 **`Doctrine\ORM\Mapping` 네임스페이스 하나뿐**이다.

### 엔티티와 리포지토리가 놓이는 자리

`Domain` 은 계층의 이름이지 객체의 한 종류가 아니다. 엔티티는 `Domain` 에 있는 여러 종류 중 하나다. 영속되는가는 저장하는 쪽의 사정이라, 도메인 개념을 나누는 기준이 아니다.

| 종류 | 무엇 | 예 | 자리 |
|---|---|---|---|
| 엔티티 | 식별자와 수명이 있고 상태가 바뀐다 | `UsageSession` · `QueueEntry` | `Domain/<개념>/` |
| 값 객체 | 식별자 없이 값으로만 같다 | `EquipmentCode` · `QueueRules` · `TagDecision` | `Domain/<개념>/` · `Domain/Shared/` |
| 판정 객체 | 규칙을 판정한다(§2) | `TagPolicy` · `SettlementPolicy` | `Domain/<개념>/` |
| 리포지토리 **인터페이스** | 도메인 언어로 적은 저장·조회 약속 | `UsageSessionRepository::findActiveByMember()` | `Domain/<개념>/` |
| 리포지토리 **구현** | Doctrine 으로 그 약속을 지킨다 | `DoctrineUsageSessionRepository` | `Infrastructure/Doctrine/` |
| T2 가짜 | 같은 인터페이스를 메모리로 지킨다 | `InMemoryUsageSessionRepository` | `tests/Support/` |

**Symfony 기본 배치(`src/Entity` · `src/Repository`)를 쓰지 않는다.** 기본 배치는 종류별로 묶는데, 여기서는 개념별로 묶는다.

- **함께 바뀌는 것이 한 폴더에 있다.** `UsageSession` · `EndReason` · `UsageSessionRepository` 는 규칙이 바뀔 때 같이 바뀐다. 종류별로 두면 한 번의 변경이 세 폴더에 흩어진다.
- **기본 배치의 리포지토리는 Doctrine 구현 그 자체다.** `ServiceEntityRepository` 를 상속하므로, 통째로 `Domain` 에 들이면 도메인이 Doctrine 의 동작에 의존한다. 그래서 리포지토리는 **인터페이스(`Domain`)와 구현(`Infrastructure`)으로 쪼갠다.**
- 인터페이스가 `Domain` 에 있어야 T2 가 그것을 인메모리로 구현할 수 있다(아래 "계층과 티어").

대가: Symfony 기본값 몇 곳을 바꿔야 하고, `make:entity` 의 도움을 덜 받는다([`persistence.md`](persistence.md) §1).

### 계층과 티어

계층마다 검증하는 티어가 정해져 있다 — `Domain` 은 T1, `Application` 은 T2, `Infrastructure` · `Ui/Http` 는 T3. 대응표와 각 티어의 대역 규칙은 [`test-as-specification.md` §2](../coding/test-as-specification.md) 가 정본이다.

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

판정 순서의 정본은 [`system-overview.md` §2.1](system-overview.md) 의 흐름도다. `decide()` 는 **그 순서를 그대로 따르고, 여기에 따로 적지 않는다.** 여러 조건이 한꺼번에 맞아도 결과가 하나로 정해져야 T1 이 결정적이다. 순서를 바꾸면 흐름도를 먼저 고친다.

| `TagDecision` | 뜻 |
|---|---|
| `StartSession` | 사용 시작. 회원이 다른 기구를 쓰고 있었다면 그 세션을 전환 종료한다는 표시를 함께 담는다 |
| `Enqueue` | 대기 등록. 첫 대기라면 사용 중 세션의 만료 시각을 정해야 한다는 표시를 함께 담는다 |
| `Unchanged` | 변화 없음. 이미 그 기구를 쓰는 중(→ 화면에 종료 버튼)이거나 기다리는 중(→ 순번) |
| `Rejected` | 거부. `RejectionReason` 하나를 담는다(값 목록은 [`api-contract.md`](api-contract.md) §3) |

- **사용 중에 다시 태깅해도 본인 종료가 되지 않는다.** QR 페이지를 새로고침할 때마다 태깅이 다시 오기 때문이다. 본인 종료는 화면의 종료 버튼(`EndSession`)으로만 한다.
- 관리자는 `TagPolicy` 에 오지 않는다. 관리자의 기구 상세는 판정 없이 관리용 표현을 돌려준다([`api-contract.md`](api-contract.md) §1).
- 정책은 사유를 **반환**하고, 예외로 바꾸는 것은 응용 서비스다. 도메인이 HTTP 를 모르는 것과 같은 이유로, 판정이 제어 흐름을 결정하지 않는다.

### 2.3 `SettlementPolicy` — 지연 정리

워커가 없으므로, 시간이 흘러 생긴 일(만료·노쇼·차례 호출)은 **다음 태깅 때 기록한다**([`system-overview.md` §2.2](system-overview.md)). 그 계산을 이 객체 하나가 맡는다.

- **받는 것**: 태깅한 기구 E 와 태깅한 회원 M 에 걸린 진행 중인 사용 세션과 대기(`dbstatus = 'A'`), 지금 시각.
- **하는 일**: `expires_at` 이 지난 세션을 `EXPIRED` 로 끝내고, 대기열을 앞에서부터 따라가며 호출 시각을 계산해 `CALLED` 와 `NO_SHOW` 를 확정한다. 앞 대기가 노쇼면 다음 대기의 호출 시각은 앞 대기의 `called_at + 노쇼 유예` 다.
- **돌려주는 것**: 적용할 변경 목록. 적용과 저장은 응용 서비스가 한다.

**조회도 같은 객체를 쓴다.** `GetEquipmentStatus` · `GetEquipmentDetail` 은 `SettlementPolicy` 를 돌려 결과를 화면에 쓰되, **저장하지 않는다.** "조회는 상태를 바꾸지 않으면서도 기록과 같은 답을 낸다" 는 약속이, 같은 함수를 쓰기 때문에 지켜진다.

같은 입력과 같은 시각이면 결과가 같다(멱등). 동시에 들어온 태깅 두 개가 같은 세션을 정리해도 같은 값을 쓴다(§3).

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

### 2.6 만료 시각은 엔티티 메서드로만 바꾼다

`expires_at` 은 여러 유스케이스(태깅·대기 취소·연장·종료)가 건드리므로, **바꾸는 방법을 `UsageSession` 의 메서드로 한정한다.** 응용 서비스가 필드를 직접 쓰지 않는다. 사건마다 어느 메서드가 무엇을 바꾸는지는 [`data-model.md` §5](data-model.md) 가 정본이다.

---

## 3. 트랜잭션과 동시성

- **유스케이스 하나 = 트랜잭션 하나.** 경계는 `Application` 에 둔다. 컨트롤러도 저장소도 트랜잭션을 열지 않는다. 지연 정리·판정·적용이 한 트랜잭션 안에서 일어난다.
- **낙관적으로 진행하고, 잠금(`SELECT … FOR UPDATE`)을 쓰지 않는다.** 판정은 먼저 도메인이 하고, 생성 컬럼 유니크([`persistence.md`](persistence.md) §3)는 판정을 함께 통과한 동시 요청 둘이 부딪혔을 때의 마지막 그물이다.
- `UniqueConstraintViolationException` 은 제약 이름으로 사유([`api-contract.md`](api-contract.md) §3)를 고른다.

  | 제약 | `reason` |
  |---|---|
  | `uniq_usage_session_active_equipment` | `equipment_taken` |
  | `uniq_usage_session_active_member` · `uniq_queue_entry_active_member` | `concurrent_request` |
  | `equipment.code` 유일 | `duplicate_equipment_code` |

- 지연 정리는 멱등이라, 두 트랜잭션이 같은 만료 세션을 동시에 정리해도 같은 값을 쓴다.
- **알려진 틈**: 대기 등록과 본인 종료가 같은 순간에 겹치면, 종료 쪽이 새 대기자를 보지 못해 `requeue_blocked_until` 을 비워 둘 수 있다. 결과는 재대기 제한 한 번이 빠지는 것이고, 주인이 둘이 되는 일은 없다. 이 틈을 막으려면 잠금이 필요한데, 데모 규모에서 그 비용을 치르지 않는다.

---

## 4. 시간대

- 저장·판정·표시 모두 **서울 시간(`Asia/Seoul`)** 이다. 근거는 [`data-model.md`](data-model.md) §5·§7. 물리 타입은 [`persistence.md`](persistence.md) §2. 앱(PHP `date.timezone`)과 DB 세션 시간대(`time_zone = '+09:00'`)를 같은 값으로 맞춘다.
- `DateTimeImmutable` 만 쓰고 가변 `DateTime` 은 도메인에 들이지 않는다.
- 현재 시각은 **`Psr\Clock\ClockInterface`** 로만 얻는다. `new DateTimeImmutable()` 을 도메인·응용에서 직접 부르지 않는다. T1 은 시각을 인자로 넘기고, T2 는 `symfony/clock` 의 `MockClock` 으로 시간을 고정한다 — 노쇼 유예·마감 임박·재대기 제한을 결정적으로 시험할 수 있게 하는 유일한 장치다.

---

## 5. 인증 — 세션 로그인

- `symfony/security-bundle` 의 **`json_login`** 으로 `/api/login` 을 처리하고, 로그인 상태는 **세션 쿠키**로 유지한다(`HttpOnly` · `SameSite=Lax`, 운영에서는 `Secure`).
- 사용자 공급자는 `Infrastructure/Security` 에 둔다. `Member` 를 `login_id` 로 읽어 `SecurityUser` 로 감싼다. **`Domain` 의 `Member` 가 Symfony 의 `UserInterface` 를 구현하지 않는다** — 도메인이 프레임워크를 모르게 하려는 것이다.
- 회원 유형은 역할로 바꾼다 — `MEMBER` → `ROLE_MEMBER`, `ADMIN` → `ROLE_ADMIN`. `access_control` 로 `/api/admin` 은 `ROLE_ADMIN`, 회원 조작은 `ROLE_MEMBER` 로 막는다. 막힌 요청은 `admin_only` · `member_only` 로 응답한다([`api-contract.md`](api-contract.md) §3).
- 비밀번호는 Symfony PasswordHasher(`auto`)로 해시한다.
- **CSRF 토큰을 두지 않는다.** 쿠키가 `SameSite=Lax` 이고 모든 변경 요청이 `Content-Type: application/json` 을 요구하므로, 다른 사이트의 폼 전송으로는 요청을 만들 수 없다.
- 로그인하지 않은 요청은 `401` + `unauthenticated` 로 응답한다([`api-contract.md`](api-contract.md) §2). 기본 로그인 페이지로 돌려보내지 않는다.

---

## 6. 범위 밖

| 없는 것 | 왜 |
|---|---|
| 만료 워커·스케줄러 | 지연 정리로 대신한다(§2.3). 워커를 들이면 배포층이 필요해진다([`BUILD-PLAN.md` §7](../BUILD-PLAN.md)) |
| 서버 푸시 | 폴링으로 먼저 만든다. Mercure 승격은 루트 README §6 의 계획 |
| 비관적 잠금 | 생성 컬럼 유니크가 최종 보증이다. 남는 틈은 §3 에 적었다 |
| 캐시·메시지 큐·이벤트 버스 | 기구 수십 대·엔드포인트 열네 개에 필요하지 않다 |
| 회원 가입·비밀번호 변경 | 계정은 시드로 고정(루트 README §5) |
| 다국어 메시지 | `message` 는 한국어 고정. `reason` 이 계약이므로 번역은 프론트 몫 |

---

## 7. 정하지 않은 것

| 항목 | 언제 정하나 | 무엇에 달렸나 |
|---|---|---|
| 의존 방향 가드 | 2-3 단계(T4 가드) | [`test-as-specification.md`](../coding/test-as-specification.md) §2 의 계층별 티어 표는 T4 가 의존 방향을 검사한다고 적지만, [`test-as-specification.md`](../coding/test-as-specification.md) 의 T4 는 아직 `FeatureCoverageTest` 뿐이다. `Domain` 이 `Doctrine\ORM\Mapping` 밖의 Doctrine·Symfony 를 쓰지 않는지 검사할 방법(리플렉션 테스트 또는 도구)을 정한다 |
| 시드 계정을 넣는 방법 | 5-1 단계 | 개발용 콘솔 명령과 데이터 마이그레이션 중 하나. 비밀번호 해시가 필요하므로 콘솔 명령이 유력하다 |

---

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | 문서 분리 — 거부 사유·엔드포인트·응답 형태(옛 §3·§5.1·§5.3)는 `api-contract.md` §1~§3 으로, 물리 매핑·생성 컬럼·마이그레이션(옛 §4.1~§4.3·§4.6)과 생성 컬럼 매핑 미결 항목은 `persistence.md` 로, 의존성·스크립트·정적 분석·환경 변수(옛 §6.1~§6.3·§6.5)는 `apps/backend/README.md` 로, 계층별 티어 표와 테스트 배치(옛 §6.4)는 `test-as-specification.md` §2 로, 만료 시각 메서드 표(옛 §2.6)는 `data-model.md` §5 로 옮김. 판정 순서는 `system-overview.md` §2.1 만 소유. 절 번호를 §1~§7 로 다시 매김 | 사용자 결정 — 한 문서가 계약·물리 DB·개발 환경까지 담아, 읽는 사람과 승인하는 사람(AGENTS.md §3)이 다른 내용이 섞여 있었다. 같은 사실이 두 벌(티어 표·판정 순서)인 곳도 정리한다 |
| 2026-09-19 | §1 에 "엔티티와 리포지토리가 놓이는 자리" 추가 — `Domain` 은 계층이고 엔티티는 그 안의 한 종류, 개념별 배치, 리포지토리는 인터페이스(`Domain`)·구현(`Infrastructure`)으로 분리. `Domain` 에 허용되는 Doctrine 의존을 `Doctrine\ORM\Mapping` 하나로 명시. 옛 §4.1 에 Symfony 기본값과 다른 설정, 옛 §8 에 의존 방향 가드 추가 | 사용자 논의 — 엔티티를 영속성 기준으로 도메인과 나누면 판정 객체가 바깥 계층에 의존하게 된다. Symfony 기본 `src/Repository` 는 Doctrine 구현이라 도메인에 그대로 들일 수 없다 |
| 2026-09-19 | §1~§5·§7 재작성 — 예약 모델을 걷어내고 실시간 점유·대기열 기준으로. 판정 객체 셋(Tag · Settlement · Extension), 거부 사유 17개(snake_case), 생성 컬럼 + 유니크로 불변식 강제, 세션 로그인, 엔드포인트 열네 개. 엔티티 정의는 `data-model.md` 로 넘김 | 사용자 인터뷰 — 전부 지금 재작성, reason 은 snake_case, 동시성은 생성 컬럼 + 유니크, 판정 객체 하나, QR 진입 시 자동 태깅하되 사용 중이면 종료 버튼 |
