<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class TagChangelogGenerationTest extends TestCase
{
    private string $repositoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->gitIsAvailable()) {
            $this->markTestSkipped('The git binary is required for changelog generation tests.');
        }

        $this->repositoryPath = sys_get_temp_dir() . '/webguard-changelog-' . bin2hex(random_bytes(6));
        mkdir($this->repositoryPath);

        $this->runCommand(['git', 'init', '-b', 'main']);
        $this->runCommand(['git', 'config', 'user.email', 'tests@example.test']);
        $this->runCommand(['git', 'config', 'user.name', 'WebGuard Tests']);
    }

    protected function tearDown(): void
    {
        if (isset($this->repositoryPath) && is_dir($this->repositoryPath)) {
            $this->removeDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_changelog_is_generated_from_conventional_commits_and_merged_branches(): void
    {
        $script = file_get_contents(base_path('.github/scripts/generate-changelog.php'));

        $this->assertIsString($script);
        $this->assertStringNotContainsString('mb_', $script);

        $this->commitFile('README.md', 'initial', 'chore: initial release');
        $this->runCommand(['git', 'tag', 'v1.0.0']);

        $this->runCommand(['git', 'checkout', '-b', 'feature/status-components']);
        $this->commitFile('status.txt', 'components', 'feat(status): add component status pages');

        $this->runCommand(['git', 'checkout', 'main']);
        $this->runCommand(['git', 'merge', '--no-ff', 'feature/status-components', '-m', 'Merge pull request #12 from example/feature/status-components']);
        $this->commitFile('alerts.txt', 'retry', 'fix(alerts): retry failed delivery');
        $this->runCommand(['git', 'tag', 'v1.1.0']);

        $notesPath = $this->repositoryPath . '/release-notes.md';
        $process = new Process([
            PHP_BINARY,
            base_path('.github/scripts/generate-changelog.php'),
            '--tag=v1.1.0',
            '--repository=example/webguard',
            '--output=' . $notesPath,
        ], $this->repositoryPath);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $notes = file_get_contents($notesPath);

        $this->assertIsString($notes);
        $this->assertStringContainsString('## v1.1.0 - ', $notes);
        $this->assertStringContainsString('Generated from Conventional Commits and merged branch metadata for `v1.0.0...v1.1.0`.', $notes);
        $this->assertStringContainsString('### Features', $notes);
        $this->assertStringContainsString('**status:** add component status pages', $notes);
        $this->assertStringContainsString('### Fixes', $notes);
        $this->assertStringContainsString('**alerts:** retry failed delivery', $notes);
        $this->assertStringContainsString('### Merged Branches', $notes);
        $this->assertStringContainsString('`example/feature/status-components` via [#12](https://github.com/example/webguard/pull/12)', $notes);
    }

    public function test_tag_workflow_publishes_new_and_historical_changelogs(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/tag.yml'));

        $this->assertArrayHasKey('workflow_dispatch', $workflow['on']);
        $this->assertSame(['main'], $workflow['on']['workflow_run']['branches']);
        $this->assertSame(['completed'], $workflow['on']['workflow_run']['types']);
        $this->assertSame('Create Tag', $workflow['jobs']['create-tag']['name']);
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $workflow['jobs']['create-tag']['if']);
        $this->assertStringContainsString("github.event.workflow_run.event == 'push'", $workflow['jobs']['create-tag']['if']);
        $this->assertStringContainsString("github.event.workflow_run.head_branch == 'main'", $workflow['jobs']['create-tag']['if']);
        $this->assertSame('${{ steps.tag_version.outputs.new_tag }}', $workflow['jobs']['create-tag']['outputs']['new_tag']);

        $createCheckout = collect($workflow['jobs']['create-tag']['steps'])->firstWhere('uses', 'actions/checkout@v6');

        $this->assertIsArray($createCheckout);
        $this->assertSame('${{ github.event.workflow_run.head_sha }}', $createCheckout['with']['ref']);

        $publishSteps = $workflow['jobs']['publish-changelog']['steps'];
        $publishCheckout = collect($publishSteps)->firstWhere('uses', 'actions/checkout@v6');
        $fetchTags = collect($publishSteps)->firstWhere('name', 'Fetch release tags');
        $publishScript = collect($publishSteps)->firstWhere('name', 'Generate changelog');
        $publishRelease = collect($publishSteps)->firstWhere('name', 'Publish GitHub release notes');

        $this->assertIsArray($publishCheckout);
        $this->assertSame('${{ github.event.workflow_run.head_sha }}', $publishCheckout['with']['ref']);
        $this->assertIsArray($fetchTags);
        $this->assertSame('git fetch --force --tags origin', $fetchTags['run']);
        $this->assertIsArray($publishScript);
        $this->assertStringContainsString('.github/scripts/generate-changelog.php', $publishScript['run']);
        $this->assertStringContainsString('needs.create-tag.outputs.new_tag', $publishScript['run']);
        $this->assertIsArray($publishRelease);
        $this->assertStringContainsString('gh release create', $publishRelease['run']);
        $this->assertStringContainsString('gh release edit', $publishRelease['run']);

        $backfillSteps = $workflow['jobs']['backfill-changelogs']['steps'];
        $resolveTags = collect($backfillSteps)->firstWhere('name', 'Resolve tags');
        $backfillRelease = collect($backfillSteps)->firstWhere('name', 'Publish release notes for resolved tags');

        $this->assertIsArray($resolveTags);
        $this->assertStringContainsString('git tag --sort=version:refname', $resolveTags['run']);
        $this->assertIsArray($backfillRelease);
        $this->assertStringContainsString('done < tags.txt', $backfillRelease['run']);
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
        $items = scandir($directory);

        $this->assertIsArray($items);

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }
            if ($item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            File::delete($path);
        }

        rmdir($directory);
    }
}
