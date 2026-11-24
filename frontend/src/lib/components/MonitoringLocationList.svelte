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
                <Badge variant="neutral" class="gap-2 rounded-full px-2.5 py-1 font-bold text-wg-text">
                    <span class="size-1.5 rounded-full bg-wg-accent" aria-hidden="true"></span>
                    {location.name}
                </Badge>
            </li>
        {/each}
    </ul>
{/if}
