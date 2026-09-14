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
            'COPY --from=oven/bun:1.3.11 /usr/local/bin/bun /usr/local/bin/bun',
            $ciStage,
        );
    }

    public function test_quality_job_caches_composer_downloads_instead_of_vendor_directory(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $cacheStep = collect($workflowConfig['jobs']['quality']['steps'] ?? [])
            ->firstWhere('name', 'Cache Composer dependencies');

        $this->assertIsArray($cacheStep, 'Missing Composer cache step for quality job.');
        $this->assertSame('~/.cache/composer/files', $cacheStep['with']['path'] ?? null);
        $this->assertNotSame('vendor', $cacheStep['with']['path'] ?? null);
    }

    public function test_ci_separates_quality_tests_and_coverage_workflows(): void
    {
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('^3.9', $composerConfig['require-dev']['larastan/larastan'] ?? null);
        $this->assertSame('^2.2', $composerConfig['require-dev']['phpstan/phpstan'] ?? null);
        $this->assertSame(['./vendor/bin/phpstan analyse --ansi --memory-limit=1G'], $composerConfig['scripts']['analyse'] ?? null);
        $this->assertSame(['./vendor/bin/pest --coverage --min=0'], $composerConfig['scripts']['test:coverage'] ?? null);
        $this->assertFileExists(base_path('phpstan.neon'));

        $ciConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $coverageConfig = Yaml::parseFile(base_path('.github/workflows/coverage.yml'));
        $qualitySteps = collect($ciConfig['jobs']['quality']['steps'] ?? []);
        $testSteps = collect($ciConfig['jobs']['tests']['steps'] ?? []);
        $coverageSteps = collect($coverageConfig['jobs']['coverage']['steps'] ?? []);
        $phpstanStep = $qualitySteps->firstWhere('name', 'Run PHPStan');
        $testStep = $testSteps->firstWhere('name', 'Run full Pest suite');
        $coverageStep = $coverageSteps->firstWhere('name', 'Run full Pest suite with coverage');

        $this->assertIsArray($phpstanStep);
        $this->assertStringContainsString('composer analyse', $phpstanStep['run'] ?? '');

        $this->assertIsArray($testStep);
        $this->assertStringContainsString('composer test -- --parallel', $testStep['run'] ?? '');
        $this->assertSame('off', $testStep['env']['XDEBUG_MODE'] ?? null);

        $this->assertIsArray($coverageStep);
        $this->assertStringContainsString('composer test:coverage', $coverageStep['run'] ?? '');
        $this->assertStringContainsString('--parallel', $coverageStep['run'] ?? '');
        $this->assertSame('sqlite', $coverageStep['env']['DB_CONNECTION'] ?? null);
        $this->assertSame(':memory:', $coverageStep['env']['DB_DATABASE'] ?? null);
        $this->assertSame('coverage', $coverageStep['env']['XDEBUG_MODE'] ?? null);
    }

    public function test_ci_verifies_the_generated_external_openapi_contract(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $qualitySteps = collect($workflowConfig['jobs']['quality']['steps'] ?? []);
        $openApiStep = $qualitySteps->firstWhere('name', 'Verify external OpenAPI contract is current');

        $this->assertIsArray($openApiStep);
        $this->assertStringContainsString('php artisan scribe:generate --no-interaction', $openApiStep['run'] ?? '');
        $this->assertStringContainsString(
            'git diff --exit-code -- storage/app/private/scribe/openapi.yaml',
            $openApiStep['run'] ?? '',
        );
        $this->assertSame('sqlite', $openApiStep['env']['DB_CONNECTION'] ?? null);
        $this->assertSame(':memory:', $openApiStep['env']['DB_DATABASE'] ?? null);
    }

    public function test_quality_job_builds_docker_image_before_its_php_quality_steps(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
        $stepNames = collect($workflowConfig['jobs']['quality']['steps'] ?? [])->pluck('name')->filter()->values();

        $buildIndex = $stepNames->search('Build CI image');
        $rectorIndex = $stepNames->search('Check Rector formatting');
        $pintIndex = $stepNames->search('Check Pint formatting');
        $phpstanIndex = $stepNames->search('Run PHPStan');
        $openApiIndex = $stepNames->search('Verify external OpenAPI contract is current');

        $this->assertNotFalse($buildIndex);
        $this->assertNotFalse($rectorIndex);
        $this->assertNotFalse($pintIndex);
        $this->assertNotFalse($phpstanIndex);
        $this->assertNotFalse($openApiIndex);

        $this->assertTrue($buildIndex < $rectorIndex);
        $this->assertTrue($rectorIndex < $pintIndex);
        $this->assertTrue($pintIndex < $phpstanIndex);
        $this->assertTrue($phpstanIndex < $openApiIndex);
    }

    public function test_topology_scope_is_conservative_and_ci_never_commits_formatting_changes(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $scopeScript = (string) file_get_contents(base_path('.github/scripts/should-run-topology-smoke.sh'));

        $this->assertStringContainsString('needs: changes', $workflow);
        $this->assertStringContainsString('should-run-topology-smoke.sh', $workflow);
        $this->assertStringNotContainsString('git-auto-commit-action', $workflow);
        $this->assertStringContainsString('--dry-run --ansi', $workflow);
        $this->assertStringContainsString('--test --parallel', $workflow);
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
        $this->assertSame('1.62.1', $packageConfig['devDependencies']['playwright'] ?? null);
    }

    public function test_topology_job_installs_its_locked_playwright_dependency(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('Install topology smoke dependencies', $workflow);
        $this->assertStringContainsString('bun install --frozen-lockfile', $workflow);
    }

    public function test_captcha_uses_intervention_image_three_until_package_supports_v4(): void
    {
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $composerLock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
        $packages = collect($composerLock['packages'] ?? [])->keyBy('name');

        $this->assertSame('^3.11', $composerConfig['require']['intervention/image'] ?? null);
        $this->assertTrue($packages->has('intervention/image'));
        $interventionImageVersion = mb_ltrim((string) $packages->get('intervention/image')['version'], 'v');

        $this->assertTrue(
            version_compare($interventionImageVersion, '4.0.0', '<'),
            'mews/captcha currently calls Intervention Image v3 APIs such as ImageManager::create().'
        );
        $this->assertTrue($packages->has('mews/captcha'));
    }

    public function test_weekly_dependency_update_caches_composer_downloads_instead_of_vendor_directory(): void
    {
        $workflowConfig = Yaml::parseFile(base_path('.github/workflows/weekly-dependency-update.yml'));
        $cacheStep = collect($workflowConfig['jobs']['update-dependencies']['steps'] ?? [])
            ->firstWhere('name', 'Cache Composer dependencies');

        $this->assertIsArray($cacheStep);
        $this->assertSame('~/.cache/composer/files', $cacheStep['with']['path'] ?? null);
        $this->assertNotSame('vendor', $cacheStep['with']['path'] ?? null);
    }
}
