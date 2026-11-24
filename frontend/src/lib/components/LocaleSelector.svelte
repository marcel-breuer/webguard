<script lang="ts">
    import * as DropdownMenu from "#lib/components/ui/dropdown-menu/index.ts";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import NavIcon from "#lib/components/NavIcon.svelte";

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


    function handleOpenChange(nextOpen: boolean): void {
        if (nextOpen) onOpen?.();
    }
</script>

<DropdownMenu.Root bind:open onOpenChange={handleOpenChange}>
    <DropdownMenu.Trigger class={`grid size-11 place-items-center rounded-[0.65rem] border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus ${variant === "surface" ? "border-wg-border bg-wg-surface text-wg-text" : "border-purple-700 text-purple-100"}`} aria-label="Language" title="Language">
        <NavIcon name="globe" />
    </DropdownMenu.Trigger>
    <DropdownMenu.Content preventScroll={false} portalProps={{ disabled: true }} align={menuAlign === "right" ? "end" : "start"} side={menuPlacement === "below" ? "bottom" : "top"} sideOffset={8} class="w-48 gap-2 p-2 wg-glass-overlay" aria-label="Language" aria-busy={saving}>
        <DropdownMenu.Label class="px-1 text-sm font-bold text-wg-text">Language</DropdownMenu.Label>
        <DropdownMenu.RadioGroup aria-label="Language" class="grid w-full gap-1"
            bind:value={() => locale, (value) => { if (value) void select(value); }}
        >
            {#each options as option (option.value)}
                <DropdownMenu.RadioItem value={option.value} disabled={saving} class="min-h-10 justify-between px-3 font-bold data-[state=checked]:bg-wg-accent data-[state=checked]:text-wg-accent-contrast [&_[data-slot=dropdown-menu-radio-item-indicator]]:hidden">
                    <span>{option.label}</span><span class="text-xs font-extrabold tracking-[0.08em]">{option.shortLabel}</span>
                </DropdownMenu.RadioItem>
            {/each}
        </DropdownMenu.RadioGroup>
        {#if error}<p class="text-[0.8125rem] text-wg-danger" role="alert">{error}</p>{/if}
    </DropdownMenu.Content>
</DropdownMenu.Root>
