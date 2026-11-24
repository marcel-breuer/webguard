<script lang="ts">
    import { Button } from "#lib/components/ui/button/index.ts";
    import * as Pagination from "#lib/components/ui/pagination/index.ts";
    import { formatNumber } from "#lib/i18n/format.ts";

    interface Props { page: number; pages: number; href: (page: number) => string; }
    let { page, pages, href }: Props = $props();
    const firstPage = $derived(Math.min(Math.max(1, page - 2), Math.max(1, pages - 4)));
    const adjacent = $derived(Array.from({ length: Math.min(pages, 5) }, (_, index) => firstPage + index));
    const linkClass = "min-h-10 min-w-10 rounded-lg px-3 py-2 no-underline";
</script>

{#if pages > 1}
    <nav aria-label="Pagination">
        <Pagination.Content class="flex-wrap gap-2">
            <Pagination.Item>
                <Button href={page > 1 ? href(page - 1) : undefined} disabled={page <= 1} variant="ghost" class={`${linkClass} border-wg-border`}>Previous</Button>
            </Pagination.Item>
            {#each adjacent as current (current)}
                <Pagination.Item>
                    <Button href={href(current)} variant={current === page ? "default" : "ghost"} class={`${linkClass} ${current === page ? "" : "border-wg-border"}`} aria-current={current === page ? "page" : undefined}>{formatNumber(current)}</Button>
                </Pagination.Item>
            {/each}
            <Pagination.Item>
                <Button href={page < pages ? href(page + 1) : undefined} disabled={page >= pages} variant="ghost" class={`${linkClass} border-wg-border`}>Next</Button>
            </Pagination.Item>
        </Pagination.Content>
    </nav>
{/if}
