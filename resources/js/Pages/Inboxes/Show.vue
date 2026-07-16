<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import MessageReader from '@sendtrap/core/Components/MessageReader/MessageReader.vue';

/**
 * Thin Community shell over the package's MessageReader (Plan 06 Phase 4b
 * design §7.2). `accessManageUrl`/`accessManageLabel`/`upgradeUrl` are null
 * — Community has no team-management page and no billing page; the package
 * components hide those affordances when absent (M-3 / §7.5).
 * `accessTitle`/`accessDescription` are Community's own workspace-neutral
 * copy (Plan 06 Phase 3 gate finding #1: core carries no "team"/"workspace"
 * vocabulary of its own — every host supplies it).
 */
defineProps({
    inbox: Object,
    messages: Object, // { data, links, meta }
    accessTitle: { type: String, required: true },
    accessDescription: { type: String, required: true },
    accessManageUrl: { type: String, default: null },
    accessManageLabel: { type: String, default: null },
    usage: Object,
    upgradeUrl: { type: String, default: null },
});
</script>

<template>
    <AppLayout :title="inbox.name">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
            <MessageReader
                :inbox="inbox"
                :messages="messages"
                :access-title="accessTitle"
                :access-description="accessDescription"
                :access-manage-url="accessManageUrl"
                :access-manage-label="accessManageLabel"
                :usage="usage"
                :upgrade-url="upgradeUrl"
            />
        </div>
    </AppLayout>
</template>
