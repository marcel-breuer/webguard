<script lang="ts">
    import CheckIcon from "@lucide/svelte/icons/check";
    import ChevronDownIcon from "@lucide/svelte/icons/chevron-down";
    import XIcon from "@lucide/svelte/icons/x";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    import { Button } from "#lib/components/ui/button/index.ts";
    import { Command as CommandPrimitive } from "bits-ui";
    import * as Command from "#lib/components/ui/command/index.ts";
    import { Input } from "#lib/components/ui/input/index.ts";
    import { NativeSelect } from "#lib/components/ui/native-select/index.ts";
    import * as Popover from "#lib/components/ui/popover/index.ts";
    import type { Snippet } from "svelte";
    import type { HTMLSelectAttributes } from "svelte/elements";

    export interface SelectOption {
        value: string;
        label: string;
        disabled?: boolean;
    }

    interface Props extends Omit<HTMLSelectAttributes, "value" | "multiple"> {
        value?: string | string[];
        options?: SelectOption[];
        children?: Snippet;
        width?: "full" | "compact";
        density?: "default" | "compact";
        multiple?: boolean;
        searchable?: boolean;
        placeholder?: string;
        searchPlaceholder?: string;
        noOptionsLabel?: string;
        noResultsLabel?: string;
        selectAllLabel?: string;
        clearLabel?: string;
    }

    let {
        value = $bindable<string | string[]>(""),
        options,
        children,
        width = "full",
        density = "default",
        multiple = false,
        searchable = false,
        placeholder = "Select an option",
        searchPlaceholder = "Search options",
        noOptionsLabel = "No options available.",
        noResultsLabel = "No matching options found.",
        selectAllLabel = "Select all",
        clearLabel = "Clear selection",
        name,
        disabled = false,
        required = false,
        id,
        form,
        title,
        class: className = "",
        ...attributes
    }: Props = $props();

    let open = $state(false);
    let query = $state("");
    let searchInput = $state<HTMLInputElement | null>(null);
    const uid = $props.id();

    const selectedValues = $derived(Array.isArray(value) ? value : value === "" ? [] : [value]);
    const selectedOptions = $derived((options ?? []).filter((option) => selectedValues.includes(option.value)));
    const filteredOptions = $derived((options ?? []).filter((option) => option.label.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())));
    const selectableOptions = $derived(filteredOptions.filter((option) => !option.disabled));
    const allFilteredSelected = $derived(selectableOptions.length > 0 && selectableOptions.every((option) => selectedValues.includes(option.value)));
    const hasSelection = $derived(selectedValues.length > 0);
    const widthClass = $derived(width === "full" ? "w-full" : "w-32");
    const densityClass = $derived(density === "default" ? "min-h-11 px-3 py-2 text-sm" : "min-h-8 px-2.5 py-1 text-xs");
    const controlClass = $derived(`border border-wg-border text-wg-text transition-colors hover:border-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted ${densityClass} ${className}`);

    function select(option: SelectOption): void {
        if (option.disabled) return;

        if (multiple) {
            value = selectedValues.includes(option.value)
                ? selectedValues.filter((selected) => selected !== option.value)
                : [...selectedValues, option.value];
        } else {
            value = option.value;
            open = false;
            query = "";
        }
    }

    function toggleAll(): void {
        const available = new Set(selectableOptions.map((option) => option.value));
        value = allFilteredSelected
            ? selectedValues.filter((selected) => !available.has(selected))
            : [...new Set([...selectedValues, ...available])];
    }

    function handleSearchKeydown(event: KeyboardEvent): void {
        if (event.key === "Backspace" && query === "" && multiple) {
            const lastOption = selectedOptions.at(-1);
            if (lastOption && !lastOption.disabled) select(lastOption);
        }
    }
</script>

{#if options !== undefined}
    <div class={`relative ${widthClass}`}>
        {#if name}
            {#if multiple}
                {#each selectedValues as selected}<input {name} {form} {disabled} type="hidden" value={selected} />{/each}
            {:else}
                <input {name} {form} {disabled} type="hidden" value={Array.isArray(value) ? value[0] ?? "" : value} />
            {/if}
        {/if}
        <Popover.Root bind:open onOpenChange={(nextOpen) => { if (!nextOpen) query = ""; }}>
            <Popover.Trigger variant="control" id={id ?? undefined} title={title ?? undefined} {disabled} aria-label={attributes["aria-label"]} aria-labelledby={attributes["aria-labelledby"]} aria-describedby={attributes["aria-describedby"]} aria-invalid={attributes["aria-invalid"]}
                class={`flex w-full items-center gap-2 pr-16 text-left outline-none ${controlClass}`}
                onkeydown={(event) => {
                    if (!open && (event.key === "ArrowDown" || event.key === "ArrowUp")) {
                        event.preventDefault();
                        open = true;
                    }
                }}
            >
                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {#if multiple && selectedOptions.length > 0}
                        {#each selectedOptions as option (option.value)}
                            <Badge variant="neutral" class="block max-w-full truncate px-2 py-0.5 leading-normal text-wg-accent">{option.label}</Badge>
                        {/each}
                    {:else if selectedOptions[0]}
                        <span class="truncate">{selectedOptions[0].label}</span>
                    {:else}
                        <span class="text-wg-text-muted">{placeholder}</span>
                    {/if}
                </span>
                <ChevronDownIcon class={`size-4 shrink-0 text-wg-accent transition-transform ${open ? "rotate-180" : ""}`} />
            </Popover.Trigger>
            <Popover.Content variant="glass" portalProps={{ disabled: true }} class="w-(--bits-popover-anchor-width) p-1"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    searchInput?.focus();
                }}
            >
                <Command.Root shouldFilter={false} loop class="bg-transparent">
                    <div class={searchable ? "p-1" : "sr-only"}>
                        <CommandPrimitive.Input value={query}>
                            {#snippet child({ props })}
                                <Input {...props} bind:ref={searchInput} bind:value={query} aria-label={searchPlaceholder} placeholder={searchPlaceholder} readonly={!searchable} class="min-h-9" onkeydown={handleSearchKeydown} />
                            {/snippet}
                        </CommandPrimitive.Input>
                    </div>
                    <Command.List id={`${uid}-options`} aria-label={placeholder} aria-multiselectable={multiple || undefined} class="max-h-64">
                        <CommandPrimitive.Viewport onpointerdown={(event) => event.preventDefault()} onclick={(event) => event.preventDefault()}>
                            <Command.Group>
                                {#if multiple && selectableOptions.length > 0}
                                    <Command.Item value="select-all" class="min-h-10 font-semibold" onSelect={toggleAll}>
                                        <CheckIcon class={allFilteredSelected ? "text-wg-accent" : "invisible"} />
                                        {selectAllLabel}
                                    </Command.Item>
                                {/if}
                                {#each filteredOptions as option (option.value)}
                                    <Command.Item value={`option:${option.value}`} disabled={option.disabled} aria-checked={multiple ? selectedValues.includes(option.value) : undefined} class="min-h-10" onSelect={() => select(option)}>
                                        {#if multiple}<CheckIcon class={selectedValues.includes(option.value) ? "text-wg-accent" : "invisible"} />{/if}
                                        <span class="min-w-0 truncate">{option.label}</span>
                                    </Command.Item>
                                {/each}
                            </Command.Group>
                            {#if filteredOptions.length === 0}
                                <Command.Empty>{options.length === 0 ? noOptionsLabel : noResultsLabel}</Command.Empty>
                            {/if}
                        </CommandPrimitive.Viewport>
                    </Command.List>
                </Command.Root>
            </Popover.Content>
        </Popover.Root>
        {#if hasSelection}
            <Button variant="ghost" size="icon-xs" {disabled} class="absolute top-1/2 right-8 min-h-0 -translate-y-1/2 border-0 p-0 active:-translate-y-1/2" aria-label={clearLabel}
                onclick={() => { value = multiple ? [] : ""; query = ""; }}
            >
                <XIcon class="size-3.5" />
            </Button>
        {/if}
    </div>
{:else}
    <NativeSelect {...attributes} {id} {name} {form} {title} {disabled} {required} {multiple} {density} bind:value class={className} wrapperClass={widthClass}>
        {@render children?.()}
    </NativeSelect>
{/if}
