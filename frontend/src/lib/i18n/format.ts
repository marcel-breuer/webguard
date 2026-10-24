import { page } from "$app/state";
import type { DateTimeFormatOptions } from "$lib/i18n/formatters";
import {
    formatCurrencyForLocale,
    formatDateTimeForLocale,
    formatMonthYearForLocale,
    formatNumberForLocale,
    formatPercentageForLocale,
    resolveFormatLocale,
} from "$lib/i18n/formatters";

export type { DateTimeFormatOptions } from "$lib/i18n/formatters";

export function formatLocale(): string {
    return resolveFormatLocale(page.data.formatLocale, page.data.locale);
}

export function formatNumber(value: number, options: Intl.NumberFormatOptions = {}): string {
    return formatNumberForLocale(formatLocale(), value, options);
}

export function formatPercentage(value: number, fractionDigits = 2): string {
    return formatPercentageForLocale(formatLocale(), value, fractionDigits);
}

export function formatCurrency(value: number, currency: string, options: Intl.NumberFormatOptions = {}): string {
    return formatCurrencyForLocale(formatLocale(), value, currency, options);
}

export function formatDateTime(
    value: string | null | undefined,
    fallback: string,
    options: DateTimeFormatOptions = { dateStyle: "medium", timeStyle: "short" },
): string {
    return formatDateTimeForLocale(formatLocale(), value, fallback, options);
}

export function formatMonthYear(value: Date): string {
    return formatMonthYearForLocale(formatLocale(), value);
}
