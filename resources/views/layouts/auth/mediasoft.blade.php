{{-- Écrans d'authentification : carte flottante centrée aux couleurs
     de Mediasoft Lafayette, logo en visuel principal. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="relative flex min-h-dvh items-center justify-center p-4 sm:p-8">
            {{-- L'accent Flux passe au bleu nuit Mediasoft : liens et anneaux de focus
                 restent ainsi neutres, le bordeaux étant réservé au bouton d'action. --}}
            <div
                class="w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-zinc-900 dark:ring-white/10"
                style="--color-accent: var(--color-mpm-navy); --color-accent-content: var(--color-mpm-navy); --color-accent-foreground: #ffffff;"
            >
                <div class="grid md:grid-cols-2">
                    {{-- Visuel de marque --}}
                    <aside class="relative flex flex-col items-center justify-center gap-6 bg-linear-to-br from-[#eef2f9] to-[#dde5f2] p-10 dark:from-zinc-800 dark:to-zinc-900">
                        <img
                            src="{{ asset('images/mediasoft-lafayette.png') }}"
                            alt="Mediasoft Lafayette"
                            class="w-52 max-w-full"
                        />

                        <div class="text-center">
                            <p class="text-[11px] uppercase tracking-[0.25em] text-mpm-navy/60 dark:text-zinc-400">
                                Direction des Projets &amp; Organisation
                            </p>
                            <p class="mt-3 text-lg font-semibold leading-snug text-mpm-navy dark:text-white">
                                Suivi des projets
                            </p>
                            <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Délais, ressources, livrables et flash report hebdomadaire,
                                selon la Méthodologie Projet MEDIASOFT.
                            </p>
                        </div>
                    </aside>

                    {{-- Formulaire --}}
                    <main class="flex items-center justify-center px-7 py-12 sm:px-10">
                        <div class="w-full max-w-sm">
                            {{ $slot }}
                        </div>
                    </main>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
