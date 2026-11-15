<script lang="ts">
    import { afterNavigate, goto } from "$app/navigation";
    import Dialog from "#lib/components/Dialog.svelte";
    import { requestFirstPartyApi } from "#lib/api/client.ts";
    import { appRoutes } from "#lib/routes.ts";

    interface MonitoringSearchResult {
        id: string;
        name: string;
        target: string;
    }

    let { open = $bindable(false) }: { open?: boolean } = $props();
    let search = $state("");
    let results = $state<MonitoringSearchResult[]>([]);
    let loading = $state(false);
    let failed = $state(false);
    let selected = $state(0);

    function close(): void {
        open = false;
        search = "";
        results = [];
    }

    afterNavigate(close);

    function handleShortcut(event: KeyboardEvent): void {
        if (event.metaKey && !event.ctrlKey && !event.altKey && !event.shiftKey
            && !event.repeat && !event.isComposing && event.code === "KeyK") {
            event.preventDefault();
            if (!open) open = true;
            else document.getElementById("monitoring-search-input")?.focus();
        }
    }

    $effect(() => {
        const query = search.trim();
        results = [];
        selected = 0;
        failed = false;
        loading = open && query.length >= 2;
        if (!open || query.length < 2) return;

        const controller = new AbortController();
        const timer = setTimeout(() => {
            void requestFirstPartyApi<MonitoringSearchResult[]>(
                `/api/monitorings/search?${new URLSearchParams({ search: query })}`,
                { signal: controller.signal },
            ).then((payload) => {
                if (!controller.signal.aborted) results = payload.data;
            }).catch(() => {
                if (!controller.signal.aborted) failed = true;
            }).finally(() => {
                if (!controller.signal.aborted) loading = false;
            });
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    });

    function handleSearchKeydown(event: KeyboardEvent): void {
        if (event.isComposing || results.length === 0) return;

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            selected = (selected + (event.key === "ArrowDown" ? 1 : -1) + results.length) % results.length;
            document.getElementById(`monitoring-search-result-${selected}`)?.scrollIntoView({ block: "nearest" });
        } else if (event.key === "Enter") {
            event.preventDefault();
            const id = results[selected]?.id;
            if (id) {
                close();
                void goto(`${appRoutes.monitorings}/${encodeURIComponent(id)}`);
            }
        }
    }
</script>

<svelte:window onkeydown={handleShortcut} />

<Dialog bind:open title="Search monitorings" description="Find a monitoring by name or target URL" initialFocus="#monitoring-search-input" layer="overlay" onclose={close}>
    <label for="monitoring-search-input" class="sr-only">Search monitorings</label>
    <input
        id="monitoring-search-input"
        type="search"
        bind:value={search}
        maxlength="120"
        autocomplete="off"
        spellcheck="false"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={results.length > 0}
        aria-controls="monitoring-search-results"
        aria-activedescendant={results.length > 0 ? `monitoring-search-result-${selected}` : undefined}
        placeholder="Name or target URL"
        class="min-h-12 w-full rounded-lg border border-wg-border bg-wg-surface px-4 text-base text-wg-text outline-none focus:border-wg-accent focus:ring-2 focus:ring-wg-accent/30"
        onkeydown={handleSearchKeydown}
    />
    <p role="status" aria-live="polite" class="mt-3 text-sm text-wg-text-muted">
        {#if search.trim().length < 2}Type at least two characters
        {:else if loading}Searching monitorings…
        {:else if failed}Search could not be loaded. Try again.
        {:else if results.length === 0}No matching monitorings
        {:else}Up to 10 results · Use arrow keys and Enter to open{/if}
    </p>
    <ul id="monitoring-search-results" role="listbox" aria-label="Matching monitorings" aria-busy={loading} class="mt-3 max-h-[50dvh] overflow-y-auto rounded-lg border border-wg-border empty:hidden">
        {#each results as result, index (result.id)}
            <li role="presentation">
                <a
                    id={`monitoring-search-result-${index}`}
                    role="option"
                    aria-selected={selected === index}
                    tabindex="-1"
                    href={`${appRoutes.monitorings}/${encodeURIComponent(result.id)}`}
                    class={`block border-b border-wg-border px-4 py-3 no-underline last:border-b-0 ${selected === index ? "bg-wg-surface-muted ring-1 ring-inset ring-wg-accent" : "hover:bg-wg-surface-muted"}`}
                    onclick={close}
                    onpointermove={() => (selected = index)}
                >
                    <span class="block truncate text-sm font-semibold text-wg-text">{result.name}</span>
                    <span class="mt-1 block truncate text-sm text-wg-text-muted">{result.target}</span>
                </a>
            </li>
        {/each}
    </ul>
</Dialog>
