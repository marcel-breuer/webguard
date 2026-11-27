<script lang="ts">
    import { Badge } from "#lib/components/ui/badge/index.ts";
    interface Props {
        locations: Array<{ code: string; name: string }>;
    }

    let { locations }: Props = $props();
</script>

{#if locations.length > 0}
    <ul class="m-0 flex flex-wrap gap-2 p-0" aria-label="Monitoring locations">
        {#each locations as location (location.code)}
            <li>
                <Badge variant="neutral" class="gap-2 rounded-lg px-2.5 py-1 font-medium text-wg-text">
                    <Badge variant="dot" class="size-1.5 bg-wg-accent" aria-hidden="true" />
                    {location.name}
                </Badge>
            </li>
        {/each}
    </ul>
{/if}
