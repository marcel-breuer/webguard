<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CiWorkflowRedisExtensionTest extends TestCase
{
    public function test_ci_image_installs_required_redis_extension(): void
    {
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('ext-redis', $composerConfig['require']);

        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $ciStage = mb_substr($dockerfile, (int) mb_strpos($dockerfile, 'AS ci'));
        $installLine = collect(explode("\n", $ciStage))->first(fn (string $line): bool => str_contains($line, 'install-php-extensions'));

        $this->assertIsString($installLine);
        $this->assertStringContainsString('redis', $installLine);
    }

    public function test_ci_image_includes_bun_for_pint_blade_formatting(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $ciStage = mb_substr($dockerfile, (int) mb_strpos($dockerfile, 'AS ci'));

        $this->assertStringContainsString(
            'COPY --from=oven/bun:1.4.5 /usr/local/bin/bun /usr/local/bin/bun',
            $ciStage,
        );
    }

    public function test_quality_job_was_removed_from_ci(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

        $this->assertArrayNotHasKey('quality', $workflowConfig['jobs'] ?? []);
        $this->assertArrayHasKey('tests', $workflowConfig['jobs'] ?? []);
        $this->assertArrayHasKey('browser', $workflowConfig['jobs'] ?? []);
    }

    public function test_ci_separates_quality_tests_and_coverage_workflows(): void
    {
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('^3.9', $composerConfig['require-dev']['larastan/larastan'] ?? null);
        $this->assertSame('^2.2', $composerConfig['require-dev']['phpstan/phpstan'] ?? null);
        $this->assertSame(['./vendor/bin/phpstan analyse --ansi --memory-limit=1G'], $composerConfig['scripts']['analyse'] ?? null);
        $this->assertSame(['./vendor/bin/pest --coverage --min=85'], $composerConfig['scripts']['test:coverage'] ?? null);
        $this->assertFileExists(base_path('phpstan.neon'));

        $ciConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $testSteps = collect($ciConfig['jobs']['tests']['steps'] ?? []);
        $testStep = $testSteps->firstWhere('name', 'Run full Pest suite');

        $this->assertIsArray($testStep);
        $this->assertStringContainsString('composer test -- --parallel', $testStep['run'] ?? '');
        $this->assertSame('off', $testStep['env']['XDEBUG_MODE'] ?? null);
        $this->assertFileDoesNotExist(base_path('.github/workflows/coverage.yml'));
    }

    public function test_ci_avoids_redundant_push_runs_and_skipped_topology_runners(): void
    {
        $ciConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

        $this->assertSame(['main'], $ciConfig['on']['push']['branches'] ?? null);
        $this->assertSame("needs.changes.outputs.topology == 'true'", $ciConfig['jobs']['topology']['if'] ?? null);
        $this->assertFileDoesNotExist(base_path('.github/workflows/coverage.yml'));
    }

    public function test_openapi_contract_check_was_removed_with_the_quality_job(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringNotContainsString('scribe:generate', $workflow);
        $this->assertStringNotContainsString('Verify external OpenAPI contract is current', $workflow);
    }

    public function test_tests_job_builds_docker_image_before_running_pest(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $stepNames = collect($workflowConfig['jobs']['tests']['steps'] ?? [])->pluck('name')->filter()->values();

        $buildIndex = $stepNames->search('Build CI image');
        $pestIndex = $stepNames->search('Run full Pest suite');

        $this->assertNotFalse($buildIndex);
        $this->assertNotFalse($pestIndex);
        $this->assertTrue($buildIndex < $pestIndex);
    }

    public function test_topology_scope_is_conservative_and_ci_never_commits_formatting_changes(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $scopeScript = (string) file_get_contents(base_path('.github/scripts/should-run-topology-smoke.sh'));

        $this->assertStringContainsString('needs: changes', $workflow);
        $this->assertStringContainsString('should-run-topology-smoke.sh', $workflow);
        $this->assertStringNotContainsString('git-auto-commit-action', $workflow);
        $this->assertStringContainsString('routes/*', $scopeScript);
        $this->assertStringContainsString('app/Http/*', $scopeScript);
        $this->assertStringContainsString('frontend/src/*', $scopeScript);
    }

    public function test_quality_job_installs_pint_formatter_dependencies_from_the_locked_workspace(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $packageConfig = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Install Bun workspace dependencies', $workflow);
        $this->assertStringContainsString('bun install --frozen-lockfile', $workflow);
        $this->assertArrayHasKey('prettier', $packageConfig['devDependencies'] ?? []);
        $this->assertArrayHasKey('prettier-plugin-blade', $packageConfig['devDependencies'] ?? []);
        $this->assertArrayHasKey('prettier-plugin-tailwindcss', $packageConfig['devDependencies'] ?? []);
        $this->assertSame('1.65.1', $packageConfig['devDependencies']['playwright'] ?? null);
    }

    public function test_topology_job_installs_its_locked_playwright_dependency(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('Install topology smoke dependencies', $workflow);
        $this->assertStringContainsString('bun install --frozen-lockfile', $workflow);
    }

    public function test_captcha_uses_the_supported_intervention_image_v4_dependency(): void
    {
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $composerLock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
        $packages = collect($composerLock['packages'] ?? [])->keyBy('name');

        $this->assertSame('^4.3', $composerConfig['require']['intervention/image'] ?? null);
        $this->assertTrue($packages->has('intervention/image'));
        $interventionImageVersion = mb_ltrim((string) $packages->get('intervention/image')['version'], 'v');

        $this->assertTrue(
            version_compare($interventionImageVersion, '4.0.0', '>='),
            'The locked mews/captcha dependency must remain compatible with Intervention Image v4.'
        );
        $this->assertTrue($packages->has('mews/captcha'));
    }

    public function test_dependabot_groups_supported_version_updates_and_assigns_prs(): void
    {
        $dependabotConfig = Yaml::parseFile(base_path('.github/dependabot.yml'));

        $this->assertSame(2, $dependabotConfig['version'] ?? null);
        $this->assertSame([
            'all-dependencies' => [
                'schedule' => [
                    'interval' => 'monthly',
                    'time' => '00:00',
                    'timezone' => 'Europe/Berlin',
                ],
                'assignees' => ['marcel-breuer'],
            ],
        ], $dependabotConfig['multi-ecosystem-groups'] ?? null);

        $this->assertSame([
            [
                'package-ecosystem' => 'composer',
                'directory' => '/',
                'directories' => null,
            ],
            [
                'package-ecosystem' => 'bun',
                'directory' => null,
                'directories' => ['/', '/frontend'],
            ],
            [
                'package-ecosystem' => 'github-actions',
                'directory' => '/',
                'directories' => null,
            ],
            [
                'package-ecosystem' => 'docker',
                'directory' => null,
                'directories' => ['/', '/docker/gateway'],
            ],
            [
                'package-ecosystem' => 'docker-compose',
                'directory' => '/',
                'directories' => null,
            ],
        ], array_map(
            static fn (array $update): array => [
                'package-ecosystem' => $update['package-ecosystem'],
                'directory' => $update['directory'] ?? null,
                'directories' => $update['directories'] ?? null,
            ],
            $dependabotConfig['updates'] ?? []
        ));

        foreach ($dependabotConfig['updates'] ?? [] as $update) {
            $this->assertSame(['*'], $update['patterns'] ?? null);
            $this->assertSame('all-dependencies', $update['multi-ecosystem-group'] ?? null);
            $this->assertSame(1, $update['open-pull-requests-limit'] ?? null);
            $this->assertSame(['marcel-breuer'], $update['assignees'] ?? null);
            $this->assertSame([
                'applies-to' => 'security-updates',
                'patterns' => ['*'],
            ], $update['groups']['security-updates'] ?? null);
        }

        $this->assertFileDoesNotExist(base_path('.github/workflows/weekly-dependency-update.yml'));
    }
}
