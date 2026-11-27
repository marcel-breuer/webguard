<script lang="ts">
    import * as Card from "#lib/components/ui/card/index.ts";
    import type { Snippet } from "svelte";

    interface Props {
        title?: string;
        titleId?: string;
        description?: string;
        actions?: Snippet;
        class?: string;
        children?: Snippet;
    }

    let { title, titleId, description, actions, class: className = "", children }: Props = $props();
</script>

<Card.Root as="section" class={className}>
    {#if title || description || actions}
        <Card.Header>
            <div>
                {#if title}<Card.Title id={titleId} class="font-semibold">{title}</Card.Title>{/if}
                {#if description}<Card.Description>{description}</Card.Description>{/if}
            </div>
            {#if actions}<Card.Action>{@render actions()}</Card.Action>{/if}
        </Card.Header>
    {/if}
    <Card.Content>{@render children?.()}</Card.Content>
</Card.Root>
