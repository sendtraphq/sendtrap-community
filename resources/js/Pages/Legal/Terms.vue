<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Terms stub (Plan 06 Phase 4b design §8 row 31, Adapted): Community ships
 * its OWN docs, not Cloud's marketing terms — the real content is Phase 6
 * publication work (D-04/D-10/D-11, owner-gated); the design explicitly
 * sanctions a Phase 4b stub route. Public (no auth), standalone layout —
 * guests may need to read this before they can log in.
 */
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-800">
        <Head title="Terms of use" />

        <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Terms of use</h1>
            <p class="mt-4 text-base leading-relaxed text-slate-600">
                This is a self-hosted Sendtrap Community instance. Terms of use are set by the operator of
                this instance, not by the Sendtrap project. The Sendtrap Community software itself is
                distributed under its open-source license — see the LICENSE file shipped with the source.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-slate-500">
                Instance operators: replace this page with your own terms if you need them.
            </p>
            <p class="mt-8 text-sm">
                <Link href="/" class="font-semibold text-brand-600 hover:text-brand-700">&larr; Back</Link>
            </p>
        </div>
    </div>
</template>
