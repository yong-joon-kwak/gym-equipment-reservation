# API 계약 — 백엔드 스펙을 프론트 타입의 단일 출처로

> 프론트가 응답 타입을 수기로 쓰지 않게 한다. 백엔드 OpenAPI 스펙에서 TypeScript 를 생성하고,
> 프론트는 그 생성물(`packages/api-client`)에만 의존한다.

---

## 생성 경로

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

## 도구 선택 (권장값)

| 역할 | 선택 | 이유 |
|---|---|---|
| 스펙 생성 | `nelmio/api-doc-bundle` | Symfony 코드(속성)에서 OpenAPI 를 뽑는다 — 스펙의 출처가 코드다 |
| TS 생성 | `openapi-typescript` | 타입만 얇게 생성(런타임 코드 최소). 호출은 `ky` 로 직접 감싼다 |
| HTTP 클라이언트 | `ky` | fetch 기반 경량, 인터셉터로 에러·헤더 일원화 |

> `swagger-typescript-api`(타입+호출 메서드까지 생성)도 대안이다. 이 데모는 "타입은 생성, 호출은 얇게 직접"을 택해 생성물의 표면을 작게 유지한다 — 대가는 요청 함수를 조금 손으로 쓰는 것.

---

## packages/api-client 의 표면

```text
packages/api-client/
├── package.json          "name": "@gym/api-client"
└── src/
    ├── generated.ts      # openapi-typescript 출력 (수기 수정 금지)
    └── index.ts          # 얇은 재노출 + ky 인스턴스(baseUrl·X-Member-Id·에러 매핑)
```

`index.ts` 가 하는 일은 최소다: 생성된 타입을 re-export 하고, 서버의 **거부 사유(`{error, reason}`)를 그대로 실은 에러 타입**을 노출한다. 프론트는 이 타입으로 거부 사유를 화면에 옮긴다.

---

## 프론트가 지키는 계약 (규칙을 다시 만들지 않는다)

- **판정은 백엔드가 한다.** 프론트는 `409 EquipmentTaken` / `422 PastSlot` 같은 사유를 받아 **그대로 노출**하고, 자체 판정을 두지 않는다.
- 예외적으로 프론트가 아는 것은 **계약에 이미 있는 형태**뿐이다 — 예: 슬롯 그리드(정시·고정 길이)는 UI 입력 제약으로 반영할 수 있으나, 그것은 "거부를 미리 줄이는 편의"이지 규칙의 출처가 아니다. 최종 판정은 언제나 서버 응답으로 수렴시킨다.
- 로그인 판정처럼 "상태를 프론트가 해석"해야 하는 것도, **로컬 값이 아니라 서버 응답을 최종 기준**으로 삼는다(이 데모는 X-Member-Id 헤더로 단순화).

---

## drift 를 CI 가 잡는다

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

## 한계

**엔드포인트가 늘면** area/소비자별로 스펙을 쪼갤지(nelmio Areas) 판단이 필요해진다. 지금은 소비자가 하나라 한 벌로 충분하다.

저장소 전체의 한계 목록은 [`BUILD-PLAN.md` §7](../BUILD-PLAN.md) 에 있다.
