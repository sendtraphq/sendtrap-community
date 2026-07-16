<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Privacy-policy stub (Plan 06 Phase 4b design §8 row 31, Adapted):
 * Community ships its OWN docs, not Cloud's marketing policy — real
 * content is Phase 6 publication work; the design sanctions a Phase 4b
 * stub route. Public (no auth), standalone layout.
 */
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-800">
        <Head title="Privacy policy" />

        <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Privacy policy</h1>
            <p class="mt-4 text-base leading-relaxed text-slate-600">
                This is a self-hosted Sendtrap Community instance. All data — users, inboxes, captured
                messages and attachments — is stored on this instance's own infrastructure and handled by
                its operator. The Sendtrap project receives nothing: the software phones home to no one,
                and every feature works offline.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-slate-500">
                Instance operators: replace this page with your own policy if you need one.
            </p>
            <p class="mt-8 text-sm">
                <Link href="/" class="font-semibold text-brand-600 hover:text-brand-700">&larr; Back</Link>
            </p>
        </div>
    </div>
</template>
