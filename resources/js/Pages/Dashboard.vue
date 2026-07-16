<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectDashboard from '@sendtrap/core/Components/ProjectDashboard/ProjectDashboard.vue';

/**
 * Thin Community shell over the package's ProjectDashboard (Plan 06 Phase
 * 4b design §7.2): page furniture only. `upgradeUrl` is null — Community
 * has no billing page, so the package components' upgrade affordances
 * don't render (M-3). The Cloud shell's support form is dropped (parity
 * row 26 is Cloud-only — Community has no support.store route).
 */
const props = defineProps({
    projects: Array,
    usage: Object,
    upgradeUrl: { type: String, default: null },
});

const showNewProject = ref(false);

const totalInboxes = props.projects.reduce((n, p) => n + p.inboxes.length, 0);
</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900">Your inboxes</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ projects.length }} project{{ projects.length === 1 ? '' : 's' }} · {{ totalInboxes }} inbox{{ totalInboxes === 1 ? '' : 'es' }}</p>
                    </div>
                </div>
                <button @click="showNewProject = !showNewProject"
                    class="group relative overflow-hidden rounded-xl bg-gradient-brand px-5 py-2.5 text-sm font-semibold text-white shadow-glow transition hover:scale-[1.03]">
                    <span class="relative z-10">+ New Project</span>
                    <span class="absolute inset-0 w-1/3 skew-x-12 bg-white/30 animate-shine"></span>
                </button>
            </div>
        </template>

        <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <ProjectDashboard
                :projects="projects"
                :usage="usage"
                :upgrade-url="upgradeUrl"
                :show-new-project="showNewProject"
                @update:show-new-project="showNewProject = $event"
            />
        </div>
    </AppLayout>
</template>
