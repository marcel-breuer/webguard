<script lang="ts">
    import { Alert } from "#lib/components/ui/alert/index.ts";
    import * as DropdownMenu from "#lib/components/ui/dropdown-menu/index.ts";
    import { onMount } from "svelte";
    import { applyAppearanceTheme, storedAppearanceTheme } from "#lib/appearance.ts";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import NavIcon, { type NavIconName } from "#lib/components/NavIcon.svelte";
    import type { AppearanceTheme } from "#lib/api/models.ts";

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


    function handleOpenChange(nextOpen: boolean): void {
        if (nextOpen) onOpen?.();
    }
</script>

<DropdownMenu.Root bind:open onOpenChange={handleOpenChange}>
    <DropdownMenu.Trigger class={`grid size-11 place-items-center rounded-[0.65rem] border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus ${variant === "surface" ? "border-wg-border bg-wg-surface text-wg-text" : "border-purple-700 text-purple-100"}`} aria-label="Appearance" title="Appearance">
        <NavIcon name="sun" />
    </DropdownMenu.Trigger>
    <DropdownMenu.Content variant="glass" preventScroll={false} portalProps={{ disabled: true }} align={menuAlign === "right" ? "end" : "start"} side={menuPlacement === "below" ? "bottom" : "top"} sideOffset={8} class="w-48 gap-2 p-2" aria-label="Appearance" aria-busy={saving}>
        <DropdownMenu.Label class="px-1 text-sm font-bold text-wg-text">Appearance</DropdownMenu.Label>
        <DropdownMenu.RadioGroup aria-label="Appearance" class="grid w-full grid-cols-3"
            bind:value={() => theme, (value) => {
                const option = options.find((option) => option.value === value);
                if (option) void select(option.value);
            }}
        >
            {#each options as option (option.value)}
                <DropdownMenu.RadioItem value={option.value} disabled={saving} class="min-h-10 justify-center px-0 data-[state=checked]:bg-wg-accent data-[state=checked]:text-wg-accent-contrast [&_[data-slot=dropdown-menu-radio-item-indicator]]:hidden" aria-label={`Use ${option.label.toLowerCase()} appearance`} title={option.label}>
                    <NavIcon name={option.icon} />
                </DropdownMenu.RadioItem>
            {/each}
        </DropdownMenu.RadioGroup>
        {#if error}<Alert variant="inline" class="text-[0.8125rem] text-wg-danger">{error}</Alert>{/if}
    </DropdownMenu.Content>
</DropdownMenu.Root>
