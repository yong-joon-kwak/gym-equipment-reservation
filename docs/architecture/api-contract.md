# API 계약 — HTTP 표면 · 거부 사유 · 프론트 타입의 단일 출처

> 백엔드가 바깥에 내는 **HTTP 계약**의 정본이다. 엔드포인트, 응답 형태, 거부 사유 목록을 정하고, 그 계약이 프론트 타입으로 옮겨지는 경로를 정한다.
> 프론트가 응답 타입을 수기로 쓰지 않게 한다. 백엔드 OpenAPI 스펙에서 TypeScript 를 생성하고, 프론트는 그 생성물(`packages/api-client`)에만 의존한다.
>
> 되풀이하지 않는 것: 판정 순서와 판정 객체 → [`backend.md`](backend.md) §2 · 인증 방식 → [`backend.md`](backend.md) §5 · 업무 흐름 → [`system-overview.md`](system-overview.md)
>
> §1~§3 은 **설계 의도**다. 5-2 단계 뒤로는 생성된 `openapi.json` 이 사실의 정본이고, 둘이 달라지면 같은 커밋에서 이 문서를 고친다. 계약을 바꾸는 일(Breaking change)은 사람이 결정한다(AGENTS.md §3).

---

## 1. 엔드포인트

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
- **`GET` 은 상태를 바꾸지 않는다.** 지연 정리를 계산만 하고 저장하지 않는다([`backend.md`](backend.md) §2.3).
- **태깅은 새로고침에 안전하다.** QR 페이지(`/e/{code}`)는 들어올 때 태깅을 한 번 보내고, 이후로는 기구 상세를 폴링한다. 새로고침으로 태깅이 다시 와도, 이미 쓰는 중이거나 기다리는 중이면 `unchanged` 다([`backend.md`](backend.md) §2.2).

---

## 2. 응답 형태

성공은 자원 표현을 그대로 준다. 실패는 **한 가지 형태만** 쓴다.

```json
{ "error": "TagRejected", "reason": "equipment_inactive", "message": "사용할 수 없는 기구입니다" }
```

- `error` 는 예외 클래스의 짧은 이름, `reason` 은 §3 의 값, `message` 는 사람이 읽는 한국어 문장이다.
- 프론트가 분기에 쓰는 것은 **`reason` 뿐이다.** `message` 는 표시용이고 계약이 아니다.
- 변환은 `Ui/Http` 의 예외 리스너 한 곳에서 한다. 컨트롤러마다 try/catch 를 두지 않는다.
- 검증 실패(입력 형식)는 `422` + `reason: "invalid_request"` 와 필드별 오류 목록을 덧붙인다.
- 로그인하지 않은 요청은 `401` + `unauthenticated` 다. 기본 로그인 페이지로 돌려보내지 않는다.

---

## 3. 거부 사유(`reason`)

판정의 소유자는 백엔드이고, 판정이 거부로 끝날 때 바깥에 내는 **사유 목록의 정본은 여기다.** 프론트는 이 문자열을 그대로 노출하고 자체 판정을 두지 않는다(§7).

| `reason` | HTTP | 언제 | 어디서 |
|---|---|---|---|
| `equipment_not_found` | 404 | 기구 코드가 없거나 삭제됨(`dbstatus = 'D'`) | 태깅 · 조회 · 관리 |
| `equipment_inactive` | 422 | 비활성 기구를 태깅 | 태깅 |
| `already_waiting_elsewhere` | 409 | 다른 기구에 진행 중인 대기가 있는데 대기 등록하려 함 | 태깅 |
| `requeue_blocked` | 422 | 재대기 제한이 풀리기 전에 같은 기구에 대기 등록하려 함 | 태깅 |
| `equipment_taken` | 409 | 같은 기구에 대한 동시 태깅이 DB 제약에 걸림. 다시 태깅하면 판정이 새로 난다([`backend.md`](backend.md) §3) | 태깅 |
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
- **남의 것은 404 다.** 사용 세션·대기는 "내 진행 중인 것" 으로만 찾으므로(§1), 남의 것은 존재 여부부터 드러내지 않는다. 옛 `not_owner`(403)는 두지 않는다.
- 사유는 `RejectionReason` **enum(string)** 하나로 두고, 값이 곧 응답 문자열이다. 새 사유는 enum 에 추가되므로 문자열 오타가 타입 검사에서 걸린다.
- 표기는 **snake_case** 다. 응답에 실리는 문자열이라 JSON 관례를 따르고, 도메인 enum(상태·종료 사유, UPPER_SNAKE)과 일부러 다르게 둔다 — 둘을 섞어 읽지 않게 하려는 것이다.
- 사유를 바꾸면 이 표를 먼저 고치고, [`system-overview.md`](system-overview.md) §2.1 의 `Reject_…` 라벨을 맞춘다.

---

## 4. 생성 경로

```text
apps/backend  Symfony Controller + Request/Response DTO
  └─ nelmio/api-doc-bundle                 → apps/backend/openapi/openapi.json
      │  #[OA\Response(content: new Model(type: XxxDto::class))]
      │  npm run api:spec                (내부: bin/console nelmio:apidoc:dump)
      └─ openapi-typescript                → packages/api-client/src/generated.ts
          │  npm -w packages/api-client run generate
          └─ apps/frontend 는 @gym/api-client 만 import (수기 타입 없음)

한 방에: npm run api:sync
```

- **생성물은 커밋 대상이다.** 리뷰어가 계약의 형태를 diff 로 볼 수 있고, CI 가 "재생성했는데 달라졌는가"로 drift 를 잡을 수 있다.
- **생성물은 수기로 고치지 않는다.** 스펙(백엔드 코드)을 고치고 재생성한다. (AGENTS.md §2·§5)

---

## 5. 도구 선택 (권장값)

| 역할 | 선택 | 이유 |
|---|---|---|
| 스펙 생성 | `nelmio/api-doc-bundle` | Symfony 코드(속성)에서 OpenAPI 를 뽑는다 — 스펙의 출처가 코드다 |
| TS 생성 | `openapi-typescript` | 타입만 얇게 생성(런타임 코드 최소). 호출은 `ky` 로 직접 감싼다 |
| HTTP 클라이언트 | `ky` | fetch 기반 경량, 인터셉터로 에러·헤더 일원화 |

> `swagger-typescript-api`(타입+호출 메서드까지 생성)도 대안이다. 이 데모는 "타입은 생성, 호출은 얇게 직접"을 택해 생성물의 표면을 작게 유지한다 — 대가는 요청 함수를 조금 손으로 쓰는 것.

---

## 6. packages/api-client 의 표면

```text
packages/api-client/
├── package.json          "name": "@gym/api-client"
└── src/
    ├── generated.ts      # openapi-typescript 출력 (수기 수정 금지)
    └── index.ts          # 얇은 재노출 + ky 인스턴스(baseUrl·세션 쿠키 포함·에러 매핑)
```

`index.ts` 가 하는 일은 최소다: 생성된 타입을 re-export 하고, 서버의 **거부 사유(`{error, reason}`)를 그대로 실은 에러 타입**을 노출한다. 프론트는 이 타입으로 거부 사유를 화면에 옮긴다.

---

## 7. 프론트가 지키는 계약 (규칙을 다시 만들지 않는다)

- **판정은 백엔드가 한다.** 프론트는 `422 equipment_inactive` / `422 requeue_blocked` 같은 사유(§3)를 받아 **그대로 노출**하고, 자체 판정을 두지 않는다.
- 예외적으로 프론트가 아는 것은 **계약에 이미 있는 값**뿐이다 — 예: 연장 버튼은 기구 상세 응답의 연장 가능 여부로 켜고 끈다. 프론트가 만료 시각을 보고 마감 임박을 스스로 계산하지 않는다. 버튼을 미리 끄는 것은 "거부를 미리 줄이는 편의"이지 규칙의 출처가 아니다. 최종 판정은 언제나 서버 응답으로 수렴시킨다.
- 로그인 판정처럼 "상태를 프론트가 해석"해야 하는 것도, **로컬 값이 아니라 서버 응답을 최종 기준**으로 삼는다(세션 쿠키 로그인 — [`backend.md`](backend.md) §5).

---

## 8. drift 를 CI 가 잡는다

재생성은 **수동**(`npm run api:sync`)이다. 사람이 스펙을 바꾸고 재생성을 빼먹으면 프론트는 옛 타입을 믿는다 — 타입 검사는 통과하고 런타임에서 `undefined` 가 난다.

그래서 CI 에 `contract` 잡을 둔다.

```bash
npm run api:sync
git diff --exit-code    # 생성물이 달라졌으면 = 재생성을 빼먹은 것 → 실패
```

생성물을 커밋 대상으로 두는 이유가 여기서 드러난다. **커밋된 생성물이 있어야 "다시 만들었더니 달라졌다" 를 diff 로 물을 수 있다.**

- 대가: CI 가 한 겹 무거워지고, 백엔드를 CI 에서 한 번 더 기동해야 한다.
- 이 잡이 보장하는 것은 "스펙과 타입이 일치한다" 까지다. **스펙이 실제 응답과 일치하는지는 보장하지 않는다** — 그건 백엔드의 통합 테스트(T3)가 보는 것이다.

---

## 9. 한계

**엔드포인트가 늘면** area/소비자별로 스펙을 쪼갤지(nelmio Areas) 판단이 필요해진다. 지금은 소비자가 하나라 한 벌로 충분하다.

저장소 전체의 한계 목록은 [`BUILD-PLAN.md` §7](../BUILD-PLAN.md) 에 있다.

---

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | `backend.md` §3(거부 사유)·§5.1(엔드포인트)·§5.3(응답 형태)을 옮겨 §1~§3 신설. 절에 번호를 붙임 | HTTP 계약은 프론트가 읽고, 바꾸려면 사람이 승인한다(AGENTS.md §3). 계약 문서가 생성 경로만 담고 본문은 백엔드 아키텍처 문서를 가리키던 구조를 바로잡는다 |
