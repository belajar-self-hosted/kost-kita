<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import Building2 from '@lucide/svelte/icons/building-2';
import CalendarCheck from '@lucide/svelte/icons/calendar-check';
import CalendarClock from '@lucide/svelte/icons/calendar-clock';
import CalendarRange from '@lucide/svelte/icons/calendar-range';
import ChartColumn from '@lucide/svelte/icons/chart-column';
import CreditCard from '@lucide/svelte/icons/credit-card';
import Receipt from '@lucide/svelte/icons/receipt';
import Wallet from '@lucide/svelte/icons/wallet';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
import UserRound from '@lucide/svelte/icons/user-round';
import UsersRound from '@lucide/svelte/icons/users-round';
import Wrench from '@lucide/svelte/icons/wrench';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { page } from '@inertiajs/svelte';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const user = $derived(page.props.auth.user);
    const mainNavItems: NavItem[] = $derived([
        { title: 'Dasbor', href: user?.role === 'super_admin' ? '/platform' : '/owner', icon: LayoutGrid },
        ...(user?.role === 'owner'
            ? [
                  { title: 'Properti', href: '/owner/properties', icon: Building2 },
                  { title: 'Penyewa', href: '/owner/residents', icon: UsersRound },
                  { title: 'Booking', href: '/owner/bookings', icon: CalendarCheck },
                  { title: 'Ketersediaan', href: '/owner/availability', icon: CalendarRange },
                  { title: 'Masa sewa', href: '/owner/tenancies', icon: CalendarClock },
                  { title: 'Check-in langsung', href: '/owner/direct-check-ins/create', icon: UserRound },
                  { title: 'Maintenance', href: '/owner/maintenance', icon: Wrench },
                  { title: 'Tagihan', href: '/owner/invoices', icon: Receipt },
                  { title: 'Penerimaan', href: '/owner/payments', icon: CreditCard },
                  { title: 'Deposit', href: '/owner/deposits', icon: Wallet },
                  { title: 'Biaya', href: '/owner/expenses', icon: Wallet },
                  { title: 'Laporan', href: '/owner/reports', icon: ChartColumn },
              ]
            : []),
    ]);

</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={user?.role === 'super_admin' ? '/platform' : '/owner'}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
