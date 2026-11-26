<script lang="ts">
    import { Alert } from "#lib/components/ui/alert/index.ts";
    import { afterNavigate } from "$app/navigation";
    import { Command as CommandPrimitive } from "bits-ui";
    import * as Command from "#lib/components/ui/command/index.ts";
    import { Input } from "#lib/components/ui/input/index.ts";
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

</script>

<svelte:window onkeydown={handleShortcut} />

<Dialog bind:open title="Search monitorings" description="Find a monitoring by name or target URL" initialFocus="#monitoring-search-input" layer="overlay" onclose={close}>
    <Command.Root shouldFilter={false} loop class="bg-transparent p-0">
        <CommandPrimitive.Input id="monitoring-search-input" value={search}>
            {#snippet child({ props })}
                <Input {...props} aria-label="Search monitorings" bind:value={search} type="search" maxlength={120} placeholder="Name or target URL" class="min-h-12 px-4 text-base" />
            {/snippet}
        </CommandPrimitive.Input>
        <Alert variant="inline" role="status" aria-live="polite" class="mt-3 text-sm text-wg-text-muted">
            {#if search.trim().length < 2}Type at least two characters
            {:else if loading}Searching monitorings…
            {:else if failed}Search could not be loaded. Try again.
            {:else if results.length === 0}No matching monitorings
            {:else}Up to 10 results · Use arrow keys and Enter to open{/if}
        </Alert>
        <Command.List id="monitoring-search-results" aria-label="Matching monitorings" aria-busy={loading} class={results.length === 0 ? "hidden" : "max-h-[50dvh]"}>
            <CommandPrimitive.Viewport>
                <Command.Group class="mt-3 rounded-lg border border-wg-border">
                    {#each results as result, index (result.id)}
                        <Command.LinkItem
                            id={`monitoring-search-result-${index}`}
                            value={result.id}
                            href={`${appRoutes.monitorings}/${encodeURIComponent(result.id)}`}
                            class="block border-b border-wg-border px-4 py-3 no-underline last:border-b-0"
                            onSelect={close}
                        >
                            <span class="block truncate text-sm font-semibold text-wg-text">{result.name}</span>
                            <span class="mt-1 block truncate text-sm text-wg-text-muted">{result.target}</span>
                        </Command.LinkItem>
                    {/each}
                </Command.Group>
            </CommandPrimitive.Viewport>
        </Command.List>
    </Command.Root>
</Dialog>
