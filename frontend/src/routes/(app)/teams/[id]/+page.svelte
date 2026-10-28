<script lang="ts">
    import { formatNumber } from "#lib/i18n/format.ts";
    import Button from "#lib/components/Button.svelte";
    import Card from "#lib/components/Card.svelte";
    import Dialog from "#lib/components/Dialog.svelte";
    import Field from "#lib/components/Field.svelte";
    import Input from "#lib/components/Input.svelte";
    import MutationForm from "#lib/components/MutationForm.svelte";
    import Select from "#lib/components/Select.svelte";
    import Textarea from "#lib/components/Textarea.svelte";
    import type { TeamWorkspace } from "./+page.server";
    interface Props { data: { data: TeamWorkspace }; }
    let { data }: Props = $props();
    let editOpen = $state(false);
    let inviteOpen = $state(false);
    let leaveOpen = $state(false);
    let deleteOpen = $state(false);
    let deleteConfirmation = $state("");
    function reload(): void { window.location.reload(); }
</script>
<svelte:head><title>{data.data.name} | WebGuard</title></svelte:head>
<main class="mx-auto w-[min(70rem,calc(100%_-_2rem))] py-6 sm:py-12">
 <a class="text-sm font-bold text-wg-accent no-underline hover:underline" href="/teams">← Teams</a>
 <header class="mt-5 mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="m-0 text-[0.8125rem] font-extrabold tracking-[0.1em] text-wg-accent uppercase">Collaboration</p><h1 class="mt-2 text-[clamp(2rem,6vw,3rem)] leading-[1.1] font-bold">{data.data.name}</h1><p class="mt-3 text-wg-text-muted">{data.data.description ?? "No description provided."} · {formatNumber(data.data.monitoring_count)} monitorings</p></div>{#if data.data.can_manage}<div class="flex flex-wrap gap-3"><Button variant="secondary" onclick={() => editOpen = true}>Edit team</Button><Button onclick={() => inviteOpen = true}>Invite member</Button><Button variant="danger" onclick={() => deleteOpen = true}>Delete team</Button></div>{/if}</header>
 <div class="grid gap-6 lg:grid-cols-[1fr_0.8fr]"><Card title="Members" description="Roles determine who can manage this team."><div class="divide-y divide-wg-border rounded-xl border border-wg-border">{#each data.data.members as member (member.id)}<article class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-bold">{member.name}</p><p class="text-sm text-wg-text-muted">{member.email} · {member.role}</p></div>{#if data.data.can_manage}<div class="flex gap-2"><MutationForm action={`/api/teams/${data.data.id}/members/${member.id}`} method="PATCH" submitLabel={member.role === "admin" ? "Make member" : "Make admin"} onSuccess={reload}><Input name="role" type="hidden" value={member.role === "admin" ? "member" : "admin"} /></MutationForm><MutationForm action={`/api/teams/${data.data.id}/members/${member.id}`} method="DELETE" submitLabel="Remove" onSuccess={reload} /></div>{/if}</article>{/each}</div></Card>
 <div class="grid gap-6">{#if data.data.can_manage}<Card title="Pending invitations" description="Invitation links remain valid for seven days.">{#if data.data.invitations.length === 0}<p class="text-sm text-wg-text-muted">No pending invitations.</p>{:else}<div class="grid gap-3">{#each data.data.invitations as invitation (invitation.id)}<div class="flex items-center justify-between gap-3 rounded-xl border border-wg-border p-3"><span class="text-sm">{invitation.email} · {invitation.role}</span><MutationForm action={`/api/teams/${data.data.id}/invitations/${invitation.id}`} method="DELETE" submitLabel="Revoke" onSuccess={reload} /></div>{/each}</div>{/if}</Card>{/if}<Card title="Leave team" description="You can leave unless you are the last administrator."><Button variant="danger" onclick={() => leaveOpen = true}>Leave team</Button></Card></div></div>
</main>
<Dialog bind:open={editOpen} title="Edit team" description="Update the information shown to all team members."><MutationForm action={`/api/teams/${data.data.id}`} method="PATCH" submitLabel="Save team" successMessage="Team updated." onSuccess={reload}><Field label="Name" required><Input name="name" value={data.data.name} /></Field><Field label="Description"><Textarea class="min-h-28" name="description" value={data.data.description ?? ""} /></Field></MutationForm></Dialog>
<Dialog bind:open={inviteOpen} title="Invite member" description="The recipient receives a signed email invitation."><MutationForm action={`/api/teams/${data.data.id}/invitations`} submitLabel="Send invitation" successMessage="Invitation sent." onSuccess={reload}><Field label="Email" required><Input name="email" type="email" /></Field><Field label="Role"><Select name="role"><option value="member">Member</option><option value="admin">Admin</option></Select></Field></MutationForm></Dialog>
<Dialog bind:open={leaveOpen} title="Leave team" description="You will lose access to team monitorings."><MutationForm action={`/api/teams/${data.data.id}/leave`} method="DELETE" submitLabel="Leave team" onSuccess={() => window.location.assign("/teams")} /></Dialog>
<Dialog bind:open={deleteOpen} title="Delete team" description="This cannot be undone. The team, its memberships and invitations, and all {formatNumber(data.data.monitoring_count)} team monitorings with their data will be permanently deleted. Monitoring groups and status pages remain, but will no longer include these monitorings."><MutationForm action={`/api/teams/${data.data.id}`} method="DELETE" submitLabel="Permanently delete team" submitAsJson submitDisabled={deleteConfirmation !== data.data.name} successMessage="Team deleted." onSuccess={() => window.location.assign("/teams")}><Field label={`Type ${data.data.name} to confirm`} required><Input name="confirmation" bind:value={deleteConfirmation} autocomplete="off" required /></Field></MutationForm></Dialog>
