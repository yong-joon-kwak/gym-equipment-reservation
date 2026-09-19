---
status: in-progress
---

# 구축 계획 — 0에서 모노레포를 세우는 순서

> 이 저장소는 **비어 있는 상태에서 시작한다.** 옮겨 올 앱은 없다.
> 이 문서는 "무엇을 어떤 순서로 만들고, 각 단계가 언제 끝난 것인가" 만 정한다.
> 왜 모노레포인가는 [`architecture/monorepo.md`](architecture/monorepo.md), 문서 규칙은 [`README.md`](README.md).
>
> 스택: **백엔드 PHP 8.4 · Symfony 7.4 · MariaDB 11.4** / **프론트 Vue 3 · Vite · TypeScript · Vitest** / **계약 OpenAPI → TypeScript**

---

## 0. 원칙

- **함께 바뀌는 것을 한 저장소에.** API 를 바꾼 커밋과 그 API 를 쓰는 화면을 바꾼 커밋이 한 diff·한 배포로 나간다.
- **계약은 코드로.** 백엔드 OpenAPI 스펙을 프론트 타입의 단일 출처로 삼는다(`packages/api-client`).
- **가드를 나중에 붙이지 않는다.** 라벨 강제 장치(T4)는 테스트가 처음 생긴 직후에 들어간다. 나중에 붙이면 라벨은 반드시 절반만 붙는다.
- **작은 diff 로 단계 이동.** 각 단계가 초록인 상태로 커밋한다.

---

## 1. 목표 트리

```text
gym-equipment-reservation/
├── AGENTS.md                     # 모든 AI 도구가 읽는 정책 SSOT (루트)
├── CLAUDE.md                     # "AGENTS.md 를 따르라" 만
├── package.json                  # npm workspaces 루트 (apps/frontend, packages/*)
├── lefthook.yml                  # 커밋/푸시 가드 (백+프론트)
├── .github/workflows/ci.yml      # backend · frontend · contract 3잡
│
├── apps/
│   ├── backend/                  # Symfony 7.4
│   │   ├── composer.json  phpunit.dist.xml  phpstan.neon.dist
│   │   ├── .env  .env.test       # 커밋되는 기본값 (실제 값은 .env.local — 무시됨)
│   │   ├── config/  public/  src/
│   │   ├── openapi/openapi.json  # nelmio 로 생성 (커밋 대상)
│   │   └── tests/
│   │       ├── Unit/             # T1 규칙 — 목 없음
│   │       ├── Collaboration/    # T2 협력 — 경계만 가짜(fake)
│   │       ├── Integration/      # T3 통합 — 실 DB
│   │       ├── Architecture/     # T4 구조 — FeatureCoverageTest
│   │       └── Support/          # 가짜 구현·#[Feature] 정의 (테스트 아님)
│   │
│   └── frontend/                 # Vue 3 + Vite + TS
│       ├── package.json  vite.config.ts  tsconfig.json  index.html
│       └── src/
│           ├── main.ts  App.vue
│           ├── features/equipment-reservation/   # 화면 + 스토어 + *.spec.ts
│           ├── features/equipment-catalog/
│           └── shared/
│
├── packages/
│   └── api-client/               # OpenAPI → 생성된 TS 타입·클라이언트
│       ├── package.json          # "@gym/api-client"
│       ├── src/generated.ts      # 생성물 (커밋 대상, 수기 수정 금지)
│       └── src/index.ts          # 얇은 재노출 + ky 인터셉터
│
└── docs/
    ├── README.md                 # 문서 지도·상태 규칙
    ├── BUILD-PLAN.md             # 이 문서
    ├── architecture/monorepo.md
    ├── architecture/api-contract.md
    ├── architecture/backend.md
    ├── coding/test-as-specification.md
    └── features/
        ├── equipment-reservation/README.md
        └── equipment-catalog/README.md
```

핵심: **apps 는 실행 단위(개수가 늘어나는 쪽), packages 는 앱 사이의 공유물(반복이 쌓인 뒤 빼는 쪽).** 지금은 프론트 하나·백 하나라 packages 는 `api-client` 하나뿐이다.

---

## 2. 워크스페이스 배선

### JS — npm workspaces (루트 `package.json`)

```jsonc
{
  "name": "gym-equipment-reservation",
  "private": true,
  "workspaces": ["apps/frontend", "packages/*"],
  "scripts": {
    "api:sync":   "npm -w apps/backend run api:spec && npm -w packages/api-client run generate",
    "typecheck":  "npm -w apps/frontend run typecheck",
    "test:front": "npm -w apps/frontend run test",
    "test:api":   "composer -d apps/backend test",
    "stan":       "composer -d apps/backend stan"
  }
}
```

- `apps/frontend` 는 `packages/api-client` 를 `"@gym/api-client": "*"` 로 의존한다(워크스페이스 링크). 별도 배포 없이 소스 링크로 쓴다.
- PHP 는 워크스페이스 개념이 없으므로 `apps/backend` 는 자기 `composer.json` 을 갖는 독립 루트다. 루트에서는 `composer -d apps/backend ...` 로 부른다.

### DB — MariaDB 단일

SQLite 대체 경로를 두지 않는다. **동시성 최종 보증(같은 활성 슬롯 중복 저장을 유니크 제약이 막는다)이 이 기능의 최소 보장에 들어 있고, 그 보장은 운영형 DB 에서만 진짜다.** 두 DB 를 지원하면 통합 테스트가 어느 쪽에서 초록인지 모호해진다.

- 로컬: 개발 기기에 MariaDB 11.4 를 직접 설치해 띄운다(macOS 는 `brew install mariadb@11.4` → `brew services start mariadb@11.4`). 빈 스키마 하나를 만들고, 접속 정보는 `apps/backend/.env.local` 의 `DATABASE_URL` 에 넣는다([`backend.md` §6.5](architecture/backend.md)).
- CI: GitHub Actions 의 `services:` 가 띄우는 MariaDB 11.4 에 붙는다. 워크플로 파일이 그 설정의 정본이다.
- 대가: **로컬에 MariaDB 가 없으면 통합 테스트를 못 돌린다.** T1·T2·T4 는 DB 없이 돌므로 커밋 가드는 영향받지 않는다.

---

## 3. 단계 (각 단계 끝에서 초록 커밋)

| 단계 | 할 일 | 완료 조건 |
|---|---|---|
| 1 | 루트 배선 — `package.json`(workspaces) · `lefthook.yml` · `.gitignore` · `.github/workflows/ci.yml` 골격 | 로컬 MariaDB 에 `DATABASE_URL` 로 접속되고 `lefthook install` 이 된다 |
| 2-1 | **백엔드 골격** — Symfony 7.4 skeleton, 환경 변수 배치, composer 스크립트 5종, 티어별 testsuite, phpstan(max) ([`backend.md`](architecture/backend.md) §1·§6) | `composer -d apps/backend run stan` 과 `test` 가 **테스트 0건으로 초록**. DB 불필요 |
| 2-2 | **도메인 T1** — 값 객체·엔티티·`ReservationPolicy` 와 목 없는 규칙 테스트 ([`backend.md`](architecture/backend.md) §2·§3) | T1 초록. `test:testdox` 출력이 한국어 보장 문장으로 읽힌다 |
| 3 | **T4 가드** — `FeatureCoverageTest` 3종 검사 + 기능 문서 2개 연결 | 라벨 없는 테스트 클래스를 일부러 넣으면 **실패**함을 확인 |
| 4 | 영속화 + **T2·T3** — Doctrine 매핑·유니크 제약, 응용 서비스와 인메모리 fake 리포지토리(T2), 실 DB 흐름·동시성(T3) ([`backend.md`](architecture/backend.md) §4) | 전체 스위트 초록 (로컬 MariaDB 필요) |
| 5 | HTTP + **OpenAPI** — 컨트롤러·요청/응답 DTO, `nelmio/api-doc-bundle`, `npm run api:spec` ([`backend.md`](architecture/backend.md) §5) | `apps/backend/openapi/openapi.json` 생성됨 |
| 6 | `packages/api-client` — `openapi-typescript` 로 `src/generated.ts`, 얇은 `index.ts`(ky 인터셉터·거부 사유 에러 타입) | `npm -w packages/api-client run build` 통과 |
| 7 | `apps/frontend` — Vue 3 + Vite + TS. 예약 화면과 기구 목록. `@gym/api-client` 만 의존 | `vue-tsc --noEmit` + `vite build` 통과 |
| 8 | **프론트 Vitest** — 4절의 계약 보장 | `vitest run` 초록 |
| 9 | CI 3잡 완성 — `backend` · `frontend` · **`contract`**(drift 검사) | 스펙을 바꾸고 `api:sync` 를 빼먹으면 CI 가 **실패**함을 확인 |

2단계를 둘로 나눈 이유: **골격과 도메인은 성격이 다르다.** 2-1 은 도구 배선이라 도메인을 몰라도 끝나고, 2-2 는 도구를 다시 건드리지 않는다. 한 칸에 두면 설계 논의가 골격 작업을 붙잡는다.

소급하지 않는다: 1~5 단계까지는 프론트가 없어도 백엔드가 그대로 돌아야 한다.

---

## 4. 프론트엔드 범위와 최소 보장

화면은 둘: **예약**(가능한 시간을 보고 → 점유(HELD) → 확정 또는 취소)과 **기구 목록**(관리자가 등록·비활성화한 결과를 반영). 넓히지 않는다.

프론트의 "최소 보장"(Vitest 로 고정할 것)은 각 기능 문서가 정본이다 — [`features/equipment-reservation/README.md`](features/equipment-reservation/README.md) · [`features/equipment-catalog/README.md`](features/equipment-catalog/README.md).

> 프론트는 규칙의 **소유자가 아니다.** 판정은 백엔드가 하고, 프론트는 그 계약(타입·거부 사유)을 그대로 반영한다. 프론트 테스트는 "규칙을 다시 구현했는가" 가 아니라 "계약을 지켰는가" 를 본다.

---

## 5. 계약 흐름 (요지 — 상세는 [`architecture/api-contract.md`](architecture/api-contract.md))

```text
Symfony Controller + DTO
  └─ nelmio/api-doc-bundle        → apps/backend/openapi/openapi.json   (npm run api:spec)
      └─ openapi-typescript        → packages/api-client/src/generated.ts (npm run generate)
          └─ apps/frontend 는 @gym/api-client 만 의존 (수기 타입 없음)
```

재생성은 **수동**(`npm run api:sync`)이고, 빼먹은 것은 9단계의 `contract` 잡이 CI 실패로 드러낸다.

---

## 6. 가드와 CI

설정의 정본은 문서가 아니라 **`lefthook.yml` 과 `.github/workflows/ci.yml` 파일 자체**다. 여기서는 배치만 설명한다.

| 시점 | 무엇을 | 왜 |
|---|---|---|
| pre-commit | phpstan(max) · phpunit `--group unit --group collaboration --group structure` · `vue-tsc` · `vitest run` | 빠르고 결정적·DB 불필요 — 커밋을 막을 자격이 있는 것만 |
| pre-push | 위 + phpunit 전체(T3 통합, DB 필요) | 느린 것은 푸시에서 |
| CI `backend` | phpstan(max) → 빠른 티어 → 전체(MariaDB 서비스) → `--testdox` | 사람이 읽는 명세 출력까지 |
| CI `frontend` | `vue-tsc` → `vitest run` → `vite build` | 백과 병렬 |
| CI `contract` | `npm run api:sync` 후 `git diff --exit-code` | 수동 재생성 누락을 실패로 드러낸다 |

---

## 7. 한계 (이 저장소의 한계 **정본**)

다른 문서는 자기 주제에 해당하는 한 줄만 쓰고 여기로 링크한다.

| 한계 | 지금 상태 | 왜 이렇게 뒀나 |
|---|---|---|
| **배포층이 없다** | CI 까지만. Jenkins·systemd 워커·S3/EC2 배포는 범위 밖 | 실무(V-Pass)에는 있으나, 이 데모에서 재현하면 저장소의 초점이 "AI 가 일할 구조" 에서 "배포 파이프라인" 으로 옮겨간다. 다음 단계에서 재검토한다 |
| **packages 가 하나다** | `api-client` 하나뿐 | 계약의 개수는 소비자 수를 따라간다. 앱이 늘어야 공용 규칙의 이점이 실현된다 |
| **프론트의 테스트=명세 강제가 백보다 약하다** | 백의 `FeatureCoverageTest` 에 해당하는 리플렉션 가드가 프론트에 없다 | 파일 규칙(`features/<슬러그>/*.spec.ts`)과 작은 lint 스크립트로 대신한다. 이 비대칭을 숨기지 않는다 |
| **일괄 배포 비용** | 한 앱만 고쳐도 전체가 빌드된다 | 앱이 늘면 "변경된 앱만 빌드하되 배포는 함께" 로 절충한다. 배포 단위를 쪼개는 것은 마지막 선택지 — 쪼개는 순간 없애려던 어긋난 구간이 돌아온다 |
| **로컬 MariaDB 없이는 T3 를 못 돌린다** | MariaDB 단일 | 동시성 보장을 SQLite 에서 흉내 내는 것보다, 못 돌리는 것을 드러내는 편이 정직하다 |
| **측정하지 않는다** | 관측성 체계 없음. "테스트=명세가 동반 수정 빈도를 줄였는가" 를 이 저장소에서는 수치로 보이지 못한다 | 데모 규모에서 나오는 숫자는 의미가 없다. 지표는 실무 저장소의 몫이다 |
