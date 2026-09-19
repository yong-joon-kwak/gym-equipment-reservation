# 백엔드 스펙 — 계층 · 도메인 · 영속화 · HTTP 표면

> `apps/backend` 의 **구조와 규칙 파라미터의 정본**이다. 2단계(도메인 T1) 착수 전에 확정해 둔다.
>
> 되풀이하지 않는 것: 기능이 무엇을 보장하는가 → [`features/`](../features/equipment-reservation/README.md) · 테스트 티어와 라벨 규약 → [`coding/test-as-specification.md`](../coding/test-as-specification.md) · OpenAPI 생성 경로 → [`api-contract.md`](api-contract.md) · 만드는 순서 → [`BUILD-PLAN.md`](../BUILD-PLAN.md).
>
> 스택: **PHP 8.4 · Symfony 7.4(LTS) · Doctrine ORM 3 · MariaDB**

---

## 1. 계층과 의존 방향

```text
apps/backend/src/
├── Domain/                     # 규칙. 프레임워크를 모른다
│   ├── Reservation/            #   Reservation · ReservationStatus · ReservationPolicy · RejectionReason
│   │                           #   ReservationHistory · ReservationRepository(인터페이스)
│   ├── Equipment/              #   Equipment · EquipmentCode · EquipmentRepository(인터페이스)
│   └── Shared/                 #   TimeSlot · MemberId · DomainException
├── Application/                # 유스케이스. 트랜잭션 경계
│   ├── Reservation/            #   HoldReservation · ConfirmReservation · CancelReservation · ListReservations
│   └── Equipment/              #   RegisterEquipment · RenameEquipment · DeactivateEquipment · ListEquipment
├── Infrastructure/             # 어댑터. 여기서만 Doctrine 을 안다
│   └── Doctrine/               #   DoctrineReservationRepository · DoctrineEquipmentRepository · 타입 매핑
└── Ui/Http/                    # 컨트롤러 · 요청/응답 DTO · 예외 → HTTP 변환
```

의존은 **안쪽으로만** 흐른다.

| 계층 | 알아도 되는 것 | 절대 모르는 것 |
|---|---|---|
| `Domain` | PHP 표준 · `Psr\Clock` | Doctrine · Symfony · HTTP · 다른 계층 |
| `Application` | `Domain` (인터페이스로만 밖을 부른다) | Doctrine · HTTP |
| `Infrastructure` | `Domain` · Doctrine | `Ui` |
| `Ui/Http` | `Application` · DTO | `Domain` 엔티티를 응답에 직접 싣지 않는다 |

### 계층마다 제 티어가 있다

계층은 특정 티어를 위해 있는 것이 아니다. **계층마다 그 계층에 맞는 티어가 있다.**

| 계층 | 여기서 묻는 것 | 티어 | 대역 |
|---|---|---|---|
| `Domain` — `ReservationPolicy` · 엔티티 | 판정과 상태 전이가 옳은가 | T1 | 없음 |
| `Application` — 예약 서비스 등 유스케이스 | 도메인과 저장소를 엮은 **흐름의 결과**가 옳은가 | T2 | 경계만 가짜(fake) |
| `Infrastructure` · `Ui/Http` | 실제 DB·HTTP 에서 흐름·제약·동시성이 성립하는가 | T3 | 없음 (실 DB) |
| 전체 | 좌표·라벨·의존 방향이 지켜지는가 | T4 | 없음 |

"목 없이 검증한다" 는 **T1 에 붙은 조건이지 백엔드 전체에 붙은 조건이 아니다.** 서비스는 T2 로 검증하고, 거기서는 인메모리 가짜 저장소와 고정 시계를 쓴다. 금지된 것은 계층도 서비스도 아니고, **T2 가 결과 대신 호출 절차를 단언하는 것** 하나다([`test-as-specification.md` §2](../coding/test-as-specification.md)).

의존 방향은 이 대응을 유지하기 위한 것이다. 규칙 객체가 Doctrine 을 알면 규칙 테스트가 DB 를 끌고 오고, **T1 이 사실상 T3 가 된다** — 티어가 무너지는 첫 지점이 여기다.

> 저장소 인터페이스를 `Domain` 에 두고 구현을 `Infrastructure` 에 두는 이유도 같다. T2 의 인메모리 가짜는 이 인터페이스를 구현한 것이지 목이 아니다.

---

## 2. 도메인 모델

### 2.1 Equipment

| 속성 | 타입 | 비고 |
|---|---|---|
| `id` | `Uuid` (v7) | |
| `code` | `EquipmentCode` | 관리자가 부여하는 식별 코드. 저장소 전체에서 유일 |
| `name` | `string` | |
| `kind` | `EquipmentKind` (enum) | `treadmill` · `bike` · `rower` · `strength` |
| `active` | `bool` | 비활성화는 **soft** — 행을 지우지 않는다 |

비활성화된 기구는 새 점유의 대상이 될 수 없다. **그 판정의 소유자는 이 도메인이다** — 예약 도메인도 프론트도 결과를 받기만 한다([`equipment-catalog`](../features/equipment-catalog/README.md)).

### 2.2 Reservation — 상태와 전이

```text
          hold                confirm
  (없음) ──────▶ HELD ──────────────▶ CONFIRMED
                  │                      │
          cancel  │                      │ cancel (시작 시각 전까지)
                  ▼                      ▼
              CANCELLED ◀────────────────┘
                  ▲
                  │  held_until 경과 → EXPIRED (별도 상태, 되돌아오지 않음)
```

| 상태 | 뜻 | 슬롯을 점유하는가 |
|---|---|---|
| `HELD` | 임시 점유. `held_until` 까지 유효 | 예 |
| `CONFIRMED` | 확정 | 예 |
| `CANCELLED` | 취소됨 | 아니오 |
| `EXPIRED` | 홀드가 만료됨 | 아니오 |

- **확정은 `HELD` 에서만 가능하다.** 만료된 홀드는 확정되지 않는다.
- **모든 전이는 `ReservationHistory` 한 줄을 남긴다.** 생성(`null → HELD`)도 포함한다.
- 남의 예약은 취소·확정할 수 없다. 소유자 판정은 도메인이 한다(`MemberId` 비교).

### 2.3 값 객체

| 값 객체 | 불변식 |
|---|---|
| `TimeSlot` | `startsAt` 은 정시, 길이는 고정(§2.4). `endsAt` 은 파생값이며 저장은 하되 입력으로 받지 않는다 |
| `MemberId` | 빈 문자열 금지, 64자 이하 |
| `EquipmentCode` | `^[A-Z0-9-]{3,32}$`, 대문자 정규화 |

전부 `final readonly class` 로 만든다. 생성자에서 불변식을 검사하고, 위반은 `DomainException` 의 하위 타입으로 던진다 — **유효하지 않은 값 객체는 존재할 수 없다.**

### 2.4 규칙 파라미터

| 값 | 기본값 | 근거 |
|---|---|---|
| 슬롯 길이 | **60분** | 그리드를 고정 길이로 두면 겹침 판정이 시작 시각 비교 하나로 끝난다 |
| 슬롯 시작 | **정시 (`:00`)** | 위와 같음. 프론트가 UI 제약으로 반영할 수 있는 형태([`api-contract.md`](api-contract.md)) |
| 홀드 유효 시간 | **10분** | 확정까지의 여유. 길수록 빈 슬롯이 오래 잠긴다 |
| 회원 활성 한도 | **3건** | `HELD` + `CONFIRMED` 합산 |
| 예약 가능 창 | **지금 ~ 14일** | 과거 거부의 반대쪽 경계. 없으면 2027년 슬롯이 점유된다 |
| 취소 가능 시점 | **시작 시각 전까지** | 시작한 예약은 기록으로 남긴다 |

**상수로 박지 않고 `ReservationPolicy` 생성자 인자로 받는다.** 값은 `config/services.yaml` 에서 주입한다. T1 테스트가 값을 바꿔 가며 경계를 검증할 수 있어야 하고, 하드코딩된 상수는 그 길을 막는다.

### 2.5 정책은 저장소를 모른다 — 사실은 인자로 받는다

`ReservationPolicy` 는 **인자로 받은 것만 안다.** 저장소·`EntityManager`·컨테이너를 주입받지 않는다.

```php
final readonly class ReservationPolicy
{
    // 파라미터(§2.4)와 시계만 생성자로 받는다
    public function __construct(private ClockInterface $clock, private int $slotMinutes, ...) {}

    public function judge(
        TimeSlot $slot,
        Equipment $equipment,      // 활성 여부
        MemberReservations $mine,  // 이 회원의 활성 예약들 — 한도·본인 겹침
        bool $slotTaken,           // 그 기구·슬롯이 이미 점유 중인가
    ): ?RejectionReason;           // null 이면 통과
}
```

**판정에 필요한 사실을 모으는 것은 응용 서비스의 책임이다.** 서비스가 저장소에서 읽어 정책에 넘기고, 정책은 받은 값으로만 판정한다.

판정 순서는 고정한다 — 여러 규칙을 동시에 어겨도 **결과가 하나로 정해져야 테스트가 결정적이다.**

```text
past_slot → off_grid → outside_booking_window → equipment_inactive
          → member_limit_exceeded → member_overlap → equipment_taken
```

- 정책은 사유를 **반환**하고, 예외로 바꾸는 것은 응용 서비스다(`ReservationRejected`). 도메인이 HTTP 를 모르는 것과 같은 이유로, 판정이 제어 흐름을 결정하지 않는다.
- 대가: **정책의 인자가 늘고, 서비스가 "무엇을 조회해야 하는지" 를 안다.** 규칙이 바뀌면 조회하는 쪽도 함께 바뀐다. 그 결합을 정책 안으로 숨기면 T1 이 저장소를 끌고 오므로, 밖에 드러난 채로 둔다.

### 2.6 만료 — 스케줄러 없이 처리한다

만료 배치 스케줄러는 범위 밖이다([`equipment-reservation`](../features/equipment-reservation/README.md)). 대신 **접근 시점에 정리한다(lazy sweep).**

- 확정 요청: `held_until` 이 지났으면 `EXPIRED` 로 전이시키고 `hold_expired` 로 거부한다.
- 같은 슬롯에 새 점유 요청이 오면: 그 슬롯의 만료된 홀드를 **같은 트랜잭션 안에서** 먼저 해제하고 진행한다.
- 조회: 만료된 `HELD` 는 활성으로 세지 않는다.

대가는 정직하게 적는다 — **아무도 건드리지 않은 만료 홀드는 DB 에 `HELD` 인 채로 남는다.** 상태는 읽는 쪽에서 판정되므로 보장은 깨지지 않지만, 행만 보고 현황을 읽으면 어긋난다. 스케줄러를 들이는 순간 배포층(워커)이 필요해지고, 그것은 이 저장소의 범위 밖이다([`BUILD-PLAN.md` §7](../BUILD-PLAN.md)).

---

## 3. 거부 사유(`reason`) — 계약의 정본

판정의 소유자가 백엔드이므로, **거부 사유 목록의 정본도 여기다.** 프론트는 이 문자열을 그대로 노출하고 자체 판정을 두지 않는다([`api-contract.md`](api-contract.md)).

| `reason` | HTTP | 언제 |
|---|---|---|
| `past_slot` | 422 | 지난 시각 |
| `off_grid` | 422 | 정시가 아니거나 길이가 다름 |
| `outside_booking_window` | 422 | 예약 가능 창 밖 |
| `member_limit_exceeded` | 422 | 회원 활성 한도 초과 |
| `member_overlap` | 409 | 본인이 같은 시간에 다른 예약을 가짐 |
| `equipment_taken` | 409 | 그 기구·시간이 이미 점유됨 |
| `equipment_inactive` | 422 | 비활성 기구 |
| `equipment_not_found` | 404 | |
| `duplicate_equipment_code` | 409 | 같은 코드의 기구가 이미 있음 |
| `reservation_not_found` | 404 | |
| `hold_expired` | 422 | 만료된 홀드를 확정하려 함 |
| `invalid_transition` | 422 | 허용되지 않는 상태 전이(예: 취소된 예약의 확정) |
| `not_owner` | 403 | 남의 예약을 취소·확정하려 함 |
| `admin_only` | 403 | 관리자 전용 조작 |

- `409` 는 **다른 데이터와 부딪혀서** 거부된 것, `422` 는 **요청 자체가 규칙을 어긴** 것으로 나눈다.
- 사유는 `RejectionReason` **enum(string)** 하나로 두고, 값이 곧 응답 문자열이다. 새 사유는 enum 에 추가되므로 문자열 오타가 타입 검사에서 걸린다.
- 에러 응답 본문은 §5.3.

---

## 4. 영속화 (Doctrine ORM 3 · MariaDB)

매핑은 **어트리뷰트**로 한다(XML·YAML 매핑을 쓰지 않는다). 엔티티는 `Domain` 에 두되, 어트리뷰트만 붙이고 Doctrine 의 기능(라이프사이클 콜백·프록시 의존 코드)은 쓰지 않는다.

### 4.1 테이블

```text
equipment
  id                BINARY(16)  PK          -- UUIDv7
  code              VARCHAR(32) NOT NULL    UNIQUE (uniq_equipment_code)
  name              VARCHAR(120) NOT NULL
  kind              VARCHAR(20)  NOT NULL
  active            TINYINT(1)   NOT NULL
  created_at        DATETIME     NOT NULL
  updated_at        DATETIME     NOT NULL

reservation
  id                BINARY(16)  PK
  equipment_id      BINARY(16)  NOT NULL    FK → equipment.id (ON DELETE RESTRICT)
  member_id         VARCHAR(64) NOT NULL
  starts_at         DATETIME    NOT NULL
  ends_at           DATETIME    NOT NULL
  status            VARCHAR(16) NOT NULL    -- HELD · CONFIRMED · CANCELLED · EXPIRED
  held_until        DATETIME    NULL        -- HELD 일 때만 값이 있다
  active_slot       DATETIME    NULL        -- §4.2
  created_at        DATETIME    NOT NULL
  updated_at        DATETIME    NOT NULL
  UNIQUE (equipment_id, active_slot)        -- uniq_equipment_active_slot
  UNIQUE (member_id,    active_slot)        -- uniq_member_active_slot
  INDEX  (equipment_id, starts_at)          -- 가용 조회
  INDEX  (member_id,    starts_at)          -- 내 예약 조회

reservation_history
  id                BIGINT AUTO_INCREMENT PK
  reservation_id    BINARY(16)  NOT NULL    FK → reservation.id (ON DELETE CASCADE)
  from_status       VARCHAR(16) NULL        -- 생성이면 NULL
  to_status         VARCHAR(16) NOT NULL
  actor_member_id   VARCHAR(64) NOT NULL
  reason            VARCHAR(40) NULL
  occurred_at       DATETIME    NOT NULL
  INDEX (reservation_id, occurred_at)
```

엔진 InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.

### 4.2 `active_slot` — 동시성의 최종 보증

MariaDB 에는 **부분 유니크 인덱스(`WHERE status IN (...)`)가 없다.** 그래서 널 허용 컬럼 하나로 같은 효과를 낸다.

- `HELD` · `CONFIRMED` 인 동안 `active_slot = starts_at`
- `CANCELLED` · `EXPIRED` 가 되는 순간 `active_slot = NULL`

유니크 인덱스는 **NULL 을 여럿 허용**하므로, 취소·만료된 예약은 몇 개든 같은 슬롯에 남을 수 있고 활성인 것만 하나로 제한된다. 이것이 "같은 활성 슬롯의 중복 저장은 DB 유니크 제약이 막는다" 의 구현이다.

`active_slot` 은 **엔티티가 상태 전이와 함께 갱신하는 파생 컬럼이다.** 상태와 따로 손대지 않는다 — 어긋나면 제약이 조용히 무력해진다. 이 불변식(활성 ⟺ `active_slot` 이 `starts_at` 과 같다)은 T3 가 지킨다.

> `uniq_member_active_slot` 은 기능 문서가 요구한 최소 보장보다 한 겹 더 조인 것이다. 본인 시간 겹침 거부는 T1 이 판정하지만, 동시 요청에서는 판정이 통과한 뒤 저장이 부딪힐 수 있다. 기구 쪽과 같은 방식으로 막는다.

### 4.3 트랜잭션과 동시성

- **유스케이스 하나 = 트랜잭션 하나.** 경계는 `Application` 에 둔다. 컨트롤러도 저장소도 트랜잭션을 열지 않는다.
- 낙관적 전제로 진행하고, `UniqueConstraintViolationException` 을 잡아 해당 `reason`(`equipment_taken` · `member_overlap` · `duplicate_equipment_code`)으로 바꾼다. **잠금(`SELECT … FOR UPDATE`)을 먼저 쓰지 않는다** — 경합이 드문 예약 도메인에서 잠금은 비용만 크고, 유니크 제약이 이미 최종 보증이다.
- 규칙 판정(겹침·한도)은 **먼저 도메인이 한다.** 제약은 그 판정을 통과한 동시 요청 둘이 부딪혔을 때의 마지막 그물이다. 순서를 뒤집어 제약에 판정을 맡기지 않는다 — 그러면 거부 사유를 예외 메시지에서 되짚어야 한다.

### 4.4 마이그레이션

`doctrine/doctrine-migrations-bundle` 을 쓴다. **스키마 도구(`doctrine:schema:update`)로 운영 스키마를 만들지 않는다.**

AGENTS.md §5 의 정지선이 여기 걸린다 — **AI 는 마이그레이션 파일 생성과 검토까지만 하고, 실제 적용(`doctrine:migrations:migrate`)은 사람이 한다.**

### 4.5 시간대

- 저장·판정은 **UTC**. `DateTimeImmutable` 만 쓰고 가변 `DateTime` 은 도메인에 들이지 않는다.
- 표시는 프론트가 KST 로 한다. 슬롯 경계는 정시이고 KST 오프셋이 `+09:00` 정각이라 그리드 판정은 두 시간대에서 같다.
- 현재 시각은 **`Psr\Clock\ClockInterface`** 로만 얻는다. `new DateTimeImmutable()` 을 도메인·응용에서 직접 부르지 않는다. T1·T2 는 `symfony/clock` 의 `MockClock` 으로 시간을 고정한다 — 이것이 "과거 시각 거부" 와 "홀드 만료" 를 결정적으로 시험할 수 있게 하는 유일한 장치다.

---

## 5. HTTP 표면

### 5.1 엔드포인트

| 메서드 · 경로 | 하는 일 | 권한 |
|---|---|---|
| `GET /api/equipment` | 기구 목록. 일반 회원은 활성만, 관리자는 비활성 포함 | 회원 |
| `POST /api/equipment` | 기구 등록 | 관리자 |
| `PATCH /api/equipment/{id}` | 이름·종류 수정 | 관리자 |
| `POST /api/equipment/{id}/deactivate` | 비활성화(soft) | 관리자 |
| `GET /api/equipment/{id}/availability?date=YYYY-MM-DD` | 그 날짜의 슬롯별 점유 여부 | 회원 |
| `POST /api/reservations` | 점유(HELD) 생성 | 회원 |
| `POST /api/reservations/{id}/confirm` | 확정 | 소유자 |
| `POST /api/reservations/{id}/cancel` | 취소 | 소유자 |
| `GET /api/reservations` | 내 예약 목록 | 회원 |

### 5.2 인증 — 범위 밖, 헤더로 단순화

| 헤더 | 값 | 없을 때 |
|---|---|---|
| `X-Member-Id` | 회원 식별자 | `401` |
| `X-Member-Role` | `member`(기본) · `admin` | `member` 로 간주 |

**실서비스 인증은 이 저장소의 범위 밖이다.** 두 헤더를 읽어 `Ui/Http` 에서 `MemberId` 와 역할로 바꾸고, 그 아래 계층은 헤더의 존재를 모른다. 나중에 진짜 인증이 들어와도 바뀌는 곳은 이 한 겹이다.

### 5.3 응답 형태

성공은 자원 표현을 그대로 준다. 실패는 **한 가지 형태만** 쓴다.

```json
{ "error": "EquipmentTaken", "reason": "equipment_taken", "message": "이미 점유된 시간입니다" }
```

- `error` 는 예외 클래스의 짧은 이름, `reason` 은 §3 의 enum 값, `message` 는 사람이 읽는 한국어 문장이다.
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
| | `nelmio/api-doc-bundle` | OpenAPI 생성 (5단계에서 추가) |
| 개발 | `phpunit/phpunit` | 속성 기반 메타데이터가 필요하므로 10 이상 |
| | `phpstan/phpstan` `phpstan-symfony` `phpstan-doctrine` `extension-installer` | 정적 분석 |
| | `symfony/browser-kit` `symfony/css-selector` | T3 의 HTTP 흐름 검증 |

정확한 버전은 설치 시점의 안정판으로 고정하고 `composer.lock` 을 커밋한다. **twig·security·mailer 등 화면/인증 번들은 넣지 않는다** — 이 앱은 API 하나다.

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
| 인증·인가 체계 | 헤더 두 개로 대체(§5.2). 데모의 초점이 아니다 |
| 만료 스케줄러·워커 | 배포층이 필요해진다([`BUILD-PLAN.md` §7](../BUILD-PLAN.md)) |
| 캐시·큐·이벤트 버스 | 기구 두 종·엔드포인트 아홉 개에 필요하지 않다 |
| 다국어 메시지 | `message` 는 한국어 고정. `reason` 이 계약이므로 번역은 프론트 몫 |
| 소프트 삭제 일반화 | 기구 비활성화 하나뿐. 공통 장치로 추상화하지 않는다 |
