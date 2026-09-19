---
status: in-progress
---

# 구축 계획 — 0에서 모노레포를 세우는 순서

> 이 저장소는 **비어 있는 상태에서 시작한다.** 옮겨 올 앱은 없다.
> 이 문서는 "무엇을 어떤 순서로 만들고, 각 단계가 언제 끝난 것인가" 만 정한다.
> 왜 모노레포인가는 [`architecture/monorepo.md`](architecture/monorepo.md), 문서 규칙은 [`README.md`](README.md).
>
> 스택: **백엔드 PHP 8.4 · Symfony 7.4 · MariaDB 12.3** / **프론트 Vue 3 · Vite · TypeScript · Vitest** / **계약 OpenAPI → TypeScript**

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
│   │   ├── README.md         # 개발 환경 (의존성·스크립트·환경 변수)
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
│           ├── features/equipment-queue/         # 화면 + 스토어 + *.spec.ts
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
    ├── architecture/persistence.md       # 물리 매핑·DB 제약·마이그레이션
    ├── architecture/system-overview.md   # 비즈니스 흐름·경계·레이어
    ├── architecture/data-model.md        # 엔티티 설계 (논리 ERD)
    ├── business/domain-glossary.md       # 도메인 용어집
    ├── coding/test-as-specification.md
    ├── ai-agent/README.md        # 설계 문서 AI 작성 지시서
    ├── plans/                    # 단계별 세부 계획 (<단계>-<이름>.md)
    └── features/
        ├── equipment-queue/README.md
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
    "api:spec":   "composer -d apps/backend run api:spec",
    "api:sync":   "npm run api:spec && npm -w packages/api-client run generate",
    "typecheck":  "npm -w apps/frontend run typecheck",
    "test:front": "npm -w apps/frontend run test",
    "test:api":   "composer -d apps/backend run test",
    "stan":       "composer -d apps/backend run stan"
  }
}
```

- `apps/frontend` 는 `packages/api-client` 를 `"@gym/api-client": "*"` 로 의존한다(워크스페이스 링크). 별도 배포 없이 소스 링크로 쓴다.
- PHP 는 워크스페이스 개념이 없으므로 `apps/backend` 는 자기 `composer.json` 을 갖는 독립 루트다. 루트에서는 `composer -d apps/backend ...` 로 부른다.

### DB — MariaDB 단일

SQLite 대체 경로를 두지 않는다. **동시성 최종 보증(한 기구에 진행 중인 사용 세션이 둘 생기는 것을 유니크 제약이 막는다)이 이 기능의 최소 보장에 들어 있고, 그 보장은 운영형 DB 에서만 진짜다.** 두 DB 를 지원하면 통합 테스트가 어느 쪽에서 초록인지 모호해진다.

- 로컬: 개발 기기에 MariaDB 12.3 을 직접 설치해 띄운다(macOS 는 `brew install mariadb` → `brew services start mariadb`). 빈 스키마 하나를 만들고, 접속 정보는 `apps/backend/.env.local` 의 `DATABASE_URL` 에 넣는다([`apps/backend/README.md` §4](../apps/backend/README.md)).
- CI: GitHub Actions 의 `services:` 가 띄우는 MariaDB 12.3 에 붙는다. 워크플로 파일이 그 설정의 정본이다.
- 대가: **로컬에 MariaDB 가 없으면 통합 테스트를 못 돌린다.** T1·T2·T4 는 DB 없이 돌므로 커밋 가드는 영향받지 않는다.

---

## 3. 단계 (각 단계 끝에서 초록 커밋)

| 단계 | 할 일 | 완료 조건 |
|---|---|---|
| 1 | 루트 배선 — `package.json`(workspaces) · `lefthook.yml` · `.gitignore` · `.github/workflows/ci.yml` 골격 | 로컬 MariaDB 에 `DATABASE_URL` 로 접속되고 `lefthook install` 이 된다 |
| 2-1 | **백엔드 골격** — Symfony 7.4 skeleton, 환경 변수 배치, composer 스크립트 5종, 티어별 testsuite, phpstan(max) ([`backend.md`](architecture/backend.md) §1 · [`apps/backend/README.md`](../apps/backend/README.md)) | `composer -d apps/backend run stan` 과 `test` 가 **테스트 0건으로 초록**. DB 불필요 |
| 2-2 | **기능 정의** (문서만) — `features/equipment-queue/README.md` 신설, `features/equipment-catalog/README.md` 정정. 여러 칸에 걸치는 설계 결정(도메인의 UUID 생성 의존, 2-4~2-7 에서 엔티티를 어디까지 만드는가)을 [`backend.md`](architecture/backend.md) 에 반영 | 두 기능 문서의 최소 보장과 티어가 정해지고 개발자가 확인했다 |
| 2-3 | **T4 가드** — `#[Feature]` 어트리뷰트(`tests/Support/Feature.php`) + `FeatureCoverageTest` 3종 검사 + 기능 문서 2개 연결 | 테스트 0건으로 초록. 라벨 없는 테스트 클래스를 일부러 넣으면 **실패**함을 확인 |
| 2-4 | **도메인 기반** — 규칙 값·enum·값 객체, 엔티티 넷(ORM 어트리뷰트 없이). 대상 보장: `UsageSession` 의 만료 시각 전이([`data-model.md`](architecture/data-model.md) §5) | 해당 보장의 T1 초록 |
| 2-5 | **`ExtensionPolicy`** — 연장 판정 | 연장 보장의 T1 초록 |
| 2-6 | **`TagPolicy`** — 태깅 판정 | [`system-overview.md`](architecture/system-overview.md) §2.1 흐름도의 분기마다 T1 초록 |
| 2-7 | **`SettlementPolicy`** — 지연 정리, 그리고 순번·예상 대기 시간 계산 | 호출·노쇼·멱등 T1 초록. `test:testdox` 출력이 한국어 보장 문장으로 읽힌다 |
| 4 | 영속화 + **T2·T3** — 매핑·유니크 제약, 서비스와 인메모리 fake 리포지토리(T2), 실 DB 흐름·동시성(T3) | 전체 스위트 초록 (로컬 MariaDB 필요) |
| 5-1 | **인증** — 세션 기반 로그인(`json_login`), 시드 회원·관리자, 역할 구분 | 로그인 후 현재 회원을 돌려주는 엔드포인트가 T3 로 초록 |
| 5-2 | HTTP + **OpenAPI** — 태깅·현황·관리자 엔드포인트와 요청/응답 DTO, `nelmio/api-doc-bundle`, `npm run api:spec` | `apps/backend/openapi/openapi.json` 생성됨 |
| 6 | `packages/api-client` — `openapi-typescript` 로 `src/generated.ts`, 얇은 `index.ts`(ky 인터셉터·거부 사유 에러 타입) | `npm -w packages/api-client run build` 통과 |
| 7 | `apps/frontend` — Vue 3 + Vite + TS. 현황 목록·기구 상세·관리자 세 화면(+ 로그인 폼). `@gym/api-client` 만 의존 | `vue-tsc --noEmit` + `vite build` 통과 |
| 8 | **프론트 Vitest** — 4절의 계약 보장 | `vitest run` 초록 |
| 9 | CI 3잡 완성 — `backend` · `frontend` · **`contract`**(drift 검사) | 스펙을 바꾸고 `api:sync` 를 빼먹으면 CI 가 **실패**함을 확인 |

2단계와 5단계를 나눈 이유는 같다: **배선과 설계는 성격이 다르다.** 2-1(골격)·5-1(인증)은 도구를 까는 일이라 업무 규칙을 몰라도 끝나고, 그 뒤 칸들은 그 도구를 다시 건드리지 않는다. 한 칸에 두면 설계 논의가 배선 작업을 붙잡는다.

소급하지 않는다: 1~5 단계까지는 프론트가 없어도 백엔드가 그대로 돌아야 한다.

2단계를 더 잘게 나눈 이유: 옛 2-2(도메인 T1)는 **정의(무엇을 보장하는가) · 설계(객체의 모양) · 구현** 을 한 칸에 담아, 어디서 시작할지 잡히지 않았다. 그래서 순서를 **보장 → 가드 → 판정 객체 하나씩** 으로 폈다.

- **가드를 첫 테스트보다 앞에 둔다.** 가드는 기능 문서만 있으면 테스트 0건으로도 초록이다. 옛 3단계(T4 가드)를 2-3 으로 당긴 것은 §0 의 "가드를 나중에 붙이지 않는다" 를 글자 그대로 지키기 위해서다. 3단계 번호는 비워 두고 4단계부터는 번호를 바꾸지 않는다 — 다른 문서가 `5-1`·`5-2` 로 가리키고 있다.
- **구현 칸 하나 = 판정 객체 하나 = 기능 문서의 보장 한두 줄.** 막히면 어디서 막혔는지 칸 이름이 말한다. 가장 복잡한 `SettlementPolicy`(시간 연쇄 계산)를 마지막에 둔다.

### 구현 칸(2-4~2-7) 안의 순서

칸마다 같은 순서를 밟는다.

| # | 할 일 | 누가 |
|---|---|---|
| 1 | 계획 `plans/2-N-<이름>.md` — 이 칸이 덮는 기능 문서의 보장 줄 · 이 칸이 **기대는 설계 문서의 절** · 이 칸에서 필요한 결정 · **`#[TestDox]` 문장 목록** | 에이전트 |
| 2 | 계획 확인 — 테스트 문장 목록과 기대는 절을 읽고 계획의 `user_validated` 를 `true` 로 | 개발자 |
| 3 | 테스트 → 구현 → 초록 커밋. 설계와 달라진 것은 같은 커밋에서 문서를 고친다 | 에이전트 |

계획 문서는 **다음 칸 것만** 쓴다. 앞 칸을 구현하며 배운 것이 다음 설계에 들어가야 한다. 확인 범위를 칸 단위로 좁히는 규칙은 [`AGENTS.md`](../AGENTS.md) §5.

---

## 4. 프론트엔드 범위와 최소 보장

화면은 셋이다 — **현황 목록**(전체 기구의 사용 중·대기 인원), **기구 상세**(QR 이 가리키는 곳. 태깅·내 순번·남은 시간), **관리자**(기구 등록·최대시간·비활성화·강제 종료). 미인증이면 로그인 폼을 띄운다. 넓히지 않는다. 범위의 정본은 [`../README.md`](../README.md).

프론트의 "최소 보장"(Vitest 로 고정할 것)은 각 기능 문서가 정본이다 — `features/equipment-queue/README.md`(작성 예정) · [`features/equipment-catalog/README.md`](features/equipment-catalog/README.md).

현황 갱신은 폴링이다. 서버 푸시(Mercure)로 올리는 것은 배포층 결정과 함께 다룬다(§7).

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

---

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | 옛 2-2(도메인 T1)를 2-2 기능 정의 · 2-3 T4 가드(옛 3단계) · 2-4~2-7 판정 객체별 구현으로 나눔. 구현 칸 안의 순서(계획 → 확인 → 구현)를 추가 | 2-2 를 진행하다 막힘 — 기능 문서(`equipment-queue`)가 없어 무엇을 테스트할지 근거가 없었고, 정의·설계·구현이 한 칸에 있었다 |
