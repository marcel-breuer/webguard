<script lang="ts">
    import Field from "#lib/components/Field.svelte";
    import GuestAuthLayout from "#lib/components/GuestAuthLayout.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";

    interface Props { data: { email: string; token: string }; }

    let { data }: Props = $props();
</script>

<svelte:head><title>Choose a new password | WebGuard</title></svelte:head>
<GuestAuthLayout title="Choose a new password" description="Use a unique password that you do not use on any other service.">
    <MutationForm action="/api/auth/reset-password" submitLabel="Reset password" successMessage="Password updated. Redirecting to sign in…" onSuccess={() => window.location.assign("/login?reset=1")}>
        <Input name="token" type="hidden" value={data.token} />
        <Field label="Email" required><Input name="email" type="email" autocomplete="username" value={data.email} required /></Field>
        <Field label="New password" required><Input name="password" type="password" autocomplete="new-password" required /></Field>
        <Field label="Confirm new password" required><Input name="password_confirmation" type="password" autocomplete="new-password" required /></Field>
    </MutationForm>
</GuestAuthLayout>
