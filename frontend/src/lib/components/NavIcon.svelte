<script lang="ts">
    export type NavIconName =
        | "activity"
        | "arrow-left"
        | "api"
        | "archive"
        | "bell"
        | "chart"
        | "dashboard"
        | "ellipsis"
        | "eye"
        | "external"
        | "file"
        | "globe"
        | "group"
        | "home"
        | "monitoring"
        | "moon"
        | "package"
        | "pencil"
        | "search"
        | "settings"
        | "sun"
        | "system"
        | "team"
        | "users"
        | "wrench";

    interface Props {
        name: NavIconName;
        size?: number;
    }

    let { name, size = 20 }: Props = $props();
</script>

<svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {#if name === "home"}
        <path d="m3 10 9-7 9 7" /><path d="M5 9.5V21h14V9.5M9 21v-6h6v6" />
    {:else if name === "search"}
        <circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" />
    {:else if name === "arrow-left"}
        <path d="M19 12H5M11 18l-6-6 6-6" />
    {:else if name === "monitoring"}
        <path d="M4 5h16v12H4z" /><path d="m7 13 3-3 2 2 4-4" /><path d="M8 21h8M12 17v4" />
    {:else if name === "group" || name === "team" || name === "users"}
        <circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0 1 12 0M16 5.5a3 3 0 0 1 0 5.8M17 14a5 5 0 0 1 4 6" />
    {:else if name === "chart"}
        <path d="M4 19V5M4 19h16" /><path d="m7 15 3-4 3 2 5-6" />
    {:else if name === "wrench"}
        <path d="m14.7 6.3 3-3a4 4 0 0 0-5 5L4 17a2.1 2.1 0 0 0 3 3l8.8-8.7a4 4 0 0 0 5-5l-3 3-3.1-.9-.9-3.1Z" />
    {:else if name === "dashboard"}
        <rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" />
    {:else if name === "ellipsis"}
        <circle cx="5" cy="12" r="1" fill="currentColor" /><circle cx="12" cy="12" r="1" fill="currentColor" /><circle cx="19" cy="12" r="1" fill="currentColor" />
    {:else if name === "eye"}
        <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="2.5" />
    {:else if name === "external"}
        <path d="M14 4h6v6M20 4l-9 9" /><path d="M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5" />
    {:else if name === "package"}
        <path d="m4 8 8-4 8 4-8 4-8-4Z" /><path d="M4 8v8l8 4 8-4V8M12 12v8" />
    {:else if name === "pencil"}
        <path fill="currentColor" stroke="none" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Zm14.85-9.46 1.85-1.85a1 1 0 0 0 0-1.41l-.28-.28a1 1 0 0 0-1.41 0l-1.85 1.85 3.75 3.75Z" />
    {:else if name === "archive"}
        <path d="M4 4h16v4H4zM5 8h14v12H5z" /><path d="M9 12h6" />
    {:else if name === "api"}
        <path d="m8 9-3 3 3 3M16 9l3 3-3 3M14 5l-4 14" />
    {:else if name === "file"}
        <path d="M5 3h10l4 4v14H5z" /><path d="M15 3v5h5M8 12h8M8 16h5" />
    {:else if name === "bell"}
        <path d="M14.857 17.082a2.75 2.75 0 0 1-5.714 0m11.104-2.108c-1.086-1.3-1.747-2.95-1.747-4.755V9.5a6.5 6.5 0 0 0-13 0v.719c0 1.805-.661 3.455-1.747 4.755A1.25 1.25 0 0 0 4.713 17h14.574a1.25 1.25 0 0 0 .96-2.026Z" />
    {:else if name === "settings"}
        <circle cx="12" cy="12" r="3.5" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    {:else if name === "globe"}
        <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
    {:else if name === "sun"}
        <circle cx="12" cy="12" r="3.5" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    {:else if name === "moon"}
        <path d="M20.5 15.3A8.5 8.5 0 0 1 8.7 3.5 8.5 8.5 0 1 0 20.5 15.3Z" />
    {:else if name === "system"}
        <rect x="3.5" y="4" width="17" height="12" rx="1.5" /><path d="M8.5 20h7M12 16v4" />
    {:else if name === "activity"}
        <path d="M3 12h4l2-7 4 14 2-7h6" />
    {/if}
</svg>
