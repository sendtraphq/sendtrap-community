<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InboxSettings from '@sendtrap/core/Components/InboxSettings.vue';

/**
 * Thin Community shell over the package's InboxSettings (Plan 06 Phase 4b
 * design §7.2): `accessManageUrl`/`accessManageLabel` are null, so no manage
 * link renders. `accessTitle`/`accessDescription` are Community's own
 * workspace-neutral copy (Plan 06 Phase 3 gate finding #1) — core carries
 * no "team"/"workspace" vocabulary of its own (§7.5).
 */
defineProps({
    inbox: Object,
    accessTitle: { type: String, required: true },
    accessDescription: { type: String, required: true },
    accessManageUrl: { type: String, default: null },
    accessManageLabel: { type: String, default: null },
});
</script>

<template>
    <AppLayout :title="`${inbox.name} · Settings`">
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('inboxes.show', inbox.id)" class="grid h-9 w-9 place-items-center rounded-lg bg-white/70 text-slate-500 ring-1 ring-slate-200 transition hover:text-brand-600 hover:ring-brand-300">←</Link>
                    <h2 class="text-xl font-extrabold tracking-tight text-slate-900">{{ inbox.name }} — Settings</h2>
                </div>
                <span class="text-sm text-slate-400">Total messages: {{ inbox.messages_count ?? 0 }}</span>
            </div>
        </template>

        <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70" style="min-height: 60vh;">
                <InboxSettings
                    :inbox="inbox"
                    :access-title="accessTitle"
                    :access-description="accessDescription"
                    :access-manage-url="accessManageUrl"
                    :access-manage-label="accessManageLabel"
                />
            </div>
        </div>
    </AppLayout>
</template>
