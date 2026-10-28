<script lang="ts">
    import Field from "#lib/components/Field.svelte";
    import GuestAuthLayout from "#lib/components/GuestAuthLayout.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";

</script>

<svelte:head><title>Reset password | WebGuard</title></svelte:head>
<GuestAuthLayout title="Reset your password" description="Enter your email address and we will send you a password reset link.">
    <MutationForm action="/api/auth/forgot-password" submitLabel="Email reset link" successMessage="If the account exists, a reset link has been sent.">
        <Field label="Email" required><Input name="email" type="email" autocomplete="email" required /></Field>
    </MutationForm>
    <a class="mt-6 inline-flex text-sm font-bold text-wg-accent no-underline hover:underline" href="/login">Back to sign in</a>
</GuestAuthLayout>
