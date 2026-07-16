<script setup>
import { computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMessage from '@sendtrap/core/Components/ActionMessage.vue';
import DangerButton from '@sendtrap/core/Components/DangerButton.vue';
import InputError from '@sendtrap/core/Components/InputError.vue';
import InputLabel from '@sendtrap/core/Components/InputLabel.vue';
import PrimaryButton from '@sendtrap/core/Components/PrimaryButton.vue';
import TextInput from '@sendtrap/core/Components/TextInput.vue';
import { confirm } from '@sendtrap/core/confirm.js';

/**
 * Owner-only Users page (Plan 06 Phase 4b design §4.6) — Community-authored
 * (no package equivalent), consuming the package form atoms. List users
 * with role badges, create a user with a role select (an owner action, not
 * public registration), change a role in place, delete with confirm.
 *
 * The server is the authority for every rule (UserPolicy: owner-only;
 * last-owner delete/demote guard; self-delete allowed unless last owner,
 * with the confirm copy warning about the logout).
 */
const props = defineProps({
    users: Array,
    roles: Array,
    usersLimit: { type: Number, default: null },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);

const atLimit = computed(
    () => props.usersLimit !== null && props.users.length >= props.usersLimit,
);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member',
});

const createUser = () => {
    createForm.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const changeRole = (user, event) => {
    const role = event.target.value;
    router.put(route('users.update', user.id), { role }, {
        preserveScroll: true,
        onError: () => {
            // Snap the select back — the server refused (e.g. last-owner
            // demote guard).
            event.target.value = user.role;
        },
    });
};

const deleteUser = async (user) => {
    const self = user.id === currentUserId.value;
    if (!(await confirm({
        title: self ? 'Delete your own account' : 'Delete user',
        message: self
            ? 'Your account will be deleted and you will be logged out immediately. This cannot be undone.'
            : `${user.name} (${user.email}) will lose access immediately. This cannot be undone.`,
        confirmText: self ? 'Delete my account' : 'Delete user',
    }))) return;

    router.delete(route('users.destroy', user.id), { preserveScroll: true });
};

const roleBadgeClass = (role) => ({
    owner: 'bg-brand-100 text-brand-700 ring-brand-200',
    member: 'bg-sky-100 text-sky-700 ring-sky-200',
    viewer: 'bg-slate-100 text-slate-600 ring-slate-200',
}[role] ?? 'bg-slate-100 text-slate-600 ring-slate-200');
</script>

<template>
    <AppLayout title="Users">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-extrabold tracking-tight text-slate-900">Users</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ users.length }} user{{ users.length === 1 ? '' : 's' }}<span v-if="usersLimit !== null"> of {{ usersLimit }} allowed</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- User list -->
            <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70">
                <ul class="divide-y divide-slate-100">
                    <li v-for="user in users" :key="user.id" class="flex flex-wrap items-center gap-3 px-6 py-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold text-slate-900">{{ user.name }}</span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1" :class="roleBadgeClass(user.role)">
                                    {{ user.role }}
                                </span>
                                <span v-if="user.id === currentUserId" class="text-xs text-slate-400">(you)</span>
                            </div>
                            <div class="truncate text-sm text-slate-500">{{ user.email }}</div>
                        </div>

                        <div class="flex items-center gap-3">
                            <select
                                :value="user.role"
                                class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                :aria-label="`Role for ${user.name}`"
                                @change="changeRole(user, $event)"
                            >
                                <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                            </select>

                            <DangerButton @click="deleteUser(user)">
                                Delete
                            </DangerButton>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Create user -->
            <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70">
                <form class="p-6" @submit.prevent="createUser">
                    <h3 class="text-lg font-bold text-slate-900">Add a user</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Created accounts are active immediately — hand the credentials over directly. Users can change their own password from their profile.
                    </p>

                    <div v-if="atLimit" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">
                        This instance's user limit ({{ usersLimit }}) has been reached.
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="new-user-name" value="Name" />
                            <TextInput id="new-user-name" v-model="createForm.name" type="text" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="createForm.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="new-user-email" value="Email" />
                            <TextInput id="new-user-email" v-model="createForm.email" type="email" class="mt-1 block w-full" required />
                            <InputError class="mt-1" :message="createForm.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="new-user-password" value="Password" />
                            <TextInput id="new-user-password" v-model="createForm.password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                            <InputError class="mt-1" :message="createForm.errors.password" />
                        </div>

                        <div>
                            <InputLabel for="new-user-role" value="Role" />
                            <select
                                id="new-user-role"
                                v-model="createForm.role"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                            </select>
                            <InputError class="mt-1" :message="createForm.errors.role" />
                        </div>
                    </div>

                    <div class="mt-5 flex items-center gap-3">
                        <PrimaryButton :disabled="createForm.processing || atLimit">Create user</PrimaryButton>
                        <ActionMessage :on="createForm.recentlySuccessful">Created.</ActionMessage>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
