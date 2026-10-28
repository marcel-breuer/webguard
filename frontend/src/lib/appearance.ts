import type { AppearanceTheme } from "#lib/api/models.ts";

export const appearanceStorageKey = "webguard.appearance";

export function isAppearanceTheme(value: string | null): value is AppearanceTheme {
    return value === "light" || value === "dark" || value === "system";
}

export function storedAppearanceTheme(): AppearanceTheme {
    const value = localStorage.getItem(appearanceStorageKey);

    return isAppearanceTheme(value) ? value : "system";
}

export function applyAppearanceTheme(theme: AppearanceTheme): void {
    const dark = theme === "dark" || (theme === "system" && window.matchMedia("(prefers-color-scheme: dark)").matches);

    document.documentElement.dataset.theme = theme;
    document.documentElement.classList.toggle("dark", dark);
    localStorage.setItem(appearanceStorageKey, theme);
}
