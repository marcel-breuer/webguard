<script lang="ts">
    import { Separator } from "#lib/components/ui/separator/index.ts";
    import { Card as Surface } from "#lib/components/ui/card/index.ts";
    import { Label } from "#lib/components/ui/label/index.ts";
    import { FieldGroup } from "#lib/components/ui/field/index.ts";
    import * as Alert from "#lib/components/ui/alert/index.ts";
    import { FirstPartyApiError, requestFirstPartyApi } from "#lib/api/client.ts";
    import type { AuthOptions } from "#lib/server/auth.ts";
    import Button from "#lib/components/Button.svelte";
    import Checkbox from "#lib/components/Checkbox.svelte";
    import Field from "#lib/components/Field.svelte";
    import GuestAuthLayout from "#lib/components/GuestAuthLayout.svelte";
    import Input from "#lib/components/Input.svelte";

    type AuthMode = "login" | "register" | "demo";

    interface AuthNavigation {
        next_url: string;
    }

    interface Props {
        options: AuthOptions;
        initialMode?: AuthMode;
        initialEmail?: string;
        initialLocale?: string;
        expired?: boolean;
        notice?: string;
        showPreferences?: boolean;
        showLegalLinks?: boolean;
    }

    let {
        options,
        initialMode = "login",
        initialEmail = "",
        initialLocale = "en",
        expired = false,
        notice = "",
        showPreferences = false,
        showLegalLinks = false,
    }: Props = $props();
    let mode = $state<AuthMode>("login");
    let email = $state("");
    let password = $state("");
    let submitting = $state(false);
    let loadingDemo = $state(false);
    let message = $state("");
    let errors = $state<Record<string, string[]>>({});
    let captchaNonce = $state(Date.now());
    let initialized = $state(false);

    const captchaUrl = $derived(`${options.captcha_url}?${captchaNonce}`);

    $effect.pre(() => {
        if (initialized) {
            return;
        }

        mode = initialMode;
        email = initialEmail;
        message = notice || (expired ? "Your session has expired. Sign in again to continue." : "");
        initialized = true;
    });

    async function selectMode(nextMode: AuthMode): Promise<void> {
        mode = nextMode;
        message = "";
        errors = {};

        if (nextMode !== "demo") {
            return;
        }

        loadingDemo = true;
        try {
            const response = await requestFirstPartyApi<{ email: string }>("/api/auth/demo-credentials");
            email = response.data.email;
            password = "password";
        } catch (exception) {
            message = exception instanceof FirstPartyApiError ? exception.message : "Demo credentials could not be loaded.";
        } finally {
            loadingDemo = false;
        }
    }

    async function submit(event: SubmitEvent): Promise<void> {
        event.preventDefault();

        if (submitting) {
            return;
        }

        submitting = true;
        message = "";
        errors = {};

        try {
            const form = event.currentTarget as HTMLFormElement;
            const endpoint = mode === "register" ? "/api/auth/register" : "/api/auth/login";
            const response = await requestFirstPartyApi<AuthNavigation>(endpoint, {
                body: new FormData(form),
                method: "POST",
            });

            window.location.assign(response.data.next_url);
        } catch (exception) {
            if (exception instanceof FirstPartyApiError) {
                errors = exception.errors;
                message = exception.message;
            } else {
                message = "The request could not be completed. Please try again.";
            }
        } finally {
            submitting = false;
        }
    }

    function errorFor(field: string): string | undefined {
        return errors[field]?.[0];
    }
</script>

<GuestAuthLayout title={mode === "register" ? "Create your account" : "Welcome to WebGuard"} description={mode === "register" ? "Start monitoring the services your team depends on." : "Sign in to manage your monitorings, status pages, and notifications."} initialLocale={initialLocale} showPreferences={showPreferences}>
    <Surface variant="inset" class="mb-7 grid grid-cols-3 gap-2 overflow-visible rounded-xl border-0 bg-wg-surface-muted p-1.5 shadow-none" aria-label="Authentication mode">
        <Button variant={mode === "login" ? "primary" : "quiet"} onclick={() => selectMode("login")}>Sign in</Button>
        <Button variant={mode === "register" ? "primary" : "quiet"} onclick={() => selectMode("register")}>Register</Button>
        <Button variant={mode === "demo" ? "primary" : "quiet"} loading={loadingDemo} onclick={() => selectMode("demo")}>Demo</Button>
    </Surface>

    {#if message}
        <Alert.Root class="mb-5" variant={Object.keys(errors).length > 0 ? "destructive" : "default"} role={Object.keys(errors).length > 0 ? "alert" : "status"}>
            <Alert.Description>{message}</Alert.Description>
        </Alert.Root>
    {/if}

    <form onsubmit={submit} novalidate>
        <FieldGroup class="grid gap-5">
            {#if mode === "register"}
                <Field label="Name" required error={errorFor("name")}><Input name="name" autocomplete="name" required /></Field>
            {/if}
            <Field label="Email" required error={errorFor("email")}><Input name="email" type="email" autocomplete={mode === "register" ? "username" : "email"} bind:value={email} required /></Field>
            <Field label="Password" required error={errorFor("password")}><Input name="password" type="password" autocomplete={mode === "register" ? "new-password" : "current-password"} bind:value={password} required /></Field>
            {#if mode === "login" || mode === "demo"}
                <Label class="flex items-center gap-2 text-sm text-wg-text-muted"><Checkbox name="remember" value="1" /> Keep me signed in</Label>
                <a class="text-sm font-bold text-wg-accent no-underline hover:underline" href="/forgot-password">Forgot your password?</a>
            {:else}
                <Field label="Confirm password" required error={errorFor("password_confirmation")}><Input name="password_confirmation" type="password" autocomplete="new-password" required /></Field>
                <Label class="flex items-start gap-3 text-sm leading-6 text-wg-text-muted"><Checkbox class="mt-1" name="terms" value="1" required /><span>I agree to the <a class="font-bold text-wg-accent" href={options.terms_url} target="_blank" rel="noopener">terms of use</a> and <a class="font-bold text-wg-accent" href={options.privacy_url} target="_blank" rel="noopener">privacy policy</a>.</span></Label>
                <Field label="Security check" required error={errorFor("captcha")}><div class="flex flex-col gap-3 sm:flex-row sm:items-center"><img class="h-[54px] w-[220px] rounded-md border border-wg-border object-cover" src={captchaUrl} alt="Security check" /><Button type="button" variant="secondary" onclick={() => (captchaNonce = Date.now())}>Reload</Button></div><Input name="captcha" autocomplete="off" inputmode="text" required /></Field>
            {/if}
            <Button type="submit" loading={submitting}>{mode === "register" ? "Create account" : "Sign in"}</Button>
        </FieldGroup>
    </form>
    {#if showLegalLinks}
        <nav class="relative mt-8 flex flex-wrap justify-center gap-x-5 gap-y-2 pt-5 text-sm font-bold" aria-label="Legal links">
            <Separator class="absolute inset-x-0 top-0 bg-wg-border" />
            <a class="text-wg-text-muted no-underline hover:text-wg-accent hover:underline" href={options.imprint_url} target="_blank" rel="noopener">Imprint</a>
            <a class="text-wg-text-muted no-underline hover:text-wg-accent hover:underline" href={options.privacy_url} target="_blank" rel="noopener">Privacy Policy</a>
        </nav>
    {/if}
</GuestAuthLayout>
