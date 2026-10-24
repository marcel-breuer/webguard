<script lang="ts">
    import { onMount } from "svelte";
    import { appRoutes } from "$lib/routes";
    import { requestFirstPartyApi } from "$lib/api/client";
    import { formatNumber } from "$lib/i18n/format";
    import AppearanceSelector from "$lib/components/AppearanceSelector.svelte";
    import LocaleSelector from "$lib/components/LocaleSelector.svelte";
    import NavIcon, { type NavIconName } from "$lib/components/NavIcon.svelte";
    import type { FirstPartySession } from "$lib/api/models";
    import type { Snippet } from "svelte";

    interface NavigationItem {
        href: string;
        icon: NavIconName;
        label: string;
    }

    interface Props {
        session: FirstPartySession;
        currentPath: string;
        children?: Snippet;
        onSignOut?: () => void | Promise<void>;
    }

    const operations: NavigationItem[] = [
        { href: appRoutes.monitorings, icon: "monitoring", label: "Monitorings" },
        { href: appRoutes.monitoringGroups, icon: "group", label: "Monitoring groups" },
        { href: appRoutes.statusPages, icon: "file", label: "Status pages" },
        { href: appRoutes.incidents, icon: "chart", label: "Incidents" },
        { href: appRoutes.maintenance, icon: "wrench", label: "Maintenance" },
    ];
    const collaboration: NavigationItem[] = [{ href: appRoutes.teams, icon: "team", label: "Teams" }];
    const administration: NavigationItem[] = [
        { href: appRoutes.adminDashboard, icon: "dashboard", label: "Dashboard" },
        { href: appRoutes.adminUsers, icon: "users", label: "Users" },
        { href: appRoutes.adminPackages, icon: "package", label: "Packages" },
        { href: appRoutes.adminInstances, icon: "archive", label: "Server instances" },
        { href: appRoutes.adminApi, icon: "api", label: "API access" },
        { href: appRoutes.adminActivityLogs, icon: "activity", label: "Activity logs" },
    ];

    let { session, currentPath, children, onSignOut }: Props = $props();
    let appearanceOpen = $state(false);
    let collapsed = $state(false);
    let isMobileViewport = $state(false);
    let localeOpen = $state(false);
    let mobileOpen = $state(false);
    let unreadNotificationCount = $state(0);

    const collapseStorageKey = "webguard.sidebar.collapsed";
    const notificationCountEvent = "webguard:notifications-count";
    const isAdmin = $derived(session.user.role === "admin");

    function setUnreadNotificationCount(value: unknown): void {
        if (typeof value === "number" && Number.isFinite(value) && value >= 0) {
            unreadNotificationCount = Math.floor(value);
        }
    }

    function handleNotificationCountEvent(event: Event): void {
        setUnreadNotificationCount((event as CustomEvent<number>).detail);
    }

    onMount(() => {
        void requestFirstPartyApi<unknown, { unread_count: number }>("/api/notifications?limit=1&show_read=0")
            .then((payload) => setUnreadNotificationCount(payload.meta?.unread_count))
            .catch(() => undefined);

        window.addEventListener(notificationCountEvent, handleNotificationCountEvent);
        collapsed = localStorage.getItem(collapseStorageKey) === "true";
        const mediaQuery = window.matchMedia("(max-width: 54rem)");
        const syncViewport = (): void => {
            isMobileViewport = mediaQuery.matches;

            if (!isMobileViewport) {
                mobileOpen = false;
            }
        };

        syncViewport();
        mediaQuery.addEventListener("change", syncViewport);

        return () => {
            mediaQuery.removeEventListener("change", syncViewport);
            window.removeEventListener(notificationCountEvent, handleNotificationCountEvent);
        };
    });

    $effect(() => {
        if (!isMobileViewport || !mobileOpen) {
            return;
        }

        document.documentElement.dataset.mobileNavigationOpen = "true";

        return () => delete document.documentElement.dataset.mobileNavigationOpen;
    });

    function toggleCollapsed(): void {
        collapsed = !collapsed;
        localStorage.setItem(collapseStorageKey, String(collapsed));
    }

    function isActive(href: string): boolean {
        return currentPath === href || (href !== appRoutes.dashboard && currentPath.startsWith(`${href}/`));
    }

    function handleWindowKeydown(event: KeyboardEvent): void {
        if (event.key === "Escape") {
            mobileOpen = false;
        }
    }

    function closeMobileNavigation(): void {
        mobileOpen = false;
    }

    function toggleMobileNavigation(): void {
        mobileOpen = !mobileOpen;
    }
</script>

<svelte:window onkeydown={handleWindowKeydown} />

<a href="#main-content" class="sr-only fixed top-3 left-3 z-[60] rounded-md bg-wg-accent px-4 py-2 text-sm font-bold text-wg-accent-contrast no-underline focus:not-sr-only">Skip to main content</a>

<div class={`min-h-screen transition-[padding] duration-150 ${collapsed ? "pl-[5.5rem] max-[54rem]:pl-0" : "pl-68 max-[54rem]:pl-0"}`}>
    <aside id="app-navigation" inert={isMobileViewport && !mobileOpen} aria-hidden={isMobileViewport && !mobileOpen ? "true" : undefined} class={`fixed inset-y-0 left-0 z-20 flex flex-col border-r border-purple-900 bg-purple-950 text-purple-100 transition-[width,transform] duration-150 max-[54rem]:w-[min(18rem,calc(100vw_-_3.5rem))] max-[54rem]:-translate-x-full max-[54rem]:shadow-wg-surface ${collapsed ? "w-[5.5rem]" : "w-68"} ${mobileOpen ? "max-[54rem]:translate-x-0" : ""}`} aria-label="Application navigation">
        <div class={collapsed ? "flex min-h-18 items-center justify-center gap-1 px-1.5" : "flex min-h-18 items-center justify-between gap-2 px-3.5"}>
            <a class="flex min-w-0 items-center gap-3 font-extrabold tracking-tight no-underline" href={appRoutes.dashboard} aria-label="WebGuard dashboard">
                <img class="size-9 shrink-0 rounded-[0.65rem] bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" />
                <span class={collapsed ? "sr-only" : "transition-opacity duration-100 max-[54rem]:not-sr-only"}>WebGuard</span>
            </a>
            <button class="grid size-9 shrink-0 place-items-center rounded-[0.65rem] border border-purple-700 bg-transparent text-xl text-purple-100 max-[54rem]:hidden" type="button" aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"} title={collapsed ? "Expand sidebar" : "Collapse sidebar"} onclick={toggleCollapsed}>
                <span aria-hidden="true">{collapsed ? "›" : "‹"}</span>
            </button>
            <button class="hidden size-9 shrink-0 place-items-center rounded-[0.65rem] border border-purple-700 bg-transparent text-xl text-purple-100 max-[54rem]:grid" type="button" aria-label="Close navigation" title="Close navigation" onclick={closeMobileNavigation}>×</button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4">
            <a class={`mt-1 flex min-h-11 items-center rounded-[0.65rem] border text-sm font-semibold no-underline ${collapsed ? "justify-center px-0" : "gap-3 px-3"} ${isActive(appRoutes.dashboard) ? "border-purple-400 bg-purple-800 text-white" : "border-transparent text-purple-200 hover:border-purple-700 hover:bg-purple-900 hover:text-white"}`} href={appRoutes.dashboard} aria-current={isActive(appRoutes.dashboard) ? "page" : undefined} aria-label="Dashboard" title="Dashboard" onclick={closeMobileNavigation}>
                <NavIcon name="home" /> <span class={collapsed ? "sr-only" : ""}>Dashboard</span>
            </a>
            <a class={`mt-1 flex min-h-11 items-center rounded-[0.65rem] border text-sm font-semibold no-underline ${collapsed ? "justify-center px-0" : "gap-3 px-3"} ${isActive(appRoutes.notifications) ? "border-purple-400 bg-purple-800 text-white" : "border-transparent text-purple-200 hover:border-purple-700 hover:bg-purple-900 hover:text-white"}`} href={appRoutes.notifications} aria-current={isActive(appRoutes.notifications) ? "page" : undefined} aria-label={unreadNotificationCount > 0 ? `Notifications, ${formatNumber(unreadNotificationCount)} unread` : "Notifications"} title={unreadNotificationCount > 0 ? `Notifications (${formatNumber(unreadNotificationCount)} unread)` : "Notifications"} onclick={closeMobileNavigation}>
                <span class="relative grid size-5 shrink-0 place-items-center"><NavIcon name="bell" />{#if unreadNotificationCount > 0}<span class="absolute -top-2 left-1/2 min-w-4 -translate-x-1/2 rounded-full bg-red-500 px-1 text-center text-[0.625rem] leading-4 font-extrabold text-white ring-2 ring-purple-950" aria-hidden="true">{unreadNotificationCount > 99 ? `${formatNumber(99)}+` : formatNumber(unreadNotificationCount)}</span>{/if}</span> <span class={collapsed ? "sr-only" : ""}>Notifications</span>
            </a>

            {@render NavSection("Operations", operations)}
            {@render NavSection("Collaboration", collaboration)}
            {#if isAdmin}{@render NavSection("Administration", administration)}{/if}
        </nav>

        <div class="grid gap-3 border-t border-purple-900 p-3.5">
            <div class="flex flex-wrap items-center gap-2">
                <AppearanceSelector initialTheme={session.user.theme} bind:open={appearanceOpen} onOpen={() => (localeOpen = false)} />
                <LocaleSelector initialLocale={session.user.locale} bind:open={localeOpen} onOpen={() => (appearanceOpen = false)} />
            </div>
            <div class="grid gap-2">
                <a href={appRoutes.profile} class={collapsed ? "flex min-h-11 items-center justify-center rounded-md px-0 text-sm font-bold no-underline" : "flex min-h-11 items-center gap-3 rounded-md px-1 text-sm font-bold no-underline"} aria-label="Profile" title="Profile">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-purple-700 text-white">{session.user.name.slice(0, 1).toUpperCase()}</span>
                    <span class={collapsed ? "sr-only" : "max-[54rem]:not-sr-only"}>{session.user.name}</span>
                </a>
                <button type="button" class={collapsed ? "sr-only" : "min-h-11 rounded-md border-0 bg-transparent px-1 text-left text-[0.8125rem] text-purple-200 max-[54rem]:not-sr-only"} onclick={() => onSignOut?.()}>Sign out</button>
            </div>
        </div>
    </aside>

    {#if mobileOpen}
        <button type="button" class="fixed inset-0 z-10 hidden bg-slate-950/45 max-[54rem]:block" aria-label="Close navigation" onclick={closeMobileNavigation}></button>
    {/if}

    <header class="hidden min-h-16 items-center justify-between border-b border-wg-border bg-wg-surface px-4 max-[54rem]:flex">
        <a class="flex items-center gap-3 font-extrabold tracking-tight no-underline" href={appRoutes.dashboard} aria-label="WebGuard dashboard"><img class="size-9 rounded-[0.65rem] bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" /><span>WebGuard</span></a>
        <button type="button" class="grid size-11 place-items-center rounded-[0.65rem] border border-wg-border bg-transparent text-xl text-wg-text" aria-controls="app-navigation" aria-expanded={mobileOpen} aria-label="Toggle navigation" onclick={toggleMobileNavigation}>☰</button>
    </header>

    <main id="main-content" tabindex="-1" class="min-w-0 outline-none">{@render children?.()}</main>
</div>

{#snippet NavSection(label: string, items: NavigationItem[])}
    <section class="mt-7" aria-label={label}>
        <h2 class={collapsed ? "sr-only" : "mb-2 px-3 text-[0.6875rem] tracking-[0.12em] text-purple-300 uppercase max-[54rem]:not-sr-only"}>{label}</h2>
        {#each items as item}
            <a class={`mt-1 flex min-h-11 items-center rounded-[0.65rem] border text-sm font-semibold no-underline ${collapsed ? "justify-center px-0" : "gap-3 px-3"} ${isActive(item.href) ? "border-purple-400 bg-purple-800 text-white" : "border-transparent text-purple-200 hover:border-purple-700 hover:bg-purple-900 hover:text-white"}`} href={item.href} aria-current={isActive(item.href) ? "page" : undefined} aria-label={item.label} title={item.label} onclick={closeMobileNavigation}>
                <NavIcon name={item.icon} /> <span class={collapsed ? "sr-only" : ""}>{item.label}</span>
            </a>
        {/each}
    </section>
{/snippet}
