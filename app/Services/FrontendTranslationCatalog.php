<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SupportedLanguage;

final class FrontendTranslationCatalog
{
    /**
     * @return array{locale: string, fallback_locale: string, messages: array<string, string>}
     */
    public function payload(?string $locale): array
    {
        $language = SupportedLanguage::tryFrom((string) $locale) ?? SupportedLanguage::default();
        $supportedLanguage = SupportedLanguage::default();
        $fallback = $this->load($supportedLanguage);

        return [
            'locale' => $language->value,
            'fallback_locale' => $supportedLanguage->value,
            'messages' => array_replace($fallback, $this->load($language)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function load(SupportedLanguage $supportedLanguage): array
    {
        /** @var mixed $messages */
        $messages = require lang_path($supportedLanguage->value . '/sveltekit.php');

        if (! is_array($messages)) {
            return [];
        }

        $translations = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            foreach ($message as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $translations[$key] = $value;
                }
            }
        }

        return $translations;
    }
}
