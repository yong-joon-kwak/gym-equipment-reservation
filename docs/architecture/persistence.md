# 영속화 — 물리 매핑 · 불변식의 DB 강제 · 마이그레이션

> [`data-model.md`](data-model.md) 의 논리 모델을 **MariaDB 에 어떻게 싣는가** 를 정한다. 매핑 규칙, 논리 타입의 물리 타입, 데이터 모델의 불변식을 DB 가 강제하는 방법, 마이그레이션 절차다.
>
> 되풀이하지 않는 것: 엔티티·관계·상태·불변식 목록 → [`data-model.md`](data-model.md) · 엔티티가 놓이는 계층 → [`backend.md`](backend.md) §1 · 트랜잭션 경계와 제약 위반의 처리 → [`backend.md`](backend.md) §3 · 제약 위반이 되는 거부 사유 → [`api-contract.md`](api-contract.md) §3
>
> 스택: **Doctrine ORM 3 · MariaDB 12.3**. 스키마를 바꾸는 일은 사람이 결정한다(AGENTS.md §3).

---

## 1. 매핑 원칙

- 매핑은 **어트리뷰트**로 한다(XML·YAML 매핑을 쓰지 않는다). 엔티티는 `Domain` 에 두되, 라이프사이클 콜백·프록시 의존 코드는 쓰지 않는다. `Domain` 에 허용되는 Doctrine 의존은 `Doctrine\ORM\Mapping` 하나다([`backend.md`](backend.md) §1).
- 테이블 이름은 엔티티의 snake_case 단수형이다 — `member` · `equipment` · `usage_session` · `queue_entry`.
- PK 컬럼 이름은 `<엔티티>_id` 다([`data-model.md` §3](data-model.md)).
- 엔진 InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.

**Symfony 기본값과 다르게 두는 곳** — 엔티티를 `Domain` 에 두기 때문이다([`backend.md`](backend.md) §1).

| 무엇 | 기본값 | 여기서 |
|---|---|---|
| `config/packages/doctrine.yaml` 매핑 | `dir: src/Entity` · `prefix: App\Entity` | `dir: src/Domain` · `prefix: App\Domain`. 어트리뷰트 드라이버는 `#[ORM\Entity]` 가 붙은 클래스만 매핑하므로 값 객체·판정 객체가 같은 폴더에 있어도 된다 |
| `config/services.yaml` 의 `App\:` 제외 목록 | `src/Entity` 를 서비스 등록에서 뺀다 | 엔티티·값 객체를 서비스로 등록하지 않게 `Domain` 쪽 제외를 둔다. 판정 객체는 `QueueRules` 를 주입받아야 하므로 서비스로 남긴다([`backend.md`](backend.md) §2.4) |
| `make:entity` | `App\Entity` 에 엔티티, `src/Repository` 에 리포지토리 생성 | 전체 클래스명을 넘기고, 생성된 리포지토리는 인터페이스(`Domain`)와 구현(`Infrastructure/Doctrine`)으로 나눈다 |

---

## 2. 논리 타입 → 물리 타입

| 논리 타입([`data-model.md`](data-model.md)) | MariaDB | 비고 |
|---|---|---|
| `uuid` | `BINARY(16)` | `symfony/uid` 의 Doctrine 타입. UUIDv7 |
| `datetime` | `DATETIME` | 서울 시간 그대로([`backend.md`](backend.md) §4). 초 단위 |
| `string` (enum) | `VARCHAR(20)` | 상태·종료 사유·취소 사유·회원 유형 |
| `string` | `VARCHAR(n)` | 길이는 엔티티 매핑에서 정한다 |
| `int` | `INT` | |
| `boolean` | `TINYINT(1)` | |
| `dbstatus` | `CHAR(1) NOT NULL DEFAULT 'A'` | `'A'` · `'D'` |

유일 제약: `equipment.code`, `member.login_id`. **삭제된 행도 포함한다**([`data-model.md` 가정](data-model.md)).

---

## 3. 불변식을 DB 로 — 생성 컬럼 + 유니크

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
- "기록상 진행 중" 을 센다. 그래서 판정 전에 지연 정리가 먼저 돈다([`backend.md`](backend.md) §2.5) — 이미 만료된 세션이 새 세션을 막지 않게.
- 생성 컬럼은 도메인이 쓰지 않는다. 정의는 마이그레이션에 두고, 엔티티 매핑에서 어떻게 다룰지는 §5.
- 제약 이름은 제약 위반을 거부 사유로 바꾸는 데 쓰인다([`backend.md`](backend.md) §3). **이름을 바꾸면 그 표도 함께 고친다.**

그 밖의 인덱스:

| 테이블 | 인덱스 | 쓰는 곳 |
|---|---|---|
| `queue_entry` | `(equipment_id, status, enqueued_at)` | 대기열 조회·순번 계산 |
| `usage_session` | `(equipment_id, ended_at)` | 기구의 진행 중 세션 |
| `usage_session` | `(member_id, equipment_id, ended_at)` | 재대기 제한(그 회원의 그 기구 마지막 세션) |

---

## 4. 마이그레이션

`doctrine/doctrine-migrations-bundle` 을 쓴다. **스키마 도구(`doctrine:schema:update`)로 운영 스키마를 만들지 않는다.**

AGENTS.md §5 의 정지선이 여기 걸린다 — **AI 는 마이그레이션 파일 생성과 검토까지만 하고, 실제 적용(`doctrine:migrations:migrate`)은 사람이 한다.**

---

## 5. 정하지 않은 것

| 항목 | 언제 정하나 | 무엇에 달렸나 |
|---|---|---|
| 생성 컬럼을 엔티티 매핑에서 다루는 법 | 4 단계 | 매핑하지 않으면 `doctrine:migrations:diff` 가 그 컬럼을 지우자고 제안한다. 읽기 전용 매핑(`insertable: false, updatable: false`)으로 둘지, diff 결과를 손으로 정리할지를 실제로 돌려 보고 정한다 |

---

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | `backend.md` §4.1~§4.3·§4.6 과 §8 의 생성 컬럼 매핑 항목을 옮겨 신설 | 물리 DB 설계는 스키마 변경 승인(AGENTS.md §3)의 대상이라 아키텍처 본문과 떼어 둔다. `data-model.md` 가 가리키는 "물리 매핑" 의 자리를 한 문서로 만든다 |
