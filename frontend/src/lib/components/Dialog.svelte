<script module lang="ts">
    let openDialogCount = 0;

    function lockBackgroundScroll(): () => void {
        openDialogCount += 1;
        document.documentElement.dataset.dialogOpen = "true";

        return () => {
            openDialogCount = Math.max(0, openDialogCount - 1);

            if (openDialogCount === 0) {
                delete document.documentElement.dataset.dialogOpen;
            }
        };
    }

</script>

<script lang="ts">
    import * as Dialog from "#lib/components/ui/dialog/index.ts";
    import type { Snippet } from "svelte";

    interface Props {
        open?: boolean;
        title: string;
        description?: string;
        closeLabel?: string;
        size?: "default" | "wide";
        initialFocus?: string;
        layer?: "default" | "overlay";
        children?: Snippet;
        onclose?: () => void;
    }

    let {
        open = $bindable(false),
        title,
        description,
        closeLabel = "Close dialog",
        size = "default",
        initialFocus,
        layer = "default",
        children,
        onclose,
    }: Props = $props();
    let panel = $state<HTMLDivElement | null>(null);

    $effect(() => {
        if (open) return lockBackgroundScroll();
    });

    function focusPanel(event: Event): void {
        event.preventDefault();
        const target = initialFocus ? panel?.querySelector<HTMLElement>(initialFocus) : null;
        (target ?? panel)?.focus();
    }

    function keepFocusedControlVisible(event: FocusEvent): void {
        if (event.target instanceof HTMLElement) {
            event.target.scrollIntoView({ block: "nearest" });
        }
    }
</script>

<Dialog.Root bind:open onOpenChange={(nextOpen) => { if (!nextOpen) onclose?.(); }}>
    <Dialog.Content
        bind:ref={panel}
        {size}
        {layer}
        showCloseButton={false}
        preventScroll={false}
        preventBodyPointerEvents={false}
        onOpenAutoFocus={focusPanel}
        onfocusin={keepFocusedControlVisible}
    >
        <Dialog.Header>
            <div>
                <Dialog.Title>{title}</Dialog.Title>
                {#if description}<Dialog.Description>{description}</Dialog.Description>{/if}
            </div>
            <Dialog.Close
                class="inline-grid size-9 shrink-0 place-items-center rounded-md border border-wg-border bg-transparent text-2xl leading-none text-wg-text enabled:hover:bg-wg-surface-muted"
                aria-label={closeLabel}
            >×</Dialog.Close>
        </Dialog.Header>
        <div class="min-h-0 overflow-auto p-4 sm:p-6">{@render children?.()}</div>
    </Dialog.Content>
</Dialog.Root>
