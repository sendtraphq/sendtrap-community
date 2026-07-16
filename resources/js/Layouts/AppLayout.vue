<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@sendtrap/core/Components/ApplicationMark.vue';
import ConfirmDialog from '@sendtrap/core/Components/ConfirmDialog.vue';
import Dropdown from '@sendtrap/core/Components/Dropdown.vue';
import DropdownLink from '@sendtrap/core/Components/DropdownLink.vue';
import NavLink from '@sendtrap/core/Components/NavLink.vue';
import ResponsiveNavLink from '@sendtrap/core/Components/ResponsiveNavLink.vue';

/**
 * Community AppLayout (Plan 06 Phase 4b design §7.1) — authored for
 * Community, structured after the Cloud layout but with every Team/billing
 * construct stripped: no team switcher, no Teams dropdown, no Billing
 * link, no API-tokens link (none of those routes exist here; leaving them
 * would throw in Ziggy's route()).
 *
 * Nav (final): Dashboard · Docs · Users (owner) · Settings (owner) ·
 * account dropdown → Profile, Logout. Users/Settings are owner-gated on
 * `$page.props.auth.user.role` (shared by HandleInertiaRequests). The
 * Docs/Users/Settings items additionally render only once their routes
 * exist (slices 6–7 register them; `route().has()` keeps this layout
 * final while those slices land routes only).
 */
defineProps({
    title: String,
});

const showingNavigationDropdown = ref(false);

const page = usePage();
const isOwner = computed(() => page.props.auth?.user?.role === 'owner');

const hasRoute = (name) => route().has(name);

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />

        <ConfirmDialog />

        <div class="relative min-h-screen bg-slate-50 text-slate-800">
            <!-- Branded backdrop -->
            <div class="pointer-events-none fixed inset-0 -z-10">
                <div class="absolute inset-0 bg-gradient-to-b from-brand-50 via-slate-50 to-white"></div>
                <div class="absolute -top-24 right-10 h-80 w-80 rounded-full bg-brand-200/30 blur-3xl"></div>
                <div class="absolute bottom-0 left-10 h-72 w-72 rounded-full bg-sky-200/30 blur-3xl"></div>
            </div>

            <nav class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-slate-200/70">
                <!-- Primary Navigation Menu -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <Link :href="route('dashboard')" class="flex items-center gap-2">
                                    <ApplicationMark class="block h-9 w-9" />
                                    <span class="text-lg font-extrabold tracking-tight text-slate-900">Sendtrap</span>
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                    Dashboard
                                </NavLink>

                                <NavLink v-if="hasRoute('docs.api')" :href="route('docs.api')" :active="route().current('docs.api')">
                                    Docs
                                </NavLink>

                                <NavLink v-if="isOwner && hasRoute('users.index')" :href="route('users.index')" :active="route().current('users.*')">
                                    Users
                                </NavLink>

                                <NavLink v-if="isOwner && hasRoute('settings')" :href="route('settings')" :active="route().current('settings')">
                                    Settings
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:flex sm:items-center sm:ms-6">
                            <!-- Account Dropdown -->
                            <div class="ms-3 relative">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none focus:bg-gray-50 active:bg-gray-50 transition ease-in-out duration-150">
                                                {{ $page.props.auth.user.name }}

                                                <svg class="ms-2 -me-0.5 size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <!-- Account Management -->
                                        <div class="block px-4 py-2 text-xs text-gray-400">
                                            Manage Account
                                        </div>

                                        <DropdownLink :href="route('profile.show')">
                                            Profile
                                        </DropdownLink>

                                        <div class="border-t border-gray-200" />

                                        <!-- Authentication -->
                                        <form @submit.prevent="logout">
                                            <DropdownLink as="button">
                                                Log Out
                                            </DropdownLink>
                                        </form>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out" @click="showingNavigationDropdown = ! showingNavigationDropdown">
                                <svg
                                    class="size-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{'hidden': showingNavigationDropdown, 'inline-flex': ! showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{'hidden': ! showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div :class="{'block': showingNavigationDropdown, 'hidden': ! showingNavigationDropdown}" class="sm:hidden">
                    <div class="pt-2 pb-3 space-y-1">
                        <ResponsiveNavLink :href="route('dashboard')" :active="route().current('dashboard')">
                            Dashboard
                        </ResponsiveNavLink>

                        <ResponsiveNavLink v-if="hasRoute('docs.api')" :href="route('docs.api')" :active="route().current('docs.api')">
                            Docs
                        </ResponsiveNavLink>

                        <ResponsiveNavLink v-if="isOwner && hasRoute('users.index')" :href="route('users.index')" :active="route().current('users.*')">
                            Users
                        </ResponsiveNavLink>

                        <ResponsiveNavLink v-if="isOwner && hasRoute('settings')" :href="route('settings')" :active="route().current('settings')">
                            Settings
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Account Options -->
                    <div class="pt-4 pb-1 border-t border-gray-200">
                        <div class="flex items-center px-4">
                            <div>
                                <div class="font-medium text-base text-gray-800">
                                    {{ $page.props.auth.user.name }}
                                </div>
                                <div class="font-medium text-sm text-gray-500">
                                    {{ $page.props.auth.user.email }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.show')" :active="route().current('profile.show')">
                                Profile
                            </ResponsiveNavLink>

                            <!-- Authentication -->
                            <form method="POST" @submit.prevent="logout">
                                <ResponsiveNavLink as="button">
                                    Log Out
                                </ResponsiveNavLink>
                            </form>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading (transparent — sits on the page background) -->
            <header v-if="$slots.header">
                <div class="max-w-7xl mx-auto pt-8 pb-2 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
