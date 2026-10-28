<script lang="ts">
    import Field from "#lib/components/Field.svelte";
    import GuestAuthLayout from "#lib/components/GuestAuthLayout.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";

    interface AuthNavigation { next_url: string; }
</script>

<svelte:head><title>Confirm password | WebGuard</title></svelte:head>
<GuestAuthLayout title="Confirm your password" description="Confirm your password before continuing with this sensitive action.">
    <MutationForm action="/api/auth/confirm-password" submitLabel="Confirm password" successMessage="Password confirmed. Continuing…" onSuccess={(data) => window.location.assign((data as AuthNavigation).next_url)}>
        <Field label="Password" required><Input name="password" type="password" autocomplete="current-password" required /></Field>
    </MutationForm>
</GuestAuthLayout>
