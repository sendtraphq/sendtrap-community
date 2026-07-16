<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionBorder from '@sendtrap/core/Components/SectionBorder.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';

/**
 * Community profile page (Plan 06 Phase 4b design §7.3): the Fortify-backed
 * self-service surface — profile information, password, 2FA. No Jetstream
 * feature flags, no profile photos, no browser-session/API-token panels
 * (Jetstream constructs — Cloud-only).
 */
defineProps({
    confirmsTwoFactorAuthentication: Boolean,
});
</script>

<template>
    <AppLayout title="Profile">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Profile
            </h2>
        </template>

        <div>
            <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
                <div>
                    <UpdateProfileInformationForm :user="$page.props.auth.user" />

                    <SectionBorder />
                </div>

                <div>
                    <UpdatePasswordForm class="mt-10 sm:mt-0" />

                    <SectionBorder />
                </div>

                <div>
                    <TwoFactorAuthenticationForm
                        :requires-confirmation="confirmsTwoFactorAuthentication"
                        class="mt-10 sm:mt-0"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
