<script lang="ts">
    interface Props {
        locations: Array<{ code: string; name: string }>;
    }

    let { locations }: Props = $props();
</script>

{#if locations.length > 0}
    <ul class="m-0 flex flex-wrap gap-2 p-0" aria-label="Monitoring locations">
        {#each locations as location (location.code)}
            <li class="inline-flex items-center gap-2 rounded-full bg-wg-surface-muted px-2.5 py-1 text-xs font-bold">
                <span class="size-1.5 rounded-full bg-wg-accent" aria-hidden="true"></span>
                {location.name}
            </li>
        {/each}
    </ul>
{/if}
