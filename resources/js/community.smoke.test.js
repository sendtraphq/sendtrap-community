import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve as resolvePath } from 'node:path';
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ZiggyVue, route as ziggyRoute } from '../../vendor/tightenco/ziggy/dist/index.esm.js';
import { Ziggy } from './ziggy.js';
import ProjectDashboard from '@sendtrap/core/Components/ProjectDashboard/ProjectDashboard.vue';
import MessageReader from '@sendtrap/core/Components/MessageReader/MessageReader.vue';
import InboxSettings from '@sendtrap/core/Components/InboxSettings.vue';
import SendLimitBanner from '@sendtrap/core/Components/SendLimitBanner.vue';

/**
 * Plan 06 Phase 4b design §10.8: the frontend smoke — mounts the package's
 * two page-level components against COMMUNITY's real generated Ziggy route
 * list (`php artisan ziggy:generate`, run by the npm `test` script), so a
 * route-name drift between the package components' `route(...)` calls and
 * Community's actual registered routes (§4.8) fails a fast offline test
 * instead of a Ziggy exception in production. Plus §10.12: the
 * no-Cloud-vocabulary render assertions (Community's own workspace-neutral
 * `accessTitle`/`accessDescription` with `accessManageUrl`/
 * `accessManageLabel`/`upgradeUrl: null` are exactly the props Community's
 * controllers pass — §7.2.1, Plan 06 Phase 3 gate finding #1).
 */
const globalMountOptions = { global: { plugins: [[ZiggyVue, Ziggy]] } };

const originalRoute = globalThis.route;

beforeAll(() => {
    globalThis.route = (name, params, absolute) => ziggyRoute(name, params, absolute, Ziggy);
});

afterAll(() => {
    globalThis.route = originalRoute;
});

beforeEach(() => {
    // useEcho's ensureEcho() is a no-op with no key configured — the §6.6
    // "no Reverb configured" degrade path, which is Community's offline
    // default. jsdom has no Pusher-compatible transport anyway.
    vi.stubEnv('VITE_REVERB_APP_KEY', '');
    delete window.Echo;
});

const inboxProps = {
    id: 1,
    name: 'Test Inbox',
    effective_allowed_ips: [],
    allowed_ips: [],
    smtp_host: 'smtp.example.test',
    smtp_ports: [2525],
    smtp_username: 'user@example.org',
    smtp_password: 'secret-pass',
    api_token: 'token-123',
    max_messages: 1000,
    auto_forward_to: null,
    webhook_url: null,
    share: null,
};

describe('ProjectDashboard (Community-shaped props)', () => {
    it('mounts with upgradeUrl null and renders the project list without throwing', () => {
        const wrapper = mount(ProjectDashboard, {
            props: {
                projects: [
                    {
                        id: 1,
                        name: 'Project One',
                        slug: 'project-one',
                        allowed_ips: [],
                        inboxes: [{ ...inboxProps, messages_count: 3, unread_count: 1 }],
                    },
                ],
                usage: null,
                upgradeUrl: null,
            },
            ...globalMountOptions,
        });

        expect(wrapper.text()).toContain('Project One');
        expect(wrapper.text()).toContain('Test Inbox');
        // §7.5/§10.12: with upgradeUrl null no billing vocabulary renders.
        expect(wrapper.text()).not.toMatch(/upgrade/i);
        expect(wrapper.text()).not.toContain('View plans');
    });
});

describe('MessageReader (Community-shaped props)', () => {
    const baseProps = {
        inbox: inboxProps,
        messages: { data: [], links: [], meta: {} },
        accessTitle: 'Workspace access',
        accessDescription: 'Everyone in this workspace can access this inbox. Manage members, roles and the instance IP allowlist from Settings.',
        accessManageUrl: null,
        accessManageLabel: null,
        usage: null,
        upgradeUrl: null,
    };

    it('mounts and renders the inbox name without throwing', () => {
        const wrapper = mount(MessageReader, { props: baseProps, ...globalMountOptions });

        expect(wrapper.text()).toContain('Test Inbox');
        expect(wrapper.text()).toContain('0 messages');
    });
});

describe('route-name drift canary (§10.8)', () => {
    // Strip // line comments, /* */ block comments and <!-- --> HTML
    // comments before scanning: the package components legitimately
    // MENTION the removed Cloud route names in prose comments (they
    // document the injected-prop pattern that removed those calls), and
    // Community's AppLayout documents its own guards. Only executable
    // route() literals must resolve.
    const stripComments = (source) => source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');

    const collectVueFiles = (dir) => {
        const files = [];
        for (const entry of readdirSync(dir)) {
            const full = join(dir, entry);
            if (statSync(full).isDirectory()) {
                files.push(...collectVueFiles(full));
            } else if (entry.endsWith('.vue')) {
                files.push(full);
            }
        }
        return files;
    };

    const routeNamesIn = (files) => {
        const names = new Set();
        const guarded = new Set();
        for (const file of files) {
            const source = stripComments(readFileSync(file, 'utf8'));
            for (const match of source.matchAll(/route\(\s*['"]([a-zA-Z0-9_.-]+)['"]/g)) {
                names.add(match[1]);
            }
            // Names only reachable behind an explicit existence guard
            // (AppLayout's hasRoute()/route().has() — §7.1: the Docs/Users/
            // Settings routes land in slices 6-7) are exempt until then.
            for (const match of source.matchAll(/hasRoute\(\s*['"]([a-zA-Z0-9_.-]+)['"]/g)) {
                guarded.add(match[1]);
            }
            for (const match of source.matchAll(/route\(\)\.has\(\s*['"]([a-zA-Z0-9_.-]+)['"]/g)) {
                guarded.add(match[1]);
            }
        }
        return { names, guarded };
    };

    it('resolves every executable route() literal in the mounted package components against Community routes', () => {
        const pkg = resolvePath(process.cwd(), 'vendor/sendtrap/core/resources/js/Components');
        const files = [
            ...collectVueFiles(join(pkg, 'MessageReader')),
            ...collectVueFiles(join(pkg, 'ProjectDashboard')),
            join(pkg, 'InboxSettings.vue'),
            join(pkg, 'SendLimitBanner.vue'),
            join(pkg, 'UsagePill.vue'),
        ];

        const { names } = routeNamesIn(files);

        expect(names.size).toBeGreaterThan(5); // sanity: the scan found calls
        for (const name of names) {
            // `1` satisfies every route in this set — each takes at most
            // one required path parameter (message/inbox/project id).
            expect(() => globalThis.route(name, 1), `package route('${name}') should resolve`).not.toThrow();
        }
    });

    it('resolves every executable, unguarded route() literal in Community-authored resources/js', () => {
        const files = collectVueFiles(resolvePath(process.cwd(), 'resources/js'));

        const { names, guarded } = routeNamesIn(files);

        expect(names.size).toBeGreaterThan(5);
        for (const name of names) {
            if (guarded.has(name)) continue;
            expect(() => globalThis.route(name, 1), `Community route('${name}') should resolve`).not.toThrow();
        }
    });

    it('never references a Cloud route name anywhere in Community-authored resources/js (§10.7 arch line)', () => {
        const files = collectVueFiles(resolvePath(process.cwd(), 'resources/js'));

        for (const file of files) {
            const source = stripComments(readFileSync(file, 'utf8'));
            expect(source, `${file} must not reference Cloud routes`).not.toMatch(/route\(['"](teams\.|billing\.)/);
            // Token split so this scanner file cannot itself trip the §10.7
            // raw resources/js scan for the same literal.
            expect(source, `${file} must not reference the Cloud team-switch endpoint`).not.toContain('current-' + 'team');
        }
    });
});

describe('no Cloud vocabulary renders with Community props (§10.12)', () => {
    it('InboxSettings with Community-shaped access props shows "Workspace access" and no team wording', async () => {
        const wrapper = mount(InboxSettings, {
            props: {
                inbox: inboxProps,
                accessTitle: 'Workspace access',
                accessDescription: 'Everyone in this workspace can access this inbox. Manage members, roles and the instance IP allowlist from Settings.',
                accessManageUrl: null,
                accessManageLabel: null,
            },
            ...globalMountOptions,
        });

        const accessTab = wrapper.findAll('button').find((b) => b.text() === 'Access Rights');
        await accessTab.trigger('click');

        expect(wrapper.text()).toContain('Workspace access');
        expect(wrapper.text()).not.toMatch(/team/i);
        expect(wrapper.text()).not.toContain('Manage team access');
    });

    it('SendLimitBanner with upgradeUrl null drops all upgrade copy when quota-blocked', () => {
        const wrapper = mount(SendLimitBanner, {
            props: {
                usage: { per_minute: null, per_month: 1000, month_usage: 1000, pct: 100, recent_block: 'quota' },
                upgradeUrl: null,
            },
        });

        expect(wrapper.text()).not.toMatch(/upgrade/i);
        expect(wrapper.text()).not.toContain('View plans');
        expect(wrapper.text()).toContain('New mail is being rejected until next month.');
    });

    it('SendLimitBanner with upgradeUrl null drops upgrade copy when rate-limited', () => {
        const wrapper = mount(SendLimitBanner, {
            props: {
                usage: { per_minute: 60, per_month: null, month_usage: 0, pct: 0, recent_block: 'rate' },
                upgradeUrl: null,
            },
        });

        expect(wrapper.text()).not.toMatch(/upgrade/i);
        expect(wrapper.text()).toContain('slow down');
    });
});
