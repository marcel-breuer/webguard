<script lang="ts">
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import NavIcon from "$lib/components/NavIcon.svelte";

    interface Props {
        initialLocale: string;
        endpoint?: string | null;
        menuAlign?: "left" | "right";
        menuPlacement?: "above" | "below";
        variant?: "sidebar" | "surface";
        open?: boolean;
        onOpen?: () => void;
    }

    let { initialLocale, endpoint = "/api/locale", menuAlign = "left", menuPlacement = "above", variant = "sidebar", open = $bindable(false), onOpen }: Props = $props();
    let locale = $state("");
    let saving = $state(false);
    let error = $state("");

    const options = [
        { value: "en", label: "English", shortLabel: "EN" },
        { value: "de", label: "Deutsch", shortLabel: "DE" },
    ];
    const guestLocaleCookieMaxAge = 60 * 60 * 24 * 365;

    $effect(() => {
        locale = initialLocale;
    });

    async function select(nextLocale: string): Promise<void> {
        if (saving || nextLocale === locale) {
            return;
        }

        saving = true;
        error = "";

        try {
            if (endpoint === null) {
                document.cookie = `webguard_locale=${encodeURIComponent(nextLocale)}; Max-Age=${guestLocaleCookieMaxAge}; path=/; SameSite=Lax`;
            } else {
                await requestFirstPartyApi<{ locale: string }>(endpoint, {
                    body: JSON.stringify({ locale: nextLocale }),
                    method: "PATCH",
                });
            }

            window.location.reload();
        } catch (exception) {
            error = exception instanceof FirstPartyApiError ? exception.message : "Language could not be saved.";
            saving = false;
        }
    }

    function handleToggle(event: Event): void {
        if ((event.currentTarget as HTMLDetailsElement).open) {
            onOpen?.();
        }
    }

</script>

<details class="relative" name="webguard-preferences" bind:open ontoggle={handleToggle}>
    <summary class={`grid size-11 cursor-pointer list-none place-items-center rounded-[0.65rem] border [&::-webkit-details-marker]:hidden ${variant === "surface" ? "border-wg-border bg-wg-surface text-wg-text" : "border-purple-700 text-purple-100"}`} aria-label="Language" title="Language">
        <NavIcon name="globe" />
    </summary>
    <div class={`absolute ${menuPlacement === "below" ? "top-[calc(100%+0.5rem)]" : "bottom-[calc(100%+0.5rem)]"} ${menuAlign === "right" ? "right-0" : "left-0"} z-30 w-48 rounded-xl border border-wg-border bg-wg-surface p-2 shadow-wg-surface`} aria-label="Language" aria-busy={saving}>
        <p class="mb-2 px-1 text-sm font-bold text-wg-text">Language</p>
        <div class="grid gap-1">
            {#each options as option}
                <button
                    type="button"
                    class={`flex min-h-10 items-center justify-between rounded-lg border-0 px-3 text-left text-sm font-bold disabled:cursor-wait disabled:opacity-65 ${locale === option.value ? "bg-wg-accent text-wg-accent-contrast" : "bg-transparent text-wg-text hover:bg-wg-surface-muted"}`}
                    aria-pressed={locale === option.value}
                    disabled={saving}
                    onclick={() => select(option.value)}
                >
                    <span>{option.label}</span><span class="text-xs font-extrabold tracking-[0.08em]">{option.shortLabel}</span>
                </button>
            {/each}
        </div>
        {#if error}<p class="mt-2 text-[0.8125rem] text-wg-danger" role="alert">{error}</p>{/if}
    </div>
</details>
