<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

class FrontendTranslationApiTest extends TestCase
{
    public function test_sveltekit_language_files_have_matching_clustered_keys(): void
    {
        $english = require base_path('resources/lang/en/sveltekit.php');
        $german = require base_path('resources/lang/de/sveltekit.php');

        $this->assertSame(array_keys($english), array_keys($german));

        foreach ($english as $cluster => $messages) {
            $this->assertIsArray($messages, $cluster);
            $this->assertIsArray($german[$cluster] ?? null, $cluster);
            $this->assertSame(array_keys($messages), array_keys($german[$cluster]), $cluster);

            foreach ($messages as $key => $value) {
                $this->assertIsString($key);
                $this->assertIsString($value);
                $this->assertIsString($german[$cluster][$key]);
            }
        }
    }

    public function test_guest_can_load_the_requested_sveltekit_translation_catalog(): void
    {
        $this->getJson(route('translations.index', ['locale' => 'de']))
            ->assertOk()
            ->assertJsonPath('data.locale', 'de')
            ->assertJsonPath('data.fallback_locale', 'en')
            ->assertJsonPath('data.messages.Service operations', 'Betrieb')
            ->assertJsonPath('data.messages.Create monitoring', 'Überwachung erstellen')
            ->assertJsonPath('data.messages.Checked every :value minutes', 'Alle :value Minuten geprüft')
            ->assertJsonPath('data.messages.Scheduled regional maintenance', 'Geplante regionale Wartung')
            ->assertJsonPath('data.messages.Customer-facing services', 'Kundenservices')
            ->assertJsonPath('data.messages.Announcement', 'Ankündigung')
            ->assertJsonPath('data.messages.WebGuard Status', 'WebGuard-Status')
            ->assertJsonPath('data.messages.Service components', 'Dienstkomponenten')
            ->assertJsonPath('data.messages.Heartbeat', 'Heartbeat')
            ->assertJsonMissingPath('data.messages.dynamic');
    }

    public function test_unsupported_locale_falls_back_to_the_english_catalog(): void
    {
        $this->getJson(route('translations.index', ['locale' => 'fr']))
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.messages.Service operations', 'Service operations')
            ->assertJsonPath('data.messages.Create monitoring', 'Create monitoring');
    }
}
