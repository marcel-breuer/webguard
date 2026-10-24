export type DateTimeFormatOptions = Intl.DateTimeFormatOptions;

const supportedFormatLocales = new Set(["de-DE", "en-GB", "en-US"]);

export function resolveFormatLocale(preference: string | null | undefined, language: string): string {
    if (typeof preference === "string" && supportedFormatLocales.has(preference)) {
        return preference;
    }

    return language === "de" ? "de-DE" : "en-US";
}

export function formatNumberForLocale(locale: string, value: number, options: Intl.NumberFormatOptions = {}): string {
    return new Intl.NumberFormat(locale, options).format(value);
}

export function formatPercentageForLocale(locale: string, value: number, fractionDigits = 2): string {
    return new Intl.NumberFormat(locale, {
        style: "percent",
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(value / 100);
}

export function formatCurrencyForLocale(
    locale: string,
    value: number,
    currency: string,
    options: Intl.NumberFormatOptions = {},
): string {
    return new Intl.NumberFormat(locale, {
        ...options,
        style: "currency",
        currency,
    }).format(value);
}

export function formatDateTimeForLocale(
    locale: string,
    value: string | null | undefined,
    fallback: string,
    options: DateTimeFormatOptions = { dateStyle: "medium", timeStyle: "short" },
): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? fallback : new Intl.DateTimeFormat(locale, options).format(date);
}

export function formatMonthYearForLocale(locale: string, value: Date): string {
    return new Intl.DateTimeFormat(locale, { month: "long", year: "numeric" }).format(value);
}
