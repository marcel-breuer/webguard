<script lang="ts">
    import { requestFirstPartyApi } from "#lib/api/client.ts";
    import Button from "#lib/components/Button.svelte";
    import GuestAuthLayout from "#lib/components/GuestAuthLayout.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";
    import type { FirstPartySession } from "#lib/api/models.ts";

    interface Props { data: { session: FirstPartySession }; }
    let { data }: Props = $props();

    async function signOut(): Promise<void> {
        await requestFirstPartyApi("/api/session/logout", { method: "POST" });
        window.location.assign("/login");
    }
</script>

<svelte:head><title>Verify your email | WebGuard</title></svelte:head>
<GuestAuthLayout title="Verify your email" description={`We sent a verification link to ${data.session.user.email}. Open it to activate your workspace.`}>
    <MutationForm action="/api/auth/email/verification-notification" submitLabel="Resend verification link" successMessage="A new verification link has been sent." />
    <div class="mt-4"><Button variant="quiet" onclick={signOut}>Sign out</Button></div>
</GuestAuthLayout>
