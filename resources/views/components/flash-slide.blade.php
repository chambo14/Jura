@props([
    'report',
    'presentation' => false,
])

@php
    use App\Enums\FlashItemType;
    use App\Enums\MemberRole;
    use App\Enums\ProgressStatus;

    $project = $report->project;
    $steps = $project->steps;

    // Bloc PILOTAGE : deux colonnes, comme sur les slides du comité.
    $pilotageGauche = [
        'Client' => $project->client?->displayName(),
        'Type projet' => $project->type_projet->label(),
        'Pays de domiciliation' => $project->pays_domiciliation,
    ];

    $pilotageDroite = [
        MemberRole::ChefProjet->label() => $project->chefProjet?->name,
        MemberRole::BackUp->label() => $project->backUp?->name,
        MemberRole::Sponsor->label() => $project->sponsor?->name,
        MemberRole::ReferentTechnique->label() => $project->referentTechnique?->name,
        'Testeur(s)' => $project->members
            ->where('role', MemberRole::Testeur)
            ->map(fn ($m) => $m->user?->name)
            ->filter()
            ->implode(', ') ?: null,
    ];

    // Intervenants hors rôles de pilotage : développeurs, intégrateurs, formateurs…
    $rolesPilotage = [
        MemberRole::ChefProjet, MemberRole::BackUp, MemberRole::Sponsor,
        MemberRole::ReferentTechnique, MemberRole::Testeur,
    ];

    $autresMembres = $project->members
        ->reject(fn ($m) => in_array($m->role, $rolesPilotage, true))
        ->filter(fn ($m) => $m->user !== null)
        ->map(fn ($m) => $m->user->name.' ('.$m->role->label().')')
        ->implode(', ');

    $planning = [
        'Date début' => $report->date_debut?->format('d/m/Y'),
        'Date fin initiale' => $report->date_fin_initiale?->format('d/m/Y'),
        'Date fin révisée' => $report->date_fin_revisee?->format('d/m/Y'),
        '% Avancement' => number_format($report->avancement_pct, 2, ',', ' ').' %',
        'Taux de réalisation' => number_format($report->taux_realisation_pct, 2, ',', ' ').' %',
    ];

    // Échelle typographique : projection en salle, ou aperçu dans l'application.
    $t = $presentation
        ? ['titre' => 'text-3xl', 'desc' => 'text-sm', 'bande' => 'text-[13px]', 'corps' => 'text-[13px]', 'menu' => 'text-[11px]', 'etape' => 'text-[11px]']
        : ['titre' => 'text-xl', 'desc' => 'text-xs', 'bande' => 'text-[11px]', 'corps' => 'text-xs', 'menu' => 'text-[10px]', 'etape' => 'text-[10px]'];

    $bande = "flex items-center gap-2 bg-mpm-navy px-2.5 py-1 font-bold uppercase tracking-wide text-white {$t['bande']}";
    $puce = 'relative ps-3.5 before:absolute before:start-0 before:top-[0.5em] before:size-1.5 before:bg-mpm-burgundy';
@endphp

<article
    {{ $attributes->class([
        'mx-auto w-full font-slide text-black',
        'max-w-[1280px] min-h-[680px] bg-white p-6 shadow-xl ring-1 ring-black/10' => $presentation,
        'rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700' => ! $presentation,
    ]) }}
>
    {{-- ─────────────── Haut de slide : identité à gauche, planning à droite ─────────────── --}}
    <div class="grid gap-5 md:grid-cols-2">
        <section class="flex flex-col">
            <h2 class="{{ $t['titre'] }} font-bold uppercase leading-tight text-mpm-navy">
                Projet : {{ $project->nom }}
            </h2>

            @if ($project->description)
                <p class="mt-1.5 {{ $t['desc'] }} leading-snug text-neutral-700">{{ $project->description }}</p>
            @endif

            <h3 class="{{ $bande }} mt-4">Pilotage</h3>

            <div class="mt-2 grid gap-x-5 gap-y-1 sm:grid-cols-2">
                @foreach ([$pilotageGauche, $pilotageDroite] as $colonne)
                    <dl class="space-y-1">
                        @foreach ($colonne as $label => $valeur)
                            <div class="flex gap-1.5 {{ $t['corps'] }} leading-snug">
                                <dt class="shrink-0 text-neutral-600">{{ $label }} :</dt>
                                <dd class="min-w-0 flex-1 font-bold">{{ $valeur ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endforeach
            </div>

            @if ($autresMembres !== '')
                <div class="mt-2 flex gap-1.5 {{ $t['corps'] }} leading-snug">
                    <span class="shrink-0 text-neutral-600">Autres membres :</span>
                    <span class="min-w-0 flex-1 font-bold">{{ $autresMembres }}</span>
                </div>
            @endif
        </section>

        <section class="flex flex-col">
            <h3 class="{{ $bande }}">Planning &amp; avancement du projet</h3>

            <table class="mt-2 w-full border-collapse {{ $t['corps'] }}">
                <thead>
                    <tr>
                        @foreach (array_keys($planning) as $entete)
                            <th class="border border-mpm-rule bg-neutral-100 px-1.5 py-1 text-center font-normal leading-tight text-neutral-700">
                                {{ $entete }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach ($planning as $valeur)
                            <td class="border border-mpm-rule px-1.5 py-1.5 text-center font-bold tabular-nums">
                                {{ $valeur ?? '—' }}
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>

            <x-progress-bar
                class="mt-2"
                :value="$report->avancement_pct"
                :target="$project->avancementTheoriquePct()"
                color="green"
            />

            <h3 class="{{ $bande }} mt-4">Phase actuelle du projet</h3>

            <ol class="mt-2 grid grid-cols-2 gap-1 lg:grid-cols-3">
                @foreach ($steps as $etape)
                    @php
                        $classes = match ($etape->statut) {
                            ProgressStatus::Termine => 'border-mpm-green/50 bg-mpm-green/10 text-mpm-green',
                            ProgressStatus::EnCours => 'border-mpm-navy bg-mpm-navy text-white',
                            ProgressStatus::Bloque => 'border-mpm-red/50 bg-mpm-red/10 text-mpm-red',
                            default => 'border-mpm-rule bg-white text-neutral-500',
                        };
                    @endphp

                    <li
                        class="flex items-start gap-1 border px-1.5 py-1 leading-tight {{ $classes }} {{ $t['etape'] }}"
                        title="{{ $etape->workflowStep?->libelle }} — {{ $etape->statut->label() }}"
                    >
                        @if ($etape->statut === ProgressStatus::Termine)
                            <span aria-hidden="true" class="shrink-0 font-bold">&check;</span>
                        @endif

                        <span class="min-w-0">
                            {{ $etape->workflowStep?->libelle }}
                            @if ($etape->annotation)
                                <span class="opacity-80">— {{ $etape->annotation }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    {{-- ─────────── Bas de slide : réalisées | points d'attention | à réaliser ─────────── --}}
    <div class="mt-5 grid gap-4 md:grid-cols-3">
        @foreach ([FlashItemType::Realisee, FlashItemType::Attention, FlashItemType::ARealiser] as $type)
            @php $lignes = $report->itemsOfType($type); @endphp

            <section class="flex flex-col">
                <h3 class="{{ $bande }}">{{ $type->label() }}</h3>

                @if ($lignes->isEmpty())
                    <p class="mt-2 {{ $t['corps'] }} italic text-neutral-400">Aucun élément.</p>
                @else
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($lignes as $ligne)
                            <li class="{{ $puce }} {{ $t['corps'] }} leading-snug">
                                @if ($ligne->date_ref)
                                    <span class="font-bold">{{ $ligne->datePrefix() }} :</span>
                                @endif
                                <span class="text-neutral-800">{{ $ligne->libelle }}</span>
                                @if ($ligne->statut_libelle)
                                    <span @class([
                                        'font-bold',
                                        'text-mpm-green' => $ligne->statut_libelle === 'Terminé',
                                        'text-mpm-red' => $ligne->statut_libelle === 'En retard',
                                        'text-mpm-blue' => ! in_array($ligne->statut_libelle, ['Terminé', 'En retard'], true),
                                    ])>{{ $ligne->statut_libelle }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    @if ($report->synthese)
        <section class="mt-4">
            <h3 class="{{ $bande }}">Synthèse</h3>
            <p class="mt-2 {{ $t['corps'] }} whitespace-pre-line leading-snug text-neutral-800">{{ $report->synthese }}</p>
        </section>
    @endif

    {{-- ─────────────────────────────── Pied de slide ─────────────────────────────── --}}
    <footer class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-mpm-rule pt-2 {{ $t['menu'] }} text-neutral-500">
        <span class="flex items-center gap-2">
            <img src="{{ asset('images/mediasoft-lafayette.png') }}" alt="" class="h-6 w-auto" />
            <span>Direction des Projets &amp; Organisation · {{ $report->periodeLabel() }}</span>
        </span>

        <span class="flex items-center gap-3">
            @if ($report->lien_planning)
                <a href="{{ $report->lien_planning }}" target="_blank" rel="noopener" class="truncate text-mpm-blue underline">
                    Lien planning
                </a>
            @endif
            {{-- Le chef de projet est celui de la fiche projet. L'auteur du rapport
                 est souvent une autre personne : l'afficher sous ce libellé
                 attribuait le projet à qui l'avait saisi. --}}
            <span>Chef de projet : {{ $project->chefProjet?->name ?? '—' }}</span>
            @if ($report->auteur && $report->auteur->isNot($project->chefProjet))
                <span class="text-zinc-500">Rapport rédigé par {{ $report->auteur->name }}</span>
            @endif
        </span>
    </footer>
</article>
