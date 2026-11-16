<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Api\OpenApiSpecGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Generate the OpenAPI contract for the token-accessible REST API')]
#[Signature('openapi:generate {--check : Fail instead of writing when the committed spec is out of date}')]
final class GenerateOpenApiSpecCommand extends Command
{
    public function handle(OpenApiSpecGenerator $openApiSpecGenerator): int
    {
        $json = json_encode($openApiSpecGenerator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = base_path(OpenApiSpecGenerator::SPEC_PATH);

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $json) {
                $this->error(OpenApiSpecGenerator::SPEC_PATH . ' is out of date. Run `php artisan openapi:generate`.');

                return self::FAILURE;
            }

            $this->info('OpenAPI spec is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->info('Wrote ' . OpenApiSpecGenerator::SPEC_PATH);

        return self::SUCCESS;
    }
}
