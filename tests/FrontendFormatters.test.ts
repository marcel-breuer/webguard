import { describe, expect, test } from "bun:test";
import {
    formatCurrencyForLocale,
    formatDateTimeForLocale,
    formatNumberForLocale,
    formatPercentageForLocale,
    resolveFormatLocale,
} from "../frontend/src/lib/i18n/formatters";
import { translate } from "../frontend/src/lib/i18n/localize";

describe("regional frontend formatting", () => {
    test("keeps formatting region independent from interface language and falls back safely", () => {
        expect(resolveFormatLocale("en-GB", "de")).toBe("en-GB");
        expect(resolveFormatLocale("fr-FR", "de")).toBe("de-DE");
        expect(resolveFormatLocale(null, "en")).toBe("en-US");
    });

    test("formats numbers, percentages, and currency by the selected region", () => {
        expect(formatNumberForLocale("de-DE", 1234567.89)).toBe("1.234.567,89");
        expect(formatNumberForLocale("en-US", 1234567.89)).toBe("1,234,567.89");
        expect(formatPercentageForLocale("de-DE", 90.5)).toBe("90,50 %");
        expect(formatPercentageForLocale("en-GB", 90.5)).toBe("90.50%");
        expect(formatCurrencyForLocale("de-DE", 1234.56, "EUR")).toContain("1.234,56");
        expect(formatCurrencyForLocale("en-US", 1234.56, "EUR")).toContain("1,234.56");
    });

    test("formats dates by region and safely returns the supplied fallback", () => {
        const date = "2026-10-24T15:07:00.000Z";
        const options: Intl.DateTimeFormatOptions = {
            day: "2-digit",
            month: "2-digit",
            timeZone: "UTC",
            year: "numeric",
        };

        expect(formatDateTimeForLocale("de-DE", date, "—", options)).toBe("24.10.2026");
        expect(formatDateTimeForLocale("en-GB", date, "—", options)).toBe("24/10/2026");
        expect(formatDateTimeForLocale("en-US", date, "—", options)).toBe("10/24/2026");
        expect(formatDateTimeForLocale("de-DE", "invalid", "—")).toBe("—");
    });

    test("translates dynamic text containing localized numbers", () => {
        const messages = {
            "Showing :count of :total entries": "Zeige :count von :total Einträgen",
            ":hours h :minutes min": ":hours Std. :minutes Min.",
            ":count loaded checks · Live and archived data": ":count geladene Prüfungen · Live- und Archivdaten",
        };

        expect(translate("Showing 12.345 of 67.890 entries", "de", messages)).toBe("Zeige 12.345 von 67.890 Einträgen");
        expect(translate("2026-10-24 · 1.234h 12m", "de", messages)).toBe("2026-10-24 · 1.234 Std. 12 Min.");
        expect(translate("1.234 loaded checks · Live and archived data", "de", messages)).toBe("1.234 geladene Prüfungen · Live- und Archivdaten");
    });
});
