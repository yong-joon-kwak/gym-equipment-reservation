# docs — 문서 지도와 규칙

> 정책의 내용은 [`AGENTS.md`](../AGENTS.md) 에 있다. 이 파일은 **문서를 어떻게 쓰고 읽는가** 만 정한다.

---

## 1. 무엇이 어디에 사는가 (중복 금지)

AGENTS.md §1 의 "정책은 다른 파일에 중복 작성하지 않는다" 를 문서 전체로 확장한 규칙이다.
**어떤 사실이든 정본은 한 곳이고, 나머지 문서는 링크만 한다.** 복사해 오면 두 벌이 되고 조용히 갈라진다.

| 사실 | 정본 | 다른 문서에서는 |
|---|---|---|
| 개발 정책·AI 권한 경계·정지선 | [`AGENTS.md`](../AGENTS.md) | 링크 |
| 왜 모노레포인가 · apps/packages 경계의 근거 | [`architecture/monorepo.md`](architecture/monorepo.md) | 링크 |
| OpenAPI → TS 계약 생성 경로·도구 선택 | [`architecture/api-contract.md`](architecture/api-contract.md) | 링크 |
| 테스트 티어(T1~T4)·라벨 규약·가드의 정의 | [`coding/test-as-specification.md`](coding/test-as-specification.md) | 링크 |
| 기능의 범위와 **최소 보장** | `features/<슬러그>/README.md` | 링크 |
| 무엇을 어떤 순서로 만드는가 | [`BUILD-PLAN.md`](BUILD-PLAN.md) | 링크 |
| **실제로 실행되는 가드 설정** | `lefthook.yml` · `.github/workflows/ci.yml` (코드) | 설명만. **YAML 을 문서에 복붙하지 않는다** |
| 이 데모가 풀지 못한 것 | [`BUILD-PLAN.md` §한계](BUILD-PLAN.md) | 자기 주제에 한정된 한 줄 + 링크 |

마지막 두 줄이 이 저장소가 실제로 어겼던 규칙이다. lefthook 설정이 두 문서에 서로 다른 형태로 적혀 있었고, "한계" 가 세 문서에 세 벌 있었다.

---

## 2. 문서 상태 (frontmatter)

`features/**/README.md` 와 계획 문서([`BUILD-PLAN.md`](BUILD-PLAN.md))는 첫 줄 frontmatter 에 `status` 를 갖는다. AGENTS.md §5 의 정지선이 이 값을 읽는다. 단 `FeatureCoverageTest` 의 커버리지 하한 검사가 읽는 것은 **기능 문서의 status 뿐**이다.

```yaml
---
status: planned
---
```

| status | 뜻 | AI 가 해도 되는 것 |
|---|---|---|
| `planned` | 범위·최소 보장은 정했고 구현 전 | 구현 착수 가능. 문서의 최소 보장 목록을 근거로 삼는다 |
| `in-progress` | 구현 중 | 위와 같음. 커버리지 하한 가드의 대상 |
| `done` | 최소 보장이 **전부** 테스트로 고정되어 초록 | 변경 시 "바뀐 보장" 을 테스트로 표현한다 |
| `blocked` | 결정 대기 | **구현 금지.** 무엇을 누구에게 물어야 하는지가 문서에 적혀 있다 |
| `superseded` | 폐기 | 구현 근거로 쓰지 않는다. 대체 문서 링크가 문서 안에 있어야 한다 |

`done` 의 조건은 하나다: **문서의 최소 보장 각 줄에 대응하는 테스트가 실재하고 초록.**

> 구현이 0인데 `done` 을 적지 않는다. 이 저장소는 실제로 한동안 그 상태였다 — 기능 문서가 `done` 인데 코드가 한 줄도 없었다. 그것이 "가드 없는 문서는 낡으면 거짓말이 된다" 의 실례이고, 그래서 상태는 **사람이 리뷰하는 값이 아니라 가드가 읽는 값**이다.

---

## 3. 기능 슬러그 — 문서와 테스트를 잇는 좌표

슬러그 하나가 네 자리에서 같은 문자열로 나타난다.

```text
docs/features/<슬러그>/README.md          문서
apps/backend   #[Feature('<슬러그>')]      백엔드 테스트 라벨
apps/frontend  src/features/<슬러그>/      프론트 폴더
apps/frontend  describe('... [<슬러그>]')  프론트 테스트 라벨
```

- kebab-case 명사구. 화면 이름이 아니라 **기능 이름**이다.
- `FeatureCoverageTest`(T4) 가 슬러그의 실재성과 커버리지 하한을 검사한다 — 자세한 건 [`coding/test-as-specification.md`](coding/test-as-specification.md).

현재 슬러그: `equipment-reservation` · `equipment-catalog`

---

## 4. 문서를 고치는 시점

- **보장이 바뀌면 같은 커밋에서 기능 문서를 고친다.** 코드만 바뀌고 문서가 남는 커밋을 만들지 않는다.
- 정책이 바뀌면 `AGENTS.md` 의 Version·Last Updated·변경 이력을 함께 갱신한다.
- 문서만 고치는 커밋은 허용한다(설명 보완). 그 반대 — 보장이 바뀌었는데 문서가 그대로인 커밋 — 이 금지 대상이다.
