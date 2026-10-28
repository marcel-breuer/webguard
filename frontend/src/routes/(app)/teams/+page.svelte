<script lang="ts">
    import { formatNumber } from "#lib/i18n/format.ts";
    import Card from "#lib/components/Card.svelte";
    import EmptyState from "#lib/components/EmptyState.svelte";
    import StatusBadge from "#lib/components/StatusBadge.svelte";
    import Button from "#lib/components/Button.svelte";
    import Dialog from "#lib/components/Dialog.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";
    import Textarea from "#lib/components/Textarea.svelte";

    interface TeamSummary { id: string; name: string; description: string | null; member_count: number; monitoring_count: number; role: "admin" | "member"; }
    interface Props { data: { data: { teams: TeamSummary[] } }; }
    let { data }: Props = $props();
    const teams = $derived(data.data.teams);
    let createOpen = $state(false);

    function refreshTeams(): void { window.location.reload(); }
</script>

<svelte:head><title>Teams | WebGuard</title></svelte:head>
<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
    <header class="mb-8 flex flex-col items-start justify-between gap-4 sm:flex-row">
        <div>
            <p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Collaboration</p>
            <h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">Teams</h1>
            <span class="mt-3 block text-wg-text-muted">Manage the monitorings and members you collaborate with.</span>
        </div>
        <Button onclick={() => (createOpen = true)}>Create team</Button>
    </header>
    {#if teams.length === 0}
        <EmptyState title="No teams yet" description="Create a team to share monitoring ownership and collaborate with colleagues." />
    {:else}
        <div class="grid grid-cols-[repeat(auto-fit,minmax(17rem,1fr))] gap-4">
            {#each teams as team (team.id)}
                <Card title={team.name} description={team.description ?? "No description provided."}>
                    {#snippet actions()}<StatusBadge tone={team.role === "admin" ? "healthy" : "neutral"} label={team.role === "admin" ? "Admin" : "Member"} />{/snippet}
                    <dl class="m-0 grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-[0.8125rem] text-wg-text-muted">Members</dt>
                            <dd class="mt-1 text-xl font-extrabold">{formatNumber(team.member_count)}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.8125rem] text-wg-text-muted">Monitorings</dt>
                            <dd class="mt-1 text-xl font-extrabold">{formatNumber(team.monitoring_count)}</dd>
                        </div>
                    </dl>
                    <a class="mt-5 inline-flex text-sm font-bold text-wg-accent no-underline hover:underline" href={`/teams/${team.id}`}>Open workspace →</a>
                </Card>
            {/each}
        </div>
    {/if}
</main>
<Dialog bind:open={createOpen} title="Create team" description="You will become this team's first administrator.">
    <MutationForm action="/api/teams" submitLabel="Create team" successMessage="Team created. Refreshing…" onSuccess={refreshTeams}>
        <Field label="Name" required><Input name="name" autocomplete="organization" /></Field>
        <Field label="Description"><Textarea name="description" rows={3} /></Field>
    </MutationForm>
</Dialog>
