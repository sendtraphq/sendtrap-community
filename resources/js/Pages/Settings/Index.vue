<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMessage from '@sendtrap/core/Components/ActionMessage.vue';
import InputError from '@sendtrap/core/Components/InputError.vue';
import InputLabel from '@sendtrap/core/Components/InputLabel.vue';
import PrimaryButton from '@sendtrap/core/Components/PrimaryButton.vue';
import TextInput from '@sendtrap/core/Components/TextInput.vue';

/**
 * Owner-only instance Settings page (Plan 06 Phase 4b design §4.6, §8 row
 * 10) — Community-authored, consuming the package form atoms. Workspace
 * name + the instance IP allowlist (writes `workspace.allowed_ips`; core's
 * `Inbox::effectiveAllowedIps()` already enforces it for SMTP AUTH and
 * inbox-token API requests), plus a read-only display of the active local
 * limits from config (limits are config, not DB — §5; install/env changes
 * them). The allowlist editor mirrors the package InboxSettings pattern:
 * one rule per line, split/trimmed on submit.
 */
const props = defineProps({
    workspace: Object,
    limits: Object,
});

const form = useForm({
    name: props.workspace.name,
    allowed_ips: props.workspace.allowed_ips || [],
});

const ipsText = ref((props.workspace.allowed_ips || []).join('\n'));

const submit = () => {
    form.allowed_ips = ipsText.value.split('\n').map((s) => s.trim()).filter(Boolean);
    form.put(route('settings.update'), { preserveScroll: true });
};

const limitRows = [
    ['sends_per_minute', 'Sends per minute'],
    ['sends_per_month', 'Sends per month'],
    ['forwards_per_month', 'Forwards per month'],
    ['email_size_bytes', 'Max email size (bytes)'],
    ['projects', 'Projects'],
    ['inboxes', 'Inboxes'],
    ['users', 'Users'],
    ['messages_per_inbox', 'Messages per inbox'],
    ['retention_days', 'Retention (days)'],
    ['storage_bytes', 'Storage (bytes)'],
    ['api_requests_per_minute', 'API requests per minute'],
];

// D-17 semantics: null/absent = unlimited, 0 = blocked, n = that many.
const displayLimit = (value) => {
    if (value === null || value === undefined) return 'Unlimited';
    if (value === 0) return 'Blocked (0)';
    return value.toLocaleString();
};

const ipErrors = () => form.errors.allowed_ips
    ?? Object.entries(form.errors).find(([key]) => key.startsWith('allowed_ips.'))?.[1];
</script>

<template>
    <AppLayout title="Settings">
        <template #header>
            <div>
                <h2 class="text-2xl font-extrabold tracking-tight text-slate-900">Instance settings</h2>
                <p class="mt-1 text-sm text-slate-500">Workspace identity, access restrictions and the active local limits.</p>
            </div>
        </template>

        <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Workspace name + instance allowlist -->
            <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70">
                <form class="p-6" @submit.prevent="submit">
                    <h3 class="text-lg font-bold text-slate-900">Workspace</h3>

                    <div class="mt-5 max-w-md">
                        <InputLabel for="workspace-name" value="Workspace name" />
                        <TextInput id="workspace-name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>

                    <div class="mt-6">
                        <InputLabel for="instance-allowed-ips" value="Instance IP allowlist" />
                        <p class="mt-1 text-sm text-slate-500">
                            One IP address or CIDR range per line (IPv4 or IPv6). When set, SMTP and API access to every inbox
                            without its own inbox- or project-level allowlist is restricted to these addresses. Leave empty for no restriction.
                        </p>
                        <textarea
                            id="instance-allowed-ips"
                            v-model="ipsText"
                            rows="5"
                            spellcheck="false"
                            placeholder="203.0.113.7&#10;198.51.100.0/24"
                            class="mt-2 block w-full max-w-md rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        ></textarea>
                        <InputError class="mt-1" :message="ipErrors()" />
                    </div>

                    <div class="mt-5 flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing">Save settings</PrimaryButton>
                        <ActionMessage :on="form.recentlySuccessful">Saved.</ActionMessage>
                    </div>
                </form>
            </div>

            <!-- Read-only active limits (§4.6: limits are config, not DB) -->
            <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-slate-900">Active limits</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Read-only — limits come from this instance's configuration (<code class="text-xs">SENDTRAP_*</code> environment variables), not the database.
                    </p>

                    <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                        <div v-for="[key, label] in limitRows" :key="key" class="flex items-baseline justify-between gap-4 border-b border-slate-100 pb-2">
                            <dt class="text-sm text-slate-500">{{ label }}</dt>
                            <dd class="text-sm font-semibold" :class="limits[key] === 0 ? 'text-red-600' : 'text-slate-900'">
                                {{ displayLimit(limits[key]) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
