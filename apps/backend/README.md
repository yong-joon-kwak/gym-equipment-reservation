# apps/backend — 개발 환경

> Symfony API 의 **의존성 · composer 스크립트 · 정적 분석 · 환경 변수** 를 정한다.
>
> 되풀이하지 않는 것: 계층·판정 객체·동시성 → [`docs/architecture/backend.md`](../../docs/architecture/backend.md) · 물리 매핑 → [`docs/architecture/persistence.md`](../../docs/architecture/persistence.md) · HTTP 계약 → [`docs/architecture/api-contract.md`](../../docs/architecture/api-contract.md) · 테스트 티어와 배치 → [`docs/coding/test-as-specification.md`](../../docs/coding/test-as-specification.md) · 만드는 순서와 로컬 MariaDB → [`docs/BUILD-PLAN.md`](../../docs/BUILD-PLAN.md)
>
> 스택: **PHP 8.4 · Symfony 7.4(LTS) · Doctrine ORM 3 · MariaDB 12.3**

---

## 1. 의존성

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

정확한 버전은 설치 시점의 안정판으로 고정하고 `composer.lock` 을 커밋한다. **twig·mailer 등 화면 번들은 넣지 않는다** — 이 앱은 API 하나다. security 는 세션 로그인에만 쓴다([`backend.md` §5](../../docs/architecture/backend.md)).

---

## 2. composer 스크립트 (루트가 부르는 이름)

루트 `package.json` 이 `composer -d apps/backend run <이름>` 으로 부른다. **이름이 계약이다.**

| 이름 | 내용 |
|---|---|
| `stan` | `phpstan analyse` (level max) |
| `test` | `phpunit` — 전체 |
| `test:fast` | `phpunit --group unit --group collaboration --group structure` — DB 불필요 |
| `test:testdox` | `phpunit --testdox` — 사람이 읽는 명세 출력 |
| `api:spec` | `bin/console nelmio:apidoc:dump --format=json > openapi/openapi.json` (5단계) |

`lefthook.yml` 과 `.github/workflows/ci.yml` 이 이 이름들에 매여 있다. 이름을 바꾸면 `composer.json` 까지 세 곳을 함께 고친다.

---

## 3. 정적 분석

`phpstan.neon.dist` — `level: max`, 대상은 `src` 와 `tests`. 베이스라인(`phpstan-baseline.neon`)을 만들지 않는다. **0에서 시작하는 저장소에 베이스라인을 두면 첫 커밋부터 예외 목록이 생긴다.**

---

## 4. 환경 변수

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

## 변경 이력

| 날짜 | 변경 | 근거 |
|---|---|---|
| 2026-09-19 | `docs/architecture/backend.md` §6.1~§6.3·§6.5 를 옮겨 신설 | 의존성·스크립트·환경 변수는 아키텍처가 아니라 개발 환경 설정이다. 설치하는 사람이 코드 옆에서 바로 찾게 한다 |
