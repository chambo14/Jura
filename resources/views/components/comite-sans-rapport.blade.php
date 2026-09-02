@props([
    'project',
    'semaine',
])

{{-- Fiche d'un projet actif dont le flash report de la semaine manque.

     Elle ne remplace pas le rapport : elle constate son absence. Ce qu'elle
     affiche vient exclusivement de la fiche projet — identité, pilotage,
     jalons datés — et jamais d'un calcul qui donnerait l'illusion d'un
     compte rendu. Un comité doit voir le vide, pas un vide comblé. --}}
<article class="flex min-h-[680px] flex-col bg-white font-slide shadow-xl ring-1 ring-black/10">
    <header class="flex items-start justify-between gap-6 bg-mpm-navy px-10 py-6">
        <div class="min-w-0">
            <h1 class="truncate text-3xl font-bold text-white">{{ $project->nom }}</h1>
            <p class="mt-1 truncate text-sm text-blue-100">
                {{ $project->code }} ·
                {{ $project->client->displayName() }} ·
                {{ $project->type_projet->label() }}
            </p>
        </div>

        <span class="shrink-0 border border-white/40 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-white">
            Rapport non rendu
        </span>
    </header>

    <div class="flex-1 px-10 py-8">
        <div class="border-l-4 border-mpm-burgundy bg-mpm-burgundy/5 px-5 py-4">
            <p class="text-lg font-bold text-mpm-burgundy">
                Aucun flash report pour la semaine du {{ $semaine->format('d/m/Y') }}
            </p>
            <p class="mt-1 text-sm text-neutral-600">
                Le projet est actif : son avancement, ses réalisations et ses points d'attention
                de la semaine ne sont pas connus. Les éléments ci-dessous viennent de la fiche
                projet, pas d'un compte rendu.
            </p>
        </div>

        <div class="mt-8 grid gap-8 sm:grid-cols-2">
            <section>
                <h2 class="border-b-2 border-mpm-navy pb-1.5 text-sm font-bold uppercase tracking-wide text-mpm-navy">
                    Pilotage
                </h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ([
                        'Chef de projet' => $project->chefProjet?->name,
                        'Back-up' => $project->backUp?->name,
                        'Sponsor' => $project->sponsor?->name,
                        'Référent technique' => $project->referentTechnique?->name,
                    ] as $role => $nom)
                        <div class="flex gap-2">
                            <dt class="shrink-0 text-neutral-500">{{ $role }} :</dt>
                            <dd class="min-w-0 truncate font-medium text-black">{{ $nom ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section>
                <h2 class="border-b-2 border-mpm-navy pb-1.5 text-sm font-bold uppercase tracking-wide text-mpm-navy">
                    Ce que dit la fiche projet
                </h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ([
                        'Date de début' => $project->date_debut?->format('d/m/Y'),
                        'Fin prévue' => $project->dateFinEffective()?->format('d/m/Y'),
                        'Phase MPM' => $project->phase?->nom,
                        'Étape courante' => $project->etapeCourante(),
                    ] as $intitule => $valeur)
                        <div class="flex gap-2">
                            <dt class="shrink-0 text-neutral-500">{{ $intitule }} :</dt>
                            <dd class="min-w-0 truncate font-medium text-black">{{ $valeur ?? '—' }}</dd>
                        </div>
                    @endforeach

                    <div class="flex gap-2">
                        <dt class="shrink-0 text-neutral-500">Avancement déclaré :</dt>
                        <dd class="font-medium text-black">
                            {{ number_format($project->avancement_pct, 1, ',', ' ') }} %
                            <span class="text-xs text-neutral-500">(saisi sur la fiche, non confirmé cette semaine)</span>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>

    <footer class="flex items-center justify-between gap-4 border-t border-mpm-rule px-10 py-4 text-xs text-neutral-500">
        <span class="flex items-center gap-2">
            <img src="{{ asset('images/mediasoft-lafayette.png') }}" alt="" class="h-6 w-auto" />
            <span>Direction des Projets &amp; Organisation · semaine du {{ $semaine->format('d/m/Y') }}</span>
        </span>
        <span>Flash report à établir depuis l'écran « Flash reports »</span>
    </footer>
</article>
