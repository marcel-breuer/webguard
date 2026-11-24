<script lang="ts">
    import { onDestroy, untrack } from "svelte";
    import { toast } from "svelte-sonner";
    import { Toaster } from "#lib/components/ui/sonner/index.ts";
    import ToastNotification from "#lib/components/ToastNotification.svelte";

    export type ToastTone = "success" | "error" | "info";
    export interface ToastMessage { id: string; tone: ToastTone; message: string; }
    interface Props { items?: ToastMessage[]; onDismiss?: (id: string) => void; }
    let { items = [], onDismiss }: Props = $props();

    const regionId = $props.id();
    let displayed = new Map<string, ToastMessage>();
    const toastId = (id: string) => `${regionId}-${id}`;

    $effect(() => {
        const next = new Map(items.map((item) => [item.id, { ...item }]));

        untrack(() => {
            for (const id of displayed.keys()) {
                if (!next.has(id)) toast.dismiss(toastId(id));
            }

            for (const item of next.values()) {
                const previous = displayed.get(item.id);
                if (previous?.message === item.message && previous.tone === item.tone) continue;

                toast.custom(ToastNotification, {
                    id: toastId(item.id),
                    toasterId: regionId,
                    duration: Infinity,
                    dismissible: false,
                    unstyled: true,
                    componentProps: { item, dismiss: () => onDismiss?.(item.id) },
                });
            }

            displayed = next;
        });
    });

    onDestroy(() => {
        for (const id of displayed.keys()) toast.dismiss(toastId(id));
    });
</script>

<Toaster id={regionId} containerAriaLabel="Notifications" position="bottom-right" expand
    visibleToasts={Math.max(1, items.length)} gap={12} offset={16} />
