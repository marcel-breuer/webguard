<script lang="ts">
    import { page } from "$app/state";
    import AppShell from "#lib/components/AppShell.svelte";
    import { requestFirstPartyApi } from "#lib/api/client.ts";
    import type { FirstPartySession } from "#lib/api/models.ts";
    import type { Snippet } from "svelte";

    interface Props {
        data: { session: FirstPartySession };
        children?: Snippet;
    }

    let { data, children }: Props = $props();

    async function signOut(): Promise<void> {
        await requestFirstPartyApi("/api/session/logout", { method: "POST" });
        window.location.assign("/login");
    }
</script>

<AppShell session={data.session} currentPath={page.url.pathname} onSignOut={signOut}>
    {@render children?.()}
</AppShell>
