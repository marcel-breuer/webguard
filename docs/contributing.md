# Contributing

Contributions are welcome. Please keep changes focused, tested, and easy to review.

## Workflow

1. Fork the repository.
2. Create or select a GitHub issue for the feature or bug fix, then create a branch for it.

   ```bash
   git checkout -b feature-or-bugfix-name
   ```

3. Make the change and add tests for the behavior.
4. Follow the [test concept](test-concept.md) and run the relevant test suite.
5. Apply the [UI quality checklist](ui-quality-checklist.md) to user-facing changes.

   ```bash
   php artisan test
   ```

   Pull requests run three independent required gates: quality checks, the full
   Pest suite without coverage instrumentation, and a conservative Docker
   topology smoke check. Coverage runs on the daily schedule and on demand;
   its report is retained as a workflow artifact.

   Rector and Pint are check-only in CI. Apply their corrections locally before
   pushing:

   ```bash
   ./vendor/bin/rector process
   ./vendor/bin/pint --parallel
   ```

   After CI pipeline changes, review the latest 20 successful pull-request
   runs in GitHub Actions. The target is a median duration of at most four
   minutes and a P95 of at most six minutes; record any deviation, its cause,
   and a follow-up issue in the related pull request.

5. Commit with a descriptive Conventional Commits message.
6. Push the branch to your fork.
7. Open a pull request against the original repository's `main` branch. Reference the issue in the pull request description and use a closing keyword such as `Closes #123` when the pull request fully resolves it.

## Expectations

- Write tests for new behavior and regressions.
- Keep existing tests passing.
- Prefer small pull requests with clear scope.
- Document user-facing behavior changes in the relevant docs file.

## Static analysis

PHPStan with Larastan runs at level 8 over `app`, `config`, and `routes` (`composer analyse`). CI fails the quality job on any error.

Legacy findings that predate level 8 are recorded in `phpstan-baseline.neon`, which `phpstan.neon` includes. The baseline policy is:

- New and changed code must pass without adding baseline entries. Fix the type, docblock, or null-safety issue instead of ignoring it.
- Do not add inline `@phpstan-ignore` comments or `ignoreErrors` rules to bypass a finding.
- Regenerate the baseline only to remove entries after fixing them: run `composer analyse -- --generate-baseline=phpstan-baseline.neon` and confirm the diff only deletes entries or lowers `count` values.
- Fixing code that is covered by baseline entries should remove those entries in the same pull request. PHPStan reports entries that no longer match.
