<script lang="ts">
    import { tick } from "svelte";
    import type { Snippet } from "svelte";

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

    interface Props {
        open?: boolean;
        title: string;
        description?: string;
        closeLabel?: string;
        size?: "default" | "wide";
        children?: Snippet;
        onclose?: () => void;
    }

    let {
        open = $bindable(false),
        title,
        description,
        closeLabel = "Close dialog",
        size = "default",
        children,
        onclose,
    }: Props = $props();
    let panel = $state<HTMLElement | null>(null);
    let previouslyFocused: HTMLElement | null = null;

    $effect(() => {
        if (!open) {
            return;
        }

        previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const unlockBackgroundScroll = lockBackgroundScroll();

        void tick().then(() => {
            panel?.focus();
        });

        return () => {
            unlockBackgroundScroll();

            if (previouslyFocused?.isConnected) {
                previouslyFocused.focus();
            }

            previouslyFocused = null;
        };
    });

    function close(): void {
        open = false;
        onclose?.();
    }

    function focusableElements(): HTMLElement[] {
        if (!panel) {
            return [];
        }

        return Array.from(
            panel.querySelectorAll<HTMLElement>(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((element) => element.getClientRects().length > 0);
    }

    function handleKeydown(event: KeyboardEvent): void {
        if (event.key === "Escape") {
            event.preventDefault();
            close();
            return;
        }

        if (event.key !== "Tab") {
            return;
        }

        const elements = focusableElements();

        if (elements.length === 0) {
            event.preventDefault();
            panel?.focus();
            return;
        }

        const first = elements[0];
        const last = elements[elements.length - 1];
        const activeElement = document.activeElement;

        if (event.shiftKey && (activeElement === first || !panel?.contains(activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (activeElement === last || !panel?.contains(activeElement))) {
            event.preventDefault();
            first.focus();
        }
    }

    function keepFocusedControlVisible(event: FocusEvent): void {
        if (event.target instanceof HTMLElement) {
            event.target.scrollIntoView({ block: "nearest" });
        }
    }
</script>

{#if open}
    <div class="fixed inset-0 z-50 grid place-items-center p-2 sm:p-4">
        <button type="button" tabindex="-1" class="absolute inset-0 z-0 cursor-default bg-slate-900/65" aria-hidden="true" onclick={close}></button>
        <div
            bind:this={panel}
            aria-label={title}
            aria-modal="true"
            role="dialog"
            tabindex="-1"
            class={`relative z-10 grid max-h-[calc(100dvh-1rem)] w-full grid-rows-[auto_minmax(0,1fr)] overflow-hidden rounded-2xl border border-wg-border wg-glass-panel wg-glass-overlay text-wg-text shadow-wg-surface outline-none sm:max-h-[calc(100dvh-2rem)] ${size === "wide" ? "max-w-5xl" : "max-w-2xl"}`}
            onkeydown={handleKeydown}
            onfocusin={keepFocusedControlVisible}
        >
            <header class="flex items-start justify-between gap-3 border-b border-wg-border px-4 py-4 sm:gap-4 sm:px-6 sm:py-5">
                <div>
                    <h2 class="text-xl leading-7 font-bold">{title}</h2>
                    {#if description}<p class="mt-[0.35rem] text-sm leading-5 text-wg-text-muted">{description}</p>{/if}
                </div>
                <button
                    type="button"
                    class="inline-grid size-9 shrink-0 place-items-center rounded-md border border-wg-border bg-transparent text-2xl leading-none text-wg-text enabled:hover:bg-wg-surface-muted"
                    aria-label={closeLabel}
                    onclick={close}
                >×</button>
            </header>
            <div class="min-h-0 overflow-auto p-4 sm:p-6">{@render children?.()}</div>
        </div>
    </div>
{/if}
