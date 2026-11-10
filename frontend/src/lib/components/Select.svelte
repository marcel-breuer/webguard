<script lang="ts">
    import { tick } from "svelte";
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
        class: className = "",
        ...attributes
    }: Props = $props();

    let open = $state(false);
    let query = $state("");
    let root = $state<HTMLDivElement | null>(null);
    let searchInput = $state<HTMLInputElement | null>(null);
    let trigger = $state<HTMLButtonElement | null>(null);

    const custom = $derived(options !== undefined);
    const selectedValues = $derived(Array.isArray(value) ? value : value === "" ? [] : [value]);
    const selectedOptions = $derived((options ?? []).filter((option) => selectedValues.includes(option.value)));
    const filteredOptions = $derived((options ?? []).filter((option) => option.label.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())));
    const allFilteredSelected = $derived(filteredOptions.length > 0 && filteredOptions.every((option) => selectedValues.includes(option.value)));
    const hasSelection = $derived(selectedValues.length > 0);
    const widthClass = $derived(width === "full" ? "w-full" : "w-32");
    const densityClass = $derived(density === "default" ? "min-h-11 px-3 py-2 text-sm" : "min-h-8 px-2.5 py-1 text-xs");
    const controlClass = $derived(`wg-glass-control border border-wg-border text-wg-text transition-colors hover:border-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:bg-wg-surface-muted disabled:text-wg-text-muted ${densityClass} ${className}`);

    function close(): void {
        open = false;
        query = "";
    }

    function toggle(): void {
        if (disabled) return;

        open = !open;

        if (open && searchable) queueMicrotask(() => searchInput?.focus());
    }

    function optionButtons(): HTMLButtonElement[] {
        return root === null ? [] : Array.from(root.querySelectorAll<HTMLButtonElement>("[data-select-option]:not(:disabled)"));
    }

    function focusOption(position: "first" | "last" = "first"): void {
        void tick().then(() => {
            const buttons = optionButtons();
            (position === "last" ? buttons.at(-1) : buttons[0])?.focus();
        });
    }

    function openOptions(position: "first" | "last" = "first"): void {
        if (disabled) return;

        open = true;

        if (searchable) {
            void tick().then(() => searchInput?.focus());
            return;
        }

        focusOption(position);
    }

    function select(option: SelectOption): void {
        if (option.disabled) return;

        if (multiple) {
            value = selectedValues.includes(option.value)
                ? selectedValues.filter((selected) => selected !== option.value)
                : [...selectedValues, option.value];

            return;
        }

        value = option.value;
        close();
    }

    function remove(option: SelectOption): void {
        value = selectedValues.filter((selected) => selected !== option.value);
    }

    function clear(): void {
        value = multiple ? [] : "";
        query = "";
    }

    function toggleAll(): void {
        if (allFilteredSelected) {
            value = selectedValues.filter((selected) => !filteredOptions.some((option) => option.value === selected));

            return;
        }

        value = [...new Set([...selectedValues, ...filteredOptions.filter((option) => !option.disabled).map((option) => option.value)])];
    }

    function handleWindowPointerDown(event: PointerEvent): void {
        if (root !== null && event.target instanceof Node && !root.contains(event.target)) close();
    }

    function handleWindowKeydown(event: KeyboardEvent): void {
        if (event.key === "Escape" && open) {
            close();
            trigger?.focus();
        }
    }

    function handleTriggerKeydown(event: KeyboardEvent): void {
        if (["Enter", " ", "ArrowDown", "ArrowUp"].includes(event.key)) {
            event.preventDefault();
            openOptions(event.key === "ArrowUp" ? "last" : "first");
        }
    }

    function handleSearchKeydown(event: KeyboardEvent): void {
        if (event.key === "Enter") event.preventDefault();

        if (event.key === "Escape") {
            event.preventDefault();
            close();
            trigger?.focus();
            return;
        }

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            focusOption(event.key === "ArrowUp" ? "last" : "first");
            return;
        }

        if (event.key === "Backspace" && query === "" && multiple && selectedOptions.length > 0) {
            remove(selectedOptions.at(-1) as SelectOption);
        }
    }

    function handleOptionKeydown(event: KeyboardEvent): void {
        const buttons = optionButtons();
        const currentIndex = buttons.indexOf(event.currentTarget as HTMLButtonElement);

        if (event.key === "Escape") {
            event.preventDefault();
            close();
            trigger?.focus();
            return;
        }

        if (event.key === "Home" || event.key === "End") {
            event.preventDefault();
            (event.key === "Home" ? buttons[0] : buttons.at(-1))?.focus();
            return;
        }

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            const offset = event.key === "ArrowDown" ? 1 : -1;
            buttons[(currentIndex + offset + buttons.length) % buttons.length]?.focus();
        }
    }
</script>

<svelte:window onpointerdown={handleWindowPointerDown} onkeydown={handleWindowKeydown} />

{#if custom}
    <div bind:this={root} class={`relative ${widthClass}`}>
        {#if name}
            {#if multiple}
                {#each selectedValues as selected}<input name={name} type="hidden" value={selected} />{/each}
            {:else}
                <input {name} type="hidden" value={Array.isArray(value) ? value[0] ?? "" : value} />
            {/if}
        {/if}

        <button
            bind:this={trigger}
            {id}
            type="button"
            class={`flex w-full items-center gap-2 pr-16 text-left outline-none ${controlClass} ${open ? "border-wg-accent ring-2 ring-wg-focus/40" : ""}`}
            aria-expanded={open}
            aria-haspopup="listbox"
            aria-controls={id ? `${id}-options` : undefined}
            {disabled}
            onclick={toggle}
            onkeydown={handleTriggerKeydown}
        >
            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                {#if multiple && selectedOptions.length > 0}
                    {#each selectedOptions as option (option.value)}
                        <span class="inline-flex max-w-full items-center gap-1 rounded bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800 dark:bg-violet-900/70 dark:text-violet-100">
                            <span class="truncate">{option.label}</span>
                        </span>
                    {/each}
                {:else if selectedOptions[0]}
                    <span class="truncate">{selectedOptions[0].label}</span>
                {:else}
                    <span class="text-wg-text-muted">{placeholder}</span>
                {/if}
            </span>
            <svg class={`size-4 shrink-0 text-wg-accent transition-transform ${open ? "rotate-180" : ""}`} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>
        {#if hasSelection}
            <button type="button" class="absolute top-1/2 right-8 grid size-6 -translate-y-1/2 place-items-center rounded text-wg-text-muted hover:bg-violet-100 hover:text-wg-text focus-visible:bg-violet-100 focus-visible:outline-2 focus-visible:outline-wg-focus dark:hover:bg-violet-900/70" aria-label={clearLabel} onclick={clear}><svg viewBox="0 0 16 16" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 4 8 8M12 4l-8 8" /></svg></button>
        {/if}

        {#if open}
            <div id={id ? `${id}-options` : undefined} class="absolute z-30 mt-1 w-full overflow-hidden wg-glass-panel wg-glass-overlay py-1" role="listbox" aria-multiselectable={multiple || undefined}>
                {#if searchable}
                    <div class="border-b border-violet-100 p-2 dark:border-violet-900"><input bind:this={searchInput} bind:value={query} aria-label={searchPlaceholder} class="min-h-9 w-full rounded border border-violet-200 bg-violet-50/40 px-2.5 text-sm text-wg-text outline-none placeholder:text-wg-text-muted focus:border-wg-accent focus:ring-2 focus:ring-wg-focus/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-wg-focus dark:border-violet-800 dark:bg-violet-950/30" placeholder={searchPlaceholder} onkeydown={handleSearchKeydown} /></div>
                {/if}
                <div class="max-h-64 overflow-y-auto">
                    {#if multiple && filteredOptions.length > 0}
                        <button type="button" class="flex min-h-10 w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-wg-text transition hover:bg-violet-50 focus:bg-violet-50 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-wg-focus dark:hover:bg-violet-950/40 dark:focus:bg-violet-950/40" onclick={toggleAll}><span class={`grid size-4 place-items-center rounded border ${allFilteredSelected ? "border-wg-accent bg-wg-accent text-wg-accent-contrast" : "border-wg-border bg-wg-surface"}`} aria-hidden="true">{#if allFilteredSelected}<svg viewBox="0 0 16 16" class="size-3" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 8 3 3 7-7" /></svg>{/if}</span>{selectAllLabel}</button>
                    {/if}
                    {#if (options ?? []).length === 0}
                        <p class="px-3 py-2 text-sm text-wg-text-muted">{noOptionsLabel}</p>
                    {:else if filteredOptions.length === 0}
                        <p class="px-3 py-2 text-sm text-wg-text-muted">{noResultsLabel}</p>
                    {:else}
                        {#each filteredOptions as option (option.value)}
                            <button data-select-option type="button" class={`flex min-h-10 w-full items-center gap-2 px-3 py-2 text-left text-sm text-wg-text transition hover:bg-violet-50 focus:bg-violet-50 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-wg-focus disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-violet-950/40 dark:focus:bg-violet-950/40 ${selectedValues.includes(option.value) ? "bg-violet-50 dark:bg-violet-950/40" : ""}`} role="option" aria-selected={selectedValues.includes(option.value)} disabled={option.disabled} onclick={() => select(option)} onkeydown={handleOptionKeydown}>{#if multiple}<span class={`grid size-4 place-items-center rounded border ${selectedValues.includes(option.value) ? "border-wg-accent bg-wg-accent text-wg-accent-contrast" : "border-wg-border bg-wg-surface"}`} aria-hidden="true">{#if selectedValues.includes(option.value)}<svg viewBox="0 0 16 16" class="size-3" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 8 3 3 7-7" /></svg>{/if}</span>{/if}<span class="min-w-0 truncate">{option.label}</span></button>
                        {/each}
                    {/if}
                </div>
            </div>
        {/if}
    </div>
{:else}
    <div class={`relative ${widthClass}`}>
        {#if multiple}
            <select {...attributes} {id} {name} {disabled} {required} multiple bind:value class={`w-full appearance-none pr-3 outline-none ${controlClass}`}>
                {@render children?.()}
            </select>
        {:else}
            <select {...attributes} {id} {name} {disabled} {required} bind:value class={`w-full appearance-none pr-10 outline-none ${controlClass}`}>
                {@render children?.()}
            </select>
            <svg class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-wg-accent" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        {/if}
    </div>
{/if}
