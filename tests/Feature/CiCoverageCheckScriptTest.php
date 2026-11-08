<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CiCoverageCheckScriptTest extends TestCase
{
    private string $repositoryPath;

    protected function tearDown(): void
    {
        if (isset($this->repositoryPath) && is_dir($this->repositoryPath)) {
            $this->removeDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_patch_coverage_passes_when_changed_lines_meet_the_minimum(): void
    {
        $this->prepareRepository();
        $base = $this->currentRevision();
        $this->commitFile('app/Service.php', "<?php\n\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n\$e = 5;\n", 'feat: add statements');
        $this->commitFile('docs.md', "notes\n", 'docs: outside the checked path');
        $this->writeClover(['app/Service.php' => [3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 0], 'app/Untouched.php' => [10 => 0]]);

        $process = $this->runScript(['--base=' . $base, '--total-min=50', '--patch-min=80']);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('| Total | 4 | 6 | 66.66 % | 50.00 % |', $process->getOutput());
        $this->assertStringContainsString('| Changed lines in `app/` | 4 | 5 | 80.00 % | 80.00 % |', $process->getOutput());
        $this->assertStringContainsString('- `app/Service.php`: 7', $process->getOutput());
        $this->assertStringContainsString('Coverage thresholds passed.', (string) file_get_contents($this->repositoryPath . '/summary.md'));
    }

    public function test_patch_coverage_fails_and_lists_uncovered_changed_lines(): void
    {
        $this->prepareRepository();
        $base = $this->currentRevision();
        $this->commitFile('app/Service.php', "<?php\n\n\$a = 1;\n\$b = 2;\n\$c = 3;\n", 'feat: add statements');
        $this->commitFile('app/Other.php', "<?php\n\n\$x = 1;\n", 'feat: add file');
        $this->writeClover(['app/Service.php' => [3 => 1, 4 => 0, 5 => 0], 'app/Other.php' => [3 => 0]]);

        $process = $this->runScript(['--base=' . $base, '--patch-min=80']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('| Changed lines in `app/` | 1 | 4 | 25.00 % | 80.00 % |', $process->getOutput());
        $this->assertStringContainsString('- `app/Other.php`: 3', $process->getOutput());
        $this->assertStringContainsString('- `app/Service.php`: 4-5', $process->getOutput());
        $this->assertStringContainsString('Patch coverage 25.00 % is below the minimum of 80.00 %.', $process->getOutput());
    }

    public function test_total_coverage_below_minimum_fails_without_a_base_revision(): void
    {
        $this->prepareRepository();
        $this->writeClover(['app/Service.php' => [3 => 1, 4 => 0]]);

        $process = $this->runScript(['--total-min=85', '--patch-min=80']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString('Changed lines', $process->getOutput());
        $this->assertStringContainsString('Total coverage 50.00 % is below the minimum of 85.00 %.', $process->getOutput());
    }

    public function test_changes_without_executable_lines_do_not_fail_the_patch_check(): void
    {
        $this->prepareRepository();
        $base = $this->currentRevision();
        $this->commitFile('app/Service.php', "<?php\n\n// documented\nreturn 1;\n", 'docs: comment');
        $this->writeClover(['app/Service.php' => [4 => 1]]);

        $process = $this->runScript(['--base=' . $base, '--patch-min=80']);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString('n/a (no executable changes)', $process->getOutput());
    }

    public function test_missing_clover_report_fails_with_a_summary(): void
    {
        $this->prepareRepository();
        $process = $this->runScript(['--patch-min=80'], 'missing.xml');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Coverage report `missing.xml` was not found', (string) file_get_contents($this->repositoryPath . '/summary.md'));
    }

    public function test_coverage_workflow_enforces_thresholds_on_pull_requests(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/coverage.yml'));
        $composerConfig = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $steps = collect($workflow['jobs']['coverage']['steps'] ?? []);
        $checkStep = $steps->firstWhere('name', 'Check total and patch coverage');
        $checkoutStep = $steps->firstWhere('name', 'Checkout code');

        $this->assertSame(['main'], $workflow['on']['pull_request']['branches'] ?? null);
        $this->assertSame('80', $workflow['env']['COVERAGE_PATCH_MIN'] ?? null);
        $this->assertSame(
            ['./vendor/bin/pest --coverage --min=' . ($workflow['env']['COVERAGE_TOTAL_MIN'] ?? '')],
            $composerConfig['scripts']['test:coverage'] ?? null,
        );
        $this->assertSame(0, $checkoutStep['with']['fetch-depth'] ?? null);
        $this->assertIsArray($checkStep);
        $this->assertSame('always()', $checkStep['if'] ?? null);
        $this->assertStringContainsString('.github/scripts/check-coverage.php', $checkStep['run'] ?? '');
        $this->assertStringContainsString('--patch-min="$COVERAGE_PATCH_MIN"', $checkStep['run'] ?? '');
        $this->assertStringContainsString('--total-min="$COVERAGE_TOTAL_MIN"', $checkStep['run'] ?? '');
        $this->assertStringContainsString('$GITHUB_STEP_SUMMARY', $checkStep['run'] ?? '');
    }

    private function prepareRepository(): void
    {
        if (! $this->gitIsAvailable()) {
            $this->markTestSkipped('The git binary is required for patch coverage tests.');
        }

        $this->repositoryPath = sys_get_temp_dir() . '/webguard-coverage-' . bin2hex(random_bytes(6));
        mkdir($this->repositoryPath . '/app', 0777, true);

        $this->runCommand(['git', 'init', '-b', 'main']);
        $this->runCommand(['git', 'config', 'user.email', 'tests@example.test']);
        $this->runCommand(['git', 'config', 'user.name', 'WebGuard Tests']);
        $this->commitFile('app/Service.php', "<?php\n\nreturn 1;\n", 'chore: initial');
    }

    /**
     * @param  array<string, array<int, int>>  $files
     */
    private function writeClover(array $files): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage><project>';

        foreach ($files as $path => $lines) {
            $xml .= '<file name="/var/www/html/' . $path . '"><line num="1" type="method" name="handle" count="0"/>';

            foreach ($lines as $number => $count) {
                $xml .= '<line num="' . $number . '" type="stmt" count="' . $count . '"/>';
            }

            $xml .= '</file>';
        }

        file_put_contents($this->repositoryPath . '/coverage.xml', $xml . '</project></coverage>');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runScript(array $arguments, string $clover = 'coverage.xml'): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('.github/scripts/check-coverage.php'),
            '--clover=' . $clover,
            '--path=app',
            '--summary=' . $this->repositoryPath . '/summary.md',
            ...$arguments,
        ], $this->repositoryPath);

        $process->run();

        return $process;
    }

    private function currentRevision(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->repositoryPath);
        $process->mustRun();

        return mb_trim($process->getOutput());
    }

    private function commitFile(string $file, string $contents, string $message): void
    {
        file_put_contents($this->repositoryPath . '/' . $file, $contents);

        $this->runCommand(['git', 'add', $file]);
        $this->runCommand(['git', 'commit', '-m', $message]);
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command): void
    {
        $process = new Process($command, $this->repositoryPath);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    private function gitIsAvailable(): bool
    {
        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            File::delete($path);
        }

        rmdir($directory);
    }
}
