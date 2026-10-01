<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Facebook, Instagram, Menu, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { home, about, rates, contact, dashboard, login, register } from '@/routes';
import { terms, privacy, shipping, refund, restricted } from '@/routes/legal';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const navLinks = [
    { name: 'About', href: about() },
    { name: 'Rates', href: rates() },
    { name: 'Contact', href: contact() },
];

const legalLinks = [
    { name: 'Terms & Conditions', href: terms() },
    { name: 'Privacy Policy', href: privacy() },
    { name: 'Shipping Policy', href: shipping() },
    { name: 'Refund & Claims', href: refund() },
    { name: 'Restricted Items', href: restricted() },
];

const socialLinks = [
    { name: 'Instagram', href: '#' },
    { name: 'TikTok', href: '#' },
    { name: 'Facebook', href: '#' },
];

const mobileOpen = ref(false);
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <Toaster richColors position="top-right" />

        <header
            class="sticky top-0 z-40 border-b border-brand-green/20 bg-background/85 backdrop-blur"
        >
            <div
                class="h-0.5 w-full bg-gradient-to-r from-brand-green via-brand-green-soft to-brand-gold/60"
                aria-hidden="true"
            />
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8"
            >
                <Link
                    :href="home()"
                    class="group flex items-center gap-3"
                    aria-label="Ship'd JM home"
                >
                    <img
                        src="/branding/shipdjm-logo.png"
                        alt="Ship'd JM"
                        class="h-10 w-auto object-contain"
                    />
                </Link>

                <nav class="hidden items-center gap-1 md:flex">
                    <Link
                        v-for="link in navLinks"
                        :key="link.name"
                        :href="link.href"
                        class="rounded-md px-3 py-2 text-sm font-medium text-foreground/80 transition hover:bg-brand-green-muted hover:text-brand-green"
                    >
                        {{ link.name }}
                    </Link>
                </nav>

                <div class="hidden items-center gap-2 md:flex">
                    <template v-if="user">
                        <Button as-child variant="ghost">
                            <Link :href="dashboard()">Dashboard</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button as-child variant="ghost">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button
                            as-child
                            class="public-cta"
                        >
                            <Link :href="register()">Create account</Link>
                        </Button>
                    </template>
                </div>

                <button
                    type="button"
                    class="inline-flex size-9 items-center justify-center rounded-md border border-border md:hidden"
                    aria-label="Toggle menu"
                    @click="mobileOpen = !mobileOpen"
                >
                    <Menu v-if="!mobileOpen" class="size-5" />
                    <X v-else class="size-5" />
                </button>
            </div>

            <div v-if="mobileOpen" class="border-t border-border md:hidden">
                <div class="mx-auto max-w-7xl space-y-1 px-4 py-3">
                    <Link
                        v-for="link in navLinks"
                        :key="link.name"
                        :href="link.href"
                        class="block rounded-md px-3 py-2 text-sm font-medium text-foreground/80 hover:bg-secondary"
                        @click="mobileOpen = false"
                    >
                        {{ link.name }}
                    </Link>
                    <div class="flex gap-2 pt-2">
                        <template v-if="user">
                            <Button as-child class="flex-1">
                                <Link :href="dashboard()">Dashboard</Link>
                            </Button>
                        </template>
                        <template v-else>
                            <Button as-child variant="outline" class="flex-1">
                                <Link :href="login()">Log in</Link>
                            </Button>
                            <Button
                                as-child
                                class="flex-1 public-cta"
                            >
                                <Link :href="register()">Create account</Link>
                            </Button>
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-brand-green/30 bg-brand-ink text-brand-cream">
            <div
                class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-4 lg:px-8"
            >
                <div class="space-y-3">
                    <p
                        class="text-lg font-semibold tracking-tight"
                    >
                        Ship'd <span class="text-brand-green-soft">JM</span>
                    </p>
                    <p class="text-sm text-brand-cream/70">
                        Jamaica-based package forwarding. Shop the world, ship
                        to our Florida warehouse, collect in Jamaica.
                    </p>
                </div>
                <div>
                    <p
                        class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-brand-green-soft"
                    >
                        Explore
                    </p>
                    <ul class="space-y-2 text-sm">
                        <li v-for="link in navLinks" :key="link.name">
                            <Link
                                :href="link.href"
                                class="text-brand-cream/70 transition hover:text-brand-cream"
                            >
                                {{ link.name }}
                            </Link>
                        </li>
                    </ul>
                </div>
                <div>
                    <p
                        class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-brand-green-soft"
                    >
                        Legal
                    </p>
                    <ul class="space-y-2 text-sm">
                        <li v-for="link in legalLinks" :key="link.name">
                            <Link
                                :href="link.href"
                                class="text-brand-cream/70 transition hover:text-brand-cream"
                            >
                                {{ link.name }}
                            </Link>
                        </li>
                    </ul>
                </div>
                <div>
                    <p
                        class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-brand-green-soft"
                    >
                        Get started
                    </p>
                    <p class="mb-3 text-sm text-brand-cream/70">
                        Create your free account to receive a customer
                        reference and Florida shipping address.
                    </p>
                    <Button
                        as-child
                        class="public-cta"
                    >
                        <Link :href="register()">Create account</Link>
                    </Button>
                    <div class="mt-4 flex items-center gap-2">
                        <template v-for="social in socialLinks" :key="social.name">
                            <a
                                :href="social.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="social.name"
                                class="inline-flex size-9 items-center justify-center rounded-md border border-white/15 text-brand-cream/70 transition hover:border-brand-green/60 hover:text-brand-green-soft"
                            >
                                <Instagram v-if="social.name === 'Instagram'" class="size-4" />
                                <Facebook v-else-if="social.name === 'Facebook'" class="size-4" />
                                <svg
                                    v-else
                                    class="size-4"
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.68h-3.45v13.84a2.9 2.9 0 1 1-2-2.77V9.57a6.33 6.33 0 1 0 6.35 6.32V9.12a8.22 8.22 0 0 0 4.87 1.61V7.28a4.87 4.87 0 0 1-2-.59z"
                                    />
                                </svg>
                            </a>
                        </template>
                    </div>
                </div>
            </div>
            <div
                class="border-t border-white/10"
            >
                <div
                    class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 px-4 py-4 text-xs text-brand-cream/60 sm:flex-row sm:px-6 lg:px-8"
                >
                    <p>© {{ new Date().getFullYear() }} Ship'd JM. All rights reserved.</p>
                    <p>Built for Jamaica · Pickup only · JMD shipping rates</p>
                </div>
            </div>
        </footer>
    </div>
</template>
