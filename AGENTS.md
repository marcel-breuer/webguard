# Repository Agent Instructions

These instructions apply to every human or AI coding agent working in this repository, regardless of the tool, model, IDE, extension, CLI, automation platform, or execution environment.

## Scope and Applicability

- This root `AGENTS.md` is the canonical source for repository-wide rules.
- Local `AGENTS.md` files MAY add directory-specific implementation rules. The nearest applicable `AGENTS.md` takes precedence for local details.
- Local rules MUST NOT weaken security, privacy, compliance, or reviewability requirements in this file.
- Agent-specific files, prompts, IDE rules, and automation adapters MUST be thin references to this file, local `AGENTS.md` files, and `.agent/skills/`.
- Shared skills in `.agent/skills/` supplement these rules and MUST NOT contradict them.

## Instruction Priority

Apply instructions in this order:

1. Explicit task requirements and acceptance criteria.
2. Security, privacy, legal, and compliance requirements.
3. Nearest applicable local `AGENTS.md`.
4. This root `AGENTS.md`.
5. Existing architecture and established repository patterns.
6. Repository configuration.
7. Tests and technical documentation.
8. Official language and framework documentation.
9. Established community standards.
10. Explicitly documented assumptions.

Agents MUST NOT invent business requirements. When instructions conflict, report the conflict and apply the stricter or safer rule.

## Project Overview

WebGuard Core is the Laravel Management Core & API for WebGuard. It owns the dashboard, admin panel, public status pages, REST API, monitoring orchestration, notification workflows, and package administration. Distributed scanning nodes live in the separate `webguard-instance` repository; do not implement scanning-node behavior here unless the core API contract or integration surface requires it.

Primary stack:

- Backend: PHP 8.5+, Laravel 13, Laravel Sanctum, Socialite, Scribe, and Spatie activity log.
- Frontend: SvelteKit, Tailwind CSS, TypeScript, and Chart.js.
- Runtime services: MySQL-compatible database, Redis cache and queues, SMTP mail.
- Tests: Pest, PHPUnit config, Pest Browser Plugin, SQLite in-memory defaults.
- Tooling: Composer, Bun, Larastan/PHPStan, Laravel Pint, Docker Compose.

Important areas:

- `app/`: Laravel controllers, requests, models, services, jobs, mail, observers, support classes, DTO-style data classes, enums, and console commands.
- `routes/`: web, auth, console, and API route definitions, including internal and external API segments.
- `frontend/`: SvelteKit routes, shared UI components, Tailwind CSS, and browser assets.
- `resources/`: Laravel translations and server-rendered mail templates.
- `database/`: migrations, factories, and seeders.
- `tests/`: unit, feature, and browser tests.
- `docker/`, `Dockerfile`, `docker-compose.yml`, `docker-compose.override.yml`, `start-dev.sh`: container build and runtime configuration.
- `.github/workflows/`: CI, tag release notes, and dependency update workflows.
- `docs/`: installation, architecture, testing, features, and contribution documentation.

## Source of Truth

Use this technical priority:

1. Existing implementation and established patterns.
2. Project configuration.
3. Tests.
4. Repository documentation.
5. Official Laravel, PHP, Pest, SvelteKit, Tailwind, TypeScript, and related package documentation.
6. Established standards.

Agents MUST NOT replace existing conventions with personal preferences without a concrete technical reason.

## Token-Efficient Work

- Read only files relevant to the current task.
- Do not scan the full repository when targeted inspection is sufficient.
- Prefer precise searches over broad file reads.
- Avoid repeatedly reading unchanged files.
- Do not restate the complete task before starting.
- Do not repeat rules already defined here.
- Keep plans concise and focused on execution-critical steps.
- Do not narrate routine tool usage.
- Report only findings that affect implementation, validation, risk, or review.
- Prefer diffs and targeted edits over rewriting complete files.
- Avoid creating abstractions, documentation, comments, tests, or files that are not required.
- Do not produce large code excerpts in final responses when file references are sufficient.
- Do not duplicate the same information across summaries, findings, and completion reports.
- Use concise tables or short lists when they reduce repetition.
- Preserve correctness, security, and completeness; token efficiency MUST NOT justify skipping required analysis or validation.

Final output SHOULD normally contain only what changed, changed files, validations executed, and unresolved issues, assumptions, or risks.

## Language and Framework Standards

- PHP code MUST follow Laravel conventions and the configured Pint rules in `pint.json`.
- PHP files SHOULD use `declare(strict_types=1);`; Pint enforces strict types.
- Use typed properties, parameters, and return types for public interfaces where practical.
- Keep controllers thin. Put reusable business logic in services, support classes, jobs, requests, policies, or DTO-style data classes that match existing patterns.
- Use Form Request classes for reusable validation and authorization logic.
- Use Eloquent models, relationships, casts, scopes, factories, and migrations consistently with existing code.
- Prefer named routes over hard-coded application URLs.
- Use Laravel fakes for mail, notifications, queues, events, cache, and HTTP boundaries in tests.
- Keep TypeScript strict and small. Put reusable frontend behavior in `frontend/src/lib`.
- Keep SvelteKit routes accessible, localized, and consistent with shared components in `frontend/src/lib/components`.
- Do not add external network calls in tests.
- Log operationally useful context without secrets, tokens, credentials, or personal data.

## Architecture Rules

- The core owns management UI, API contracts, orchestration, notifications, aggregation, user/team/package administration, legal pages, and public status output.
- Distributed probe/scanner behavior belongs in `webguard-instance`; this repository may expose or adapt core API contracts for instances.
- Business logic SHOULD live in services, jobs, commands, requests, support classes, enums, or data classes instead of Svelte components or controllers.
- API controllers MUST validate request input, authorize access, and return stable response shapes.
- External providers such as SMTP, Slack, Discord, Teams, Telegram, FCM, APNS, Socialite, and webhooks MUST stay behind existing Laravel configuration or service boundaries.
- Queue and scheduler changes MUST account for the default and `heartbeat` queues.
- New abstractions require a concrete reduction in duplication, complexity, or risk.

## Code Quality

- Run configured formatting, static analysis, tests, and builds relevant to the change.
- Keep functions and methods focused; avoid speculative abstractions.
- Remove dead code, commented-out code, unused imports, and unused variables.
- Use meaningful constants or enums instead of unexplained literals when values represent business rules.
- Comments SHOULD explain why, not restate what the code says.
- Do not introduce unintended public API, route, schema, or behavior changes.
- Identify breaking changes explicitly.
- Do not add broad disable comments, ignore rules, unsafe casts, or weakened checks only to bypass tooling.
- Do not weaken existing quality gates, validation, authentication, authorization, or tests.

## Naming Conventions

- Use existing Laravel naming conventions for controllers, models, requests, jobs, mailables, commands, observers, services, factories, seeders, migrations, routes, and tests.
- PHP classes use PascalCase; methods and variables use camelCase; database tables and columns use snake_case.
- Enums, DTO-style data classes, services, jobs, and commands MUST have domain-specific names.
- Test names and filenames SHOULD describe observable behavior.
- API endpoint names, route names, and translation keys MUST be stable, descriptive, and consistent with surrounding files.
- Frontend component and utility filenames SHOULD follow the existing descriptive TypeScript naming in `frontend/src`.

## Testing Rules

- Follow `docs/test-concept.md`.
- Use `tests/Unit` for services, support classes, DTO-style payload logic, enum helpers, and deterministic calculations.
- Use `tests/Feature` for routes, controllers, requests, middleware, commands, jobs, mail, notifications, database behavior, API contracts, and authorization.
- Use `tests/Browser` for rendered UI, responsive layout, JavaScript interactions, and behavior that feature tests cannot validate.
- New or changed business logic MUST have tests unless the change is documentation-only or a justified exception is reported.
- Bug fixes SHOULD include a regression test that fails before the fix where feasible.
- Tests MUST be deterministic, isolated, and use factories or fakes instead of broad fixtures or live services.
- Do not remove, weaken, skip, or rewrite tests merely to make changes pass.
- Do not update snapshots or generated outputs without verifying the behavioral reason.

## Validation Commands

Run commands inside Docker when technically possible, using:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml ...
```

Verified project commands:

| Purpose | Command |
| --- | --- |
| Start local Docker stack | `./start-dev.sh` |
| Backend tests | `docker compose -f docker-compose.yml -f docker-compose.override.yml exec php composer test` |
| Targeted Pest test | `docker compose -f docker-compose.yml -f docker-compose.override.yml exec php ./vendor/bin/pest tests/Feature/MonitoringExpectedHttpStatusesTest.php` |
| Static analysis | `docker compose -f docker-compose.yml -f docker-compose.override.yml exec php composer analyse` |
| Migrations | `docker compose -f docker-compose.yml -f docker-compose.override.yml exec php php artisan migrate` |
| Frontend install | `docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm node bun install` |
| Frontend build | `docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm node bun run build` |
| One queue job | `docker compose -f docker-compose.yml -f docker-compose.override.yml exec queue-default php artisan queue:work redis --once` |

Database migrations MUST always be executed with Laravel's `php artisan migrate` command inside the Docker `php` service. Agents MUST NOT run migrations with host PHP or a host-side `php artisan` command while Docker is available.

Host commands MAY be used only when Docker is unavailable or a task explicitly requires host execution. State the exception and remaining risk.

Validation matrix:

| Change type | Required validation |
| --- | --- |
| Documentation | Relevant docs review; run documentation structure tests when links or required topics change |
| Backend service/support logic | Targeted unit tests; PHPStan when type or shared logic risk exists |
| Route/controller/request/SvelteKit gateway contract | Targeted feature tests |
| API contract | Targeted API feature tests and compatibility review |
| Job/command/scheduler/notification | Targeted feature tests for scheduling and side effects |
| Database/migration | Migration or compatibility test; use MySQL when SQLite cannot model the risk |
| Frontend/asset/TypeScript | `bun run build`; browser test for interaction or responsive behavior |
| Docker/infrastructure/CI | Syntax/configuration review and the narrowest runnable build or workflow-equivalent check |
| Dependencies | Clean install or update command, lockfile review, relevant tests, and build |

If a command cannot be executed, agents MUST state why.

## Dependency Management

- Composer and `composer.lock` are authoritative for PHP dependencies.
- Bun and `bun.lock` are authoritative for frontend dependencies.
- Add dependencies only when existing framework or repository code cannot reasonably solve the problem.
- Consider security, maintenance, license, package size, runtime impact, and overlap with existing libraries.
- Do not change lockfiles without a corresponding dependency operation.
- Do not perform unrelated upgrades or unrequested major-version updates.
- Keep dependency updates focused and review lockfile changes.

## Security, Privacy, and Compliance

- Do not commit secrets, credentials, tokens, production personal data, logs, or machine-specific files.
- Use `.env` and existing Laravel configuration for secrets and environment-specific values.
- Validate input at trust boundaries and escape output in the correct context.
- Do not bypass authentication, authorization, signed URLs, CSRF, Sanctum, role checks, or ownership checks.
- Use Eloquent/query builder parameter binding; avoid SQL injection and shell injection risks.
- Keep default access restrictive and errors safe for public output.
- Do not log sensitive values, internal tokens, authorization headers, push credentials, webhook secrets, or private keys.
- Do not disable security controls or add telemetry/external services without approval.
- Do not upload repository content or data to external systems without authorization.
- Legal, GDPR, imprint, terms, and privacy pages MUST remain accurate and configuration-driven where existing code requires environment values.

## Database and Migration Rules

- Use Laravel migrations, Eloquent models, casts, factories, and seeders.
- Previously shared or executed migrations MUST NOT be edited unless the repository explicitly requires a corrective migration strategy.
- Prefer new migrations for schema changes.
- Add indexes, constraints, defaults, and backfills deliberately; account for MySQL compatibility and SQLite test limitations.
- Use transactions where the database and migration operation support them.
- Keep seed data non-sensitive and deterministic.
- Update factories and tests when schema changes require it.

## API and Integration Rules

- API changes MUST validate requests, enforce authentication/authorization, and preserve stable response formats unless a breaking change is explicit.
- Internal instance APIs under `routes/api/instance.php` are compatibility-sensitive for `webguard-instance`; see `docs/architecture/api-boundaries.md` and `docs/integrations/webguard-instance-api.md`.
- Public badge/card/status endpoints MUST avoid leaking private monitoring data.
- Use appropriate status codes, pagination, error messages, rate limits, and idempotency patterns consistent with existing API code.
- External notification channels and push integrations MUST handle failures without exposing secrets or blocking unrelated delivery paths.
- Scribe documentation generation exists; update generated API documentation only when intentionally requested or part of the release process.

## Frontend Rules

- Reuse shared Svelte components in `frontend/src/lib/components` before adding new structures.
- Use `$unslop` from `.agents/skills/unslop/SKILL.md` for new UI views, UI redesigns, and focused UI reviews. Audit the current UI before proposing changes; preserve existing branding, design tokens, component libraries, reusable components, and these repository rules. When an audited screen is already good, report only evidence-backed issues and avoid speculative redesigns.
- Keep UI text localized where surrounding views use `resources/lang`.
- Preserve accessible labels, focus states, form errors, responsive behavior, and dark/theme behavior where present.
- Use Tailwind classes consistently with existing Svelte markup.
- Keep Svelte and TypeScript behavior small, typed, and bound to clear component ownership.
- Include loading, empty, validation, and error states for changed user workflows when relevant.
- Do not introduce unnecessary bundle weight.

## Documentation Rules

- Update documentation when setup, Docker operations, deployment, testing expectations, public behavior, API usage, or configuration changes.
- Keep root `README.md` concise and link expanded docs from `docs/`.
- Put setup and Docker operations in `docs/installation.md`.
- Put testing strategy in `docs/test-concept.md`.
- Put contribution workflow in `docs/contributing.md`.
- Do not make unverified claims or manually edit generated documentation.

## Git and Change Scope

- Use the connected GitHub MCP tools for GitHub issues, pull requests, reviews, labels, and other repository metadata whenever the required operation is available there. Use local `git` only for working-tree and branch operations, and use `gh` only for gaps not covered by the GitHub MCP tools.
- Record concrete current or future work as a GitHub issue before implementation. Implementation pull requests MUST reference the relevant issue and SHOULD use a closing keyword such as `Closes #123` when merging the pull request completes that issue.
- Keep changes limited to the task.
- Do not perform unrelated refactors or formatting of untouched files.
- Do not overwrite local changes you did not make.
- Do not use destructive Git commands unless explicitly requested.
- Do not commit, push, tag, release, or open pull requests without explicit instruction.
- Do not modify CI/CD, infrastructure, deployment, or security configuration unless required by the task.
- Keep changes small, reviewable, and easy to revert.
- Do not include AI-assistant, automation-tool, or code-generation branding in code comments, documentation, commit messages, PR text, or branch names unless explicitly required.

## Shared Skills

- Shared skills live under `.agent/skills/<skill-name>/SKILL.md`.
- Agents MUST read only the skills relevant to the current task.
- Skills are tool-independent and repository-specific.
- Skills MUST NOT override this file.
- Agents MUST NOT modify skills unless explicitly requested.
- Skill output must remain concise and token-efficient.

Start with `.agent/skills/README.md` when selecting skills.

## Agent Workflow

1. Read the task and acceptance criteria.
2. Read this file and any nearest local `AGENTS.md`.
3. Identify and read only relevant skills.
4. Inspect relevant files and existing patterns.
5. Evaluate architecture, dependencies, security, and validation risk.
6. Plan the smallest viable change.
7. Implement the change.
8. Add or update tests and documentation when required.
9. Run relevant validation commands.
10. Review the diff for unintended changes.
11. Report changes, validation, assumptions, and remaining risks concisely.

Agents MUST NOT begin implementation before checking applicable rules and skills.

## Definition of Done

A task is complete only when:

- Acceptance criteria are met.
- Architecture rules are followed.
- Relevant tests exist or a justified exception is reported.
- Required validation succeeds or skipped checks are disclosed with risk.
- Security and privacy requirements are met.
- Documentation is updated where required.
- No unintended files changed.
- Assumptions and remaining risks are stated.
