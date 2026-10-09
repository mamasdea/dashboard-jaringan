<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Pemantauan" class="grid">
                    @can(\App\Enums\Izin::LihatDashboard->value)
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            Dashboard
                        </flux:sidebar.item>
                    @endcan
                    @can('viewAny', \App\Models\Tiket::class)
                        <flux:sidebar.item icon="ticket" :href="route('tiket.index')" :current="request()->routeIs('tiket.*')" wire:navigate>
                            Tiket gangguan
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Izin::LihatLaporan->value)
                        <flux:sidebar.item icon="document-chart-bar" :href="route('laporan.index')" :current="request()->routeIs('laporan.*')" wire:navigate>
                            Laporan
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @can(\App\Enums\Izin::LihatAset->value)
                    <flux:sidebar.group heading="Inventaris" class="grid">
                        <flux:sidebar.item icon="map" :href="route('peta')" :current="request()->routeIs('peta')" wire:navigate>
                            Peta jaringan
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office-2" :href="route('opd.index')" :current="request()->routeIs('opd.*') || request()->is('impor/opd')" wire:navigate>
                            OPD
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="server-stack" :href="route('perangkat.index')" :current="request()->routeIs('perangkat.*') || request()->is('impor/perangkat')" wire:navigate>
                            Perangkat
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="Fiber optik" class="grid">
                        <flux:sidebar.item icon="map-pin" :href="route('fo.tiang')" :current="request()->routeIs('fo.tiang*')" wire:navigate>
                            Tiang
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="share" :href="route('fo.titik')" :current="request()->routeIs('fo.titik*')" wire:navigate>
                            Titik jaringan
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="signal" :href="route('fo.kabel')" :current="request()->routeIs('fo.kabel*')" wire:navigate>
                            Segmen kabel
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="arrows-right-left" :href="route('fo.penelusuran')" :current="request()->routeIs('fo.penelusuran')" wire:navigate>
                            Penelusuran jalur
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @canany([\App\Enums\Izin::KelolaPengguna->value, \App\Enums\Izin::LihatLogAktivitas->value])
                    <flux:sidebar.group heading="Administrasi" class="grid">
                        @can('viewAny', \App\Models\User::class)
                            <flux:sidebar.item icon="users" :href="route('pengguna.index')" :current="request()->routeIs('pengguna.*')" wire:navigate>
                                Pengguna
                            </flux:sidebar.item>
                        @endcan
                        @can(\App\Enums\Izin::LihatLogAktivitas->value)
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('log-aktivitas.index')" :current="request()->routeIs('log-aktivitas.*')" wire:navigate>
                                Log aktivitas
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
