<script lang="ts">
    import { onMount } from "svelte";
    import { applyAppearanceTheme, storedAppearanceTheme } from "$lib/appearance";
    import { FirstPartyApiError, requestFirstPartyApi } from "$lib/api/client";
    import NavIcon, { type NavIconName } from "$lib/components/NavIcon.svelte";
    import type { AppearanceTheme } from "$lib/api/models";

    interface Props {
        initialTheme?: AppearanceTheme;
        endpoint?: string | null;
        menuAlign?: "left" | "right";
        menuPlacement?: "above" | "below";
        variant?: "sidebar" | "surface";
        open?: boolean;
        onOpen?: () => void;
    }

    let { initialTheme, endpoint = "/api/appearance", menuAlign = "left", menuPlacement = "above", variant = "sidebar", open = $bindable(false), onOpen }: Props = $props();
    let theme = $state<AppearanceTheme>("system");
    let saving = $state(false);
    let error = $state("");

    const options: { value: AppearanceTheme; label: string; icon: NavIconName }[] = [
        { value: "light", label: "Light", icon: "sun" },
        { value: "dark", label: "Dark", icon: "moon" },
        { value: "system", label: "System", icon: "system" },
    ];

    onMount(() => {
        theme = initialTheme ?? storedAppearanceTheme();
        applyAppearanceTheme(theme);
    });

    async function select(nextTheme: AppearanceTheme): Promise<void> {
        if (saving || nextTheme === theme) {
            return;
        }

        const previousTheme = theme;
        theme = nextTheme;
        error = "";
        applyAppearanceTheme(nextTheme);

        if (endpoint === null) {
            return;
        }

        saving = true;

        try {
            const response = await requestFirstPartyApi<{ theme: AppearanceTheme }>(endpoint, {
                body: JSON.stringify({ theme: nextTheme }),
                method: "PATCH",
            });

            theme = response.data.theme;
            applyAppearanceTheme(theme);
        } catch (exception) {
            theme = previousTheme;
            applyAppearanceTheme(previousTheme);
            error = exception instanceof FirstPartyApiError ? exception.message : "Appearance could not be saved.";
        } finally {
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
    <summary class={`grid size-11 cursor-pointer list-none place-items-center rounded-[0.65rem] border [&::-webkit-details-marker]:hidden ${variant === "surface" ? "border-wg-border bg-wg-surface text-wg-text" : "border-purple-700 text-purple-100"}`} aria-label="Appearance" title="Appearance"><NavIcon name="sun" /></summary>
    <div class={`absolute ${menuPlacement === "below" ? "top-[calc(100%+0.5rem)]" : "bottom-[calc(100%+0.5rem)]"} ${menuAlign === "right" ? "right-0" : "left-0"} z-30 w-48 rounded-xl border border-wg-border bg-wg-surface p-2 shadow-wg-surface`} aria-label="Appearance" aria-busy={saving}>
        {@render optionsList()}
    </div>
</details>

{#snippet optionsList()}
    <p class="mb-2 px-1 text-sm font-bold text-wg-text">Appearance</p>
    <div class="grid grid-cols-3 overflow-hidden rounded-xl border border-wg-border">
        {#each options as option}
            <button
                type="button"
                class={`grid min-h-10 place-items-center border-0 border-r border-wg-border bg-wg-surface text-wg-text-muted last:border-r-0 disabled:cursor-wait disabled:opacity-65 ${theme === option.value ? "bg-wg-accent text-wg-accent-contrast" : ""}`}
                aria-pressed={theme === option.value}
                aria-label={`Use ${option.label.toLowerCase()} appearance`}
                title={option.label}
                disabled={saving}
                onclick={() => select(option.value)}
            >
                <NavIcon name={option.icon} />
            </button>
        {/each}
    </div>
    {#if error}<p class="mt-2 text-[0.8125rem] text-wg-danger" role="alert">{error}</p>{/if}
{/snippet}
