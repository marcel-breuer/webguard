import assert from "node:assert/strict";
import { chromium } from "playwright";

const baseUrl = process.env.SMOKE_BASE_URL;
assert.ok(baseUrl, "SMOKE_BASE_URL must point to the development frontend (/_ui is development-only).");

const browser = await chromium.launch({ headless: true });

try {
    for (const colorScheme of ["light", "dark"]) {
        for (const width of [1440, 390, 320]) {
            const page = await browser.newPage({ colorScheme, viewport: { width, height: 900 } });
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            // The isolated preview has no authenticated API session.
            await page.route("**/api/notifications?**", (route) => route.fulfill({ json: { data: [], meta: { unread_count: 0 } } }));
            await page.route("**/sanctum/csrf-cookie", (route) => route.fulfill({ status: 204 }));
            await page.route("**/api/appearance", (route) => route.fulfill({ json: { data: route.request().postDataJSON() } }));
            await page.goto(`${baseUrl}/_ui`, { waitUntil: "networkidle" });
            await page.getByRole("heading", { name: "WebGuard interface primitives" }).waitFor();
            assert.equal(await page.title(), "WebGuard UI preview");
            assert.equal(await page.locator("vite-error-overlay").count(), 0);
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), `Horizontal overflow at ${width}px`);

            const panel = page.locator("section.wg-glass-panel").first();
            assert.ok(await panel.evaluate((element) => getComputedStyle(element).backgroundColor.includes("0.88") || getComputedStyle(element).backgroundColor.includes("0.92")), "Content panels must be translucent");
            await page.getByRole("button", { name: "Open confirmation" }).click();
            const dialog = page.getByRole("dialog", { name: "Discard unsaved changes?" });
            await dialog.waitFor();
            assert.ok(await dialog.evaluate((element) => getComputedStyle(element).backdropFilter.includes("blur")));
            await page.keyboard.press("Tab");
            assert.ok(await dialog.evaluate((element) => element.contains(document.activeElement)), "Dialog must retain keyboard focus");
            await page.keyboard.press("Escape");
            await dialog.waitFor({ state: "detached" });
            assert.ok(await page.getByRole("button", { name: "Open confirmation" }).evaluate((element) => element === document.activeElement), "Closing a dialog must restore focus");

            if (width < 864) {
                await page.getByRole("button", { name: "Toggle navigation" }).click();
                assert.equal(await page.getByRole("button", { name: "Toggle navigation" }).getAttribute("aria-expanded"), "true");
                await page.keyboard.press("Escape");
                assert.equal(await page.getByRole("button", { name: "Toggle navigation" }).getAttribute("aria-expanded"), "false");
            }

            if (width === 1440) {
                await page.locator("summary[aria-label='Appearance']").click();
                const oppositeTheme = colorScheme === "light" ? "dark" : "light";
                const choice = page.getByRole("button", { name: `Use ${oppositeTheme} appearance` });
                await choice.click();
                await page.waitForFunction((theme) => document.documentElement.dataset.theme === theme, oppositeTheme);
                assert.equal(await choice.getAttribute("aria-pressed"), "true");
                assert.ok(await choice.evaluate((element) => !element.classList.contains("bg-wg-surface")), "Selected theme must have an unambiguous accent surface");
                assert.equal(await page.locator("html").evaluate((element) => element.classList.contains("dark")), oppositeTheme === "dark");
                await page.locator("summary[aria-label='Appearance']").click();
            }

            const cdp = await page.context().newCDPSession(page);
            for (const feature of ["prefers-reduced-transparency", "prefers-contrast"]) {
                await cdp.send("Emulation.setEmulatedMedia", { features: [{ name: feature, value: feature === "prefers-contrast" ? "more" : "reduce" }] });
                assert.ok(await page.evaluate(({ feature }) => matchMedia(`(${feature}: ${feature === "prefers-contrast" ? "more" : "reduce"})`).matches, { feature }));
                assert.ok(await panel.evaluate((element) => !getComputedStyle(element).backgroundColor.includes("color(")), "Accessibility fallback must be solid");
                assert.equal(await page.locator(".wg-glass-navigation").evaluate((element) => getComputedStyle(element).backdropFilter), "none");
                assert.equal(await page.locator("body").evaluate((element) => getComputedStyle(element).backgroundImage), "none");
                await page.getByRole("button", { name: "Open confirmation" }).click();
                assert.equal(await dialog.evaluate((element) => getComputedStyle(element).backdropFilter), "none");
                assert.ok(await dialog.evaluate((element) => !getComputedStyle(element).backgroundColor.includes("color(")), "Dialog accessibility fallback must be solid");
                await page.keyboard.press("Escape");
            }
            await cdp.send("Emulation.setEmulatedMedia", { features: [] });
            await page.emulateMedia({ forcedColors: "active", reducedMotion: "reduce" });
            assert.equal(await page.locator(".wg-glass-navigation").evaluate((element) => getComputedStyle(element).backdropFilter), "none");
            assert.deepEqual(errors, []);
            console.log(`Glass interface passed: ${colorScheme}, ${width}px, dialog focus, navigation, solid accessibility fallbacks.`);
            await page.close();
        }
    }
} finally {
    await browser.close();
}
