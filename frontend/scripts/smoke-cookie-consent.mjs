import assert from "node:assert/strict";
import { chromium } from "playwright";

const baseUrl = process.env.SMOKE_BASE_URL ?? "http://gateway:8080";
const enabled = process.env.SMOKE_CONSENT_ENABLED === "true";
const statusPageSlug = process.env.SMOKE_STATUS_PAGE_SLUG;
const browser = await chromium.launch({ headless: true });

try {
    const context = await browser.newContext({
        extraHTTPHeaders: { "Accept-Language": "de-DE,de;q=0.9" },
        viewport: { width: 390, height: 844 },
    });
    const page = await context.newPage();
    const googleRequests = [];
    await page.route("https://www.googletagmanager.com/**", async (route) => {
        googleRequests.push(route.request().url());
        await route.fulfill({ status: 200, contentType: "application/javascript", body: "" });
    });

    await page.goto(`${baseUrl}/login`);
    if (!enabled) {
        await page.waitForLoadState("networkidle");
        assert.equal(await page.getByRole("heading", { name: "Cookie-Einstellungen" }).count(), 0);
        assert.equal(await page.getByRole("button", { name: "Cookie-Einstellungen" }).count(), 0);
        assert.deepEqual(googleRequests, []);
        await context.close();
        console.log("Cookie consent stays absent without analytics configuration.");
        process.exit(0);
    }

    await page.getByRole("heading", { name: "Cookie-Einstellungen" }).waitFor();
    assert.equal(await page.getByRole("button", { name: "Alle ablehnen" }).count(), 1);
    assert.equal(await page.getByRole("button", { name: "Alle akzeptieren" }).count(), 1);
    assert.deepEqual(googleRequests, [], "Google must not be contacted before consent");

    const rejectedContext = await browser.newContext({ extraHTTPHeaders: { "Accept-Language": "de-DE,de;q=0.9" } });
    const rejectedPage = await rejectedContext.newPage();
    await rejectedPage.route("https://www.googletagmanager.com/**", (route) => route.abort());
    await rejectedPage.goto(`${baseUrl}/login`);
    await rejectedPage.getByRole("button", { name: "Alle ablehnen" }).click();
    assert.equal(await rejectedPage.getByRole("heading", { name: "Cookie-Einstellungen" }).count(), 0);
    await rejectedPage.reload();
    assert.equal(await rejectedPage.getByRole("heading", { name: "Cookie-Einstellungen" }).count(), 0);
    await rejectedContext.close();

    await page.getByRole("button", { name: "Einstellungen", exact: true }).click();
    await page.getByRole("checkbox", { name: /Statistik/ }).check();
    await page.getByRole("button", { name: "Auswahl speichern" }).click();
    await page.waitForFunction(() => window.dataLayer?.some((entry) => Array.isArray(entry) && entry[0] === "consent" && entry[1] === "update"));
    assert.equal(googleRequests.length, 1, "GTM should load once after opt-in");
    assert.match(googleRequests[0], /id=GTM-CONSENTTEST/);

    const loginEvents = await page.evaluate(() => window.dataLayer?.filter((entry) =>
        typeof entry === "object" && entry !== null && "event" in entry && entry.event === "webguard_page_view",
    ) ?? []);
    assert.equal(loginEvents.length, 1);
    assert.deepEqual(loginEvents[0], { event: "webguard_page_view", page_category: "authentication" });

    await page.getByRole("button", { name: "Cookie-Einstellungen" }).click();
    await page.getByRole("checkbox", { name: /Statistik/ }).uncheck();
    await page.getByRole("button", { name: "Auswahl speichern" }).click();
    const consentUpdates = await page.evaluate(() => window.dataLayer?.filter((entry) =>
        Array.isArray(entry) && entry[0] === "consent" && entry[1] === "update",
    ) ?? []);
    assert.equal(consentUpdates.at(-1)?.[2]?.analytics_storage, "denied");

    const requestsAfterWithdrawal = googleRequests.length;
    await page.goto(`${baseUrl}/status/${statusPageSlug}`);
    await page.waitForLoadState("networkidle");
    assert.equal(await page.getByRole("button", { name: "Cookie-Einstellungen" }).count(), 1);
    assert.equal(googleRequests.length, requestsAfterWithdrawal, "status pages must not load Google scripts");
    await context.close();
    console.log("Cookie consent, withdrawal, localization, and status-page exclusion passed.");
} finally {
    await browser.close();
}
