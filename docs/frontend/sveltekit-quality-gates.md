# SvelteKit quality gates

Every pull request that changes the SvelteKit application must satisfy the
following container-backed evidence before it can merge. The CI workflow runs
the deterministic gates; the release owner records the staged browser evidence
for the affected route family.

## Enforced CI evidence

| Gate | Evidence | Threshold |
| --- | --- | --- |
| Type safety | `bun run frontend:check` | No Svelte or TypeScript diagnostics |
| Production output | `bun run frontend:build` | Adapter-node build succeeds |
| JavaScript payload | `bun run --cwd frontend budget` | No immutable JavaScript asset above 200 KiB; total immutable JavaScript at most 1 MiB |
| First-party API budget | Feature-contract tests | Dashboard projection at most 30 queries and 128 KiB response bytes |
| Laravel contracts | Pest feature tests and OpenAPI drift check | Authorization, response shape, and generated external contract remain valid |
| Runtime topology | `.github/scripts/smoke-sveltekit-topology.sh` | Gateway, SvelteKit, Laravel, queue worker, and scheduler start with MySQL and Redis; all readiness endpoints pass |
| Browser route smoke | Chromium in the isolated topology network | Public status SSR renders at desktop and mobile widths with no console errors or horizontal overflow; labelled subscription input accepts keyboard focus |

The topology smoke test runs in Docker with an isolated network, volume set,
application key, and database. It creates only a disposable public status page,
loads it through Chromium over the gateway, and removes all resources on exit.
It never calls external services or production data.

For pull requests, CI allocates a runner for this expensive smoke test whenever
changed paths can affect the runtime or its infrastructure. Documentation,
tests, and isolated domain changes skip the job; pushes to `main` always run
it. When the job runs, its summary records the scope decision.

## Browser, accessibility, and performance evidence

Before a migrated route family is released, capture the following in staging at
1280px and 390px and attach it to the release evidence:

| Measurement | Budget | Baseline | Collection method |
| --- | --- | --- | --- |
| Initial authenticated navigation | at most 1,500 ms | Capture before the first route-family cutover | Browser performance trace with a warm authenticated session |
| Public status SSR response | at most 1,000 ms | Browser smoke baseline in CI | Chromium navigation timing plus `Server-Timing` response header |
| Client-side route transition | at most 300 ms | Capture per migrated workspace | Browser performance trace after first hydration |
| First-party API response | at most 128 KiB and 30 queries for dashboard | Enforced by feature contract | `X-Response-Bytes` and `X-Query-Count` headers |
| Accessibility | No critical or serious automated violations | Capture per migrated route family | Keyboard journey and browser accessibility scan |

The release evidence must include the browser URL family, viewport, locale,
theme, commit SHA, trace or screenshot link, measured value, and reviewer. The
existing [UI quality checklist](../ui-quality-checklist.md) remains mandatory
for keyboard focus, dialogs, localization, responsiveness, and public-data
isolation.

## Exception process

An exception requires a linked GitHub issue, the failing budget, the measured
value, a user impact assessment, an owner, and an expiry date. It is valid for
one release only. The release owner records the exception with the staging
evidence; the follow-up issue must restore the normal threshold before the next
route-family cutover.

## Request correlation

The gateway creates an opaque `X-Request-Id` for every proxied request and logs
it with the upstream address and status. It forwards the value to SvelteKit and
Laravel. SvelteKit forwards the validated value when server-side loads call the
first-party API, and Laravel returns it on internal and external API responses.
The identifier contains no cookie, target, token, or personal data and allows a
browser response to be correlated with gateway access logs and Laravel API
telemetry.
