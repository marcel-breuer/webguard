<script lang="ts">
    import MenuIcon from "@lucide/svelte/icons/menu";
    import XIcon from "@lucide/svelte/icons/x";
    import ChevronLeftIcon from "@lucide/svelte/icons/chevron-left";
    import ChevronRightIcon from "@lucide/svelte/icons/chevron-right";
    import { Separator } from "#lib/components/ui/separator/index.ts";
    import { Kbd } from "#lib/components/ui/kbd/index.ts";
    import * as Avatar from "#lib/components/ui/avatar/index.ts";
    import * as DropdownMenu from "#lib/components/ui/dropdown-menu/index.ts";
    import * as Sheet from "#lib/components/ui/sheet/index.ts";
    import * as Sidebar from "#lib/components/ui/sidebar/index.ts";
    import { Badge } from "#lib/components/ui/badge/index.ts";
    import { Button } from "#lib/components/ui/button/index.ts";
    import { onMount } from "svelte";
    import { appRoutes } from "#lib/routes.ts";
    import { requestFirstPartyApi } from "#lib/api/client.ts";
    import { formatNumber } from "#lib/i18n/format.ts";
    import MonitoringSearch from "#lib/components/MonitoringSearch.svelte";
    import AppearanceSelector from "#lib/components/AppearanceSelector.svelte";
    import LocaleSelector from "#lib/components/LocaleSelector.svelte";
    import NavIcon, { type NavIconName } from "#lib/components/NavIcon.svelte";
    import type { FirstPartySession } from "#lib/api/models.ts";
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
    let monitoringSearchOpen = $state(false);
    let unreadNotificationCount = $state(0);

    const collapseStorageKey = "webguard.sidebar.collapsed";
    const notificationCountEvent = "webguard:notifications-count";
    const compact = $derived(collapsed && !isMobileViewport);
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

    function setCollapsed(value: boolean): void {
        collapsed = value;
        localStorage.setItem(collapseStorageKey, String(collapsed));
    }

    function isActive(href: string): boolean {
        return currentPath === href || (href !== appRoutes.dashboard && currentPath.startsWith(`${href}/`));
    }

    function closeMobileNavigation(): void {
        mobileOpen = false;
    }

    function openMonitoringSearch(): void {
        closeMobileNavigation();
        monitoringSearchOpen = true;
    }
</script>
<MonitoringSearch bind:open={monitoringSearchOpen} />

<Button href="#main-content" class="sr-only fixed top-3 left-3 z-[60] min-h-0 border-0 px-4 py-2 font-bold tracking-normal no-underline shadow-none focus:not-sr-only">Skip to main content</Button>

<Sidebar.Provider open={!collapsed} onOpenChange={(open) => setCollapsed(!open)}>
    <div class={`min-h-screen w-full min-w-0 transition-[padding] duration-150 ${collapsed ? "pl-[5.5rem] max-[54rem]:pl-0" : "pl-64 max-[54rem]:pl-0"}`}>
        {#if !isMobileViewport}
            <Sidebar.Root collapsible="none" class={`fixed inset-y-0 left-0 z-20 border-r border-wg-border transition-[width] duration-150 max-[54rem]:hidden ${collapsed ? "w-[5.5rem]" : "w-64"}`}>
                <Sidebar.Root as="aside" collapsible="none" variant="navigation" id="app-navigation" class="w-full" aria-label="Application navigation">{@render Navigation()}</Sidebar.Root>
            </Sidebar.Root>
        {/if}

        <Sheet.Root bind:open={mobileOpen}>
            <Sidebar.Header variant="bar" inert={mobileOpen} class="hidden min-h-16 items-center justify-between px-4 max-[54rem]:flex">
                <a class="flex items-center gap-3 font-semibold no-underline" href={appRoutes.dashboard} aria-label="WebGuard dashboard"><img class="size-9 rounded-lg bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" /><span>WebGuard</span></a>
                <Button variant="ghost" class="ml-auto mr-2 size-11 border-wg-border p-0" aria-label="Search monitorings" aria-haspopup="dialog" onclick={openMonitoringSearch}><NavIcon name="search" /></Button>
                <Sheet.Trigger>
                    {#snippet child({ props })}
                        <Button {...props} variant="ghost" class="size-11 border-wg-border p-0" aria-controls="app-navigation" aria-expanded={mobileOpen} aria-label="Toggle navigation"><MenuIcon class="size-[18px]" /></Button>
                    {/snippet}
                </Sheet.Trigger>
            </Sidebar.Header>
            {#if isMobileViewport}
                <!-- Keep the controlled element mounted and let the mobile-navigation dataset own the scroll lock. -->
                <Sheet.Content side="left" id="app-navigation" forceMount preventScroll={false} showCloseButton={false}
                    class="hidden border-wg-border p-0 data-[side=left]:w-[min(18rem,calc(100vw_-_3.5rem))] data-[state=open]:flex"
                    inert={isMobileViewport && !mobileOpen} aria-describedby={undefined} portalProps={{ to: "#localized-app" }}
                    onCloseAutoFocus={(event) => { if (monitoringSearchOpen) event.preventDefault(); }}>
                    <Sheet.Title class="sr-only">Application navigation</Sheet.Title>
                    <Sidebar.Root collapsible="none" variant="navigation" class="min-h-0 w-auto">{@render Navigation()}</Sidebar.Root>
                </Sheet.Content>
            {/if}
        </Sheet.Root>

        <main id="main-content" tabindex="-1" inert={mobileOpen} class="min-w-0 outline-none">{@render children?.()}</main>
    </div>
</Sidebar.Provider>

{#snippet Navigation()}
    <Sidebar.Header class={compact ? "flex min-h-16 flex-row items-center justify-center gap-1 px-1.5" : "flex min-h-16 flex-row items-center justify-between gap-2 px-3.5"}>
        <a class="flex min-w-0 items-center gap-3 font-semibold no-underline" href={appRoutes.dashboard} aria-label="WebGuard dashboard" onclick={closeMobileNavigation}>
            <img class="size-9 shrink-0 rounded-lg bg-white object-contain p-0.5" src="/brand/webguard-logo.png" alt="" />
            <span class={compact ? "sr-only" : "transition-opacity duration-100 max-[54rem]:not-sr-only"}>WebGuard</span>
        </a>
        <Button variant="ghost" class="grid size-9 min-h-9 shrink-0 place-items-center p-0 max-[54rem]:hidden" type="button" aria-label={compact ? "Expand sidebar" : "Collapse sidebar"} title={compact ? "Expand sidebar" : "Collapse sidebar"} onclick={() => setCollapsed(!collapsed)}>
            {#if compact}<ChevronRightIcon class="size-4" />{:else}<ChevronLeftIcon class="size-4" />{/if}
        </Button>
        {#if isMobileViewport}
            <Sheet.Close>
                {#snippet child({ props })}
                    <Button {...props} variant="ghost" class="size-10 min-h-10 shrink-0 p-0" aria-label="Close navigation"><XIcon class="size-[18px]" /></Button>
                {/snippet}
            </Sheet.Close>
        {/if}
    </Sidebar.Header>

    <Sidebar.Content class="px-3 py-4">
        <nav>
            <Sidebar.MenuButton class={`wg-glass-control min-h-10 rounded-lg border border-wg-border text-wg-text-muted hover:bg-wg-surface-muted hover:text-wg-text ${compact ? "justify-center px-0" : "gap-3 px-3"}`}
                aria-label="Search monitorings" aria-keyshortcuts="Meta+K" aria-haspopup="dialog"
                tooltipContent={compact ? "Search monitorings" : undefined} tooltipContentProps={{ role: "tooltip", portalProps: { to: "#localized-app" } }} onclick={openMonitoringSearch}>
                <NavIcon name="search" />
                <span class={compact ? "sr-only" : "min-w-0 flex-1 text-left"}>Search</span>
                {#if !compact}<Kbd class="h-auto min-w-0 shrink-0 rounded border border-wg-border bg-wg-surface-muted px-1 font-mono text-xs font-normal text-wg-text-muted" aria-hidden="true">⌘K</Kbd>{/if}
            </Sidebar.MenuButton>
            <Sidebar.Menu class="gap-0">
                {@render NavItem({ href: appRoutes.dashboard, icon: "home", label: "Dashboard" })}
                {@render NavItem({ href: appRoutes.notifications, icon: "bell", label: "Notifications" })}
            </Sidebar.Menu>
            {@render NavSection("Operations", operations)}
            {@render NavSection("Collaboration", collaboration)}
            {#if isAdmin}{@render NavSection("Administration", administration)}{/if}
        </nav>
    </Sidebar.Content>

    <Sidebar.Footer class="relative grid gap-3 p-3">
        <Separator class="absolute inset-x-0 top-0 bg-wg-border" />
        <div class="flex flex-wrap items-center gap-2">
            <AppearanceSelector initialTheme={session.user.theme} bind:open={appearanceOpen} onOpen={() => (localeOpen = false)} />
            <LocaleSelector initialLocale={session.user.locale} bind:open={localeOpen} onOpen={() => (appearanceOpen = false)} />
        </div>
        <DropdownMenu.Root>
            <DropdownMenu.Trigger>
                {#snippet child({ props })}
                    <Sidebar.MenuButton {...props} class={`min-h-11 text-wg-text ${compact ? "justify-center px-0" : "gap-3 px-1"}`} aria-label="Profile" title="Profile">
                        <Avatar.Root class="size-8 shrink-0"><Avatar.Fallback class="bg-wg-surface-muted text-wg-text">{session.user.name.slice(0, 1).toUpperCase()}</Avatar.Fallback></Avatar.Root>
                        <span class={compact ? "sr-only" : ""}>{session.user.name}</span>
                    </Sidebar.MenuButton>
                {/snippet}
            </DropdownMenu.Trigger>
            <DropdownMenu.Content side={compact ? "right" : "top"} portalProps={{ to: "#localized-app", disabled: isMobileViewport }}>
                <DropdownMenu.Group>
                    <DropdownMenu.Item>
                        {#snippet child({ props })}
                            <a {...props} href={appRoutes.profile} aria-current={isActive(appRoutes.profile) ? "page" : undefined} onclick={closeMobileNavigation}>Profile</a>
                        {/snippet}
                    </DropdownMenu.Item>
                    <DropdownMenu.Item onSelect={() => onSignOut?.()}>Sign out</DropdownMenu.Item>
                </DropdownMenu.Group>
            </DropdownMenu.Content>
        </DropdownMenu.Root>
    </Sidebar.Footer>
{/snippet}

{#snippet NavSection(label: string, items: NavigationItem[])}
    <Sidebar.Group class="mt-7 p-0" aria-label={label}>
        <h2 class={compact ? "sr-only" : "mb-2 px-3 text-xs font-medium text-wg-text-muted"}>{label}</h2>
        <Sidebar.Menu class="gap-0">
            {#each items as item (item.href)}{@render NavItem(item)}{/each}
        </Sidebar.Menu>
    </Sidebar.Group>
{/snippet}

{#snippet NavItem(item: NavigationItem)}
    {@const notifications = item.href === appRoutes.notifications}
    {@const label = notifications && unreadNotificationCount > 0 ? `Notifications, ${formatNumber(unreadNotificationCount)} unread` : item.label}
    <Sidebar.MenuItem>
        <Sidebar.MenuButton isActive={isActive(item.href)} data-active={isActive(item.href) ? "true" : undefined} tooltipContent={compact ? label : undefined}
            tooltipContentProps={{ role: "tooltip", portalProps: { to: "#localized-app" } }}
            class={`mt-1 min-h-10 overflow-visible rounded-lg border border-transparent font-medium text-wg-text-muted no-underline transition-colors hover:border-wg-border hover:bg-wg-surface-muted hover:text-wg-text data-active:border-wg-border data-active:bg-wg-surface data-active:text-wg-text data-active:shadow-[inset_2px_0_0_var(--wg-accent)] ${compact ? "justify-center px-0" : "gap-3 px-3"}`}>
            {#snippet child({ props })}
                <a {...props} href={item.href} aria-current={isActive(item.href) ? "page" : undefined} aria-label={label} onclick={closeMobileNavigation}>
                    <span class="relative grid size-5 shrink-0 place-items-center">
                        <NavIcon name={item.icon} />
                        {#if notifications && unreadNotificationCount > 0}
                            <Badge class="absolute -top-2 left-1/2 h-4 min-w-4 -translate-x-1/2 justify-center border border-wg-border bg-wg-status-down px-1 font-mono text-[0.625rem] font-semibold text-wg-status-down-foreground" aria-hidden="true">{unreadNotificationCount > 99 ? `${formatNumber(99)}+` : formatNumber(unreadNotificationCount)}</Badge>
                        {/if}
                    </span>
                    <span class={compact ? "sr-only" : ""}>{item.label}</span>
                </a>
            {/snippet}
        </Sidebar.MenuButton>
    </Sidebar.MenuItem>
{/snippet}
