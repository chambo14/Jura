<?php

use App\Enums\ProgressStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ChargeService;
use App\Support\Charge\ChargeCollaborateur;
use App\Support\Charge\Periode;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public User $collaborateur;

    public string $granularite = 'mois';

    /**
     * Nombre de périodes affichées dans l'histogramme.
     */
    private const HORIZON = 6;

    public function mount(User $user): void
    {
        $this->authorize('viewAny', Project::class);

        $this->collaborateur = $user;
    }

    /**
     * @return array<int, Periode>
     */
    #[Computed]
    public function periodes(): array
    {
        return Periode::suite($this->granularite, $this->granularite === 'semaine' ? 8 : self::HORIZON);
    }

    /**
     * @return Collection<int, array{periode: Periode, charge: ChargeCollaborateur}>
     */
    #[Computed]
    public function serie(): Collection
    {
        return app(ChargeService::class)->serie($this->collaborateur, $this->periodes());
    }

    #[Computed]
    public function courante(): ChargeCollaborateur
    {
        return $this->serie()->first()['charge'];
    }

    /**
     * Charge maximale de la série, pour mettre l'histogramme à l'échelle.
     */
    #[Computed]
    public function maximum(): float
    {
        return max(100.0, $this->serie()->max(fn (array $p) => $p['charge']->total()));
    }

    /**
     * Tâches ouvertes portées par le collaborateur, les plus urgentes d'abord.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function taches(): Collection
    {
        return Task::query()
            ->where('assignee_id', $this->collaborateur->id)
            ->whereNotIn('statut', [ProgressStatus::Termine->value, ProgressStatus::Annule->value])
            ->whereHas('project', fn ($q) => $q->actifs())
            ->with(['project', 'deleguePar'])
            ->orderByRaw('date_echeance IS NULL')
            ->orderBy('date_echeance')
            ->get();
    }

    public function changerGranularite(string $granularite): void
    {
        $this->granularite = $granularite === 'semaine' ? 'semaine' : 'mois';

        unset($this->periodes, $this->serie, $this->courante, $this->maximum);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title('Charge de '.$this->collaborateur->name);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    {{-- En-tête --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('plan-de-charge.index')" wire:navigate>Plan de charge</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $collaborateur->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="mt-2 flex items-center gap-3">
                <flux:avatar :name="$collaborateur->name" size="lg" />
                <div>
                    <flux:heading size="xl">{{ $collaborateur->name }}</flux:heading>
                    <flux:subheading>
                        {{ $collaborateur->poste ?? '—' }}
                        @if ($collaborateur->profile)
                            · {{ $collaborateur->profile->nom }}
                        @endif
                    </flux:subheading>
                </div>
            </div>
        </div>

        <flux:button.group>
            <flux:button
                wire:click="changerGranularite('mois')"
                size="sm"
                :variant="$granularite === 'mois' ? 'primary' : 'filled'"
            >Mois</flux:button>
            <flux:button
                wire:click="changerGranularite('semaine')"
                size="sm"
                :variant="$granularite === 'semaine' ? 'primary' : 'filled'"
            >Semaine</flux:button>
        </flux:button.group>
    </div>

    {{-- Histogramme de charge --}}
    <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Charge prévisionnelle</flux:heading>
        <flux:subheading>
            {{ $granularite === 'semaine' ? 'Huit prochaines semaines' : 'Six prochains mois' }}
        </flux:subheading>

        <div class="mt-5 flex items-end gap-2" style="height: 11rem">
            @foreach ($this->serie() as $point)
                @php
                    $total = $point['charge']->total();
                    $hauteur = max(2, $total / $this->maximum() * 100);
                @endphp

                <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                    <span @class([
                        'text-xs font-medium tabular-nums',
                        'text-red-600 dark:text-red-400' => $point['charge']->couleur() === 'red',
                        'text-amber-600 dark:text-amber-400' => $point['charge']->couleur() === 'amber',
                        'text-zinc-500 dark:text-zinc-400' => $point['charge']->couleur() === 'green',
                    ])>{{ number_format($total, 0) }}%</span>

                    <div class="relative w-full" style="height: {{ $hauteur }}%">
                        <div @class([
                            'absolute inset-0 rounded-t',
                            'bg-red-500' => $point['charge']->couleur() === 'red',
                            'bg-amber-500' => $point['charge']->couleur() === 'amber',
                            'bg-green-500' => $point['charge']->couleur() === 'green',
                        ])></div>
                    </div>

                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $point['periode']->libelleCourt }}</span>
                </div>
            @endforeach
        </div>

        {{-- Repère du temps plein --}}
        <div class="mt-3 flex items-center gap-2 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            <span class="h-2 w-4 rounded bg-green-500"></span> sous 85 %
            <span class="ms-3 h-2 w-4 rounded bg-amber-500"></span> sans marge
            <span class="ms-3 h-2 w-4 rounded bg-red-500"></span> surcharge au-delà de 100 %
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Détail par projet sur la période courante --}}
        <section>
            <flux:heading size="lg" class="mb-1">Répartition par projet</flux:heading>
            <flux:subheading class="mb-3">{{ $this->serie()->first()['periode']->libelle }}</flux:subheading>

            @if ($this->courante()->projets->isEmpty())
                <flux:callout icon="check-circle" color="green" heading="Aucune charge sur cette période" />
            @else
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-mpm-navy-tint text-xs uppercase tracking-wide text-mpm-navy-dark dark:bg-zinc-800/60 dark:text-zinc-300">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">Projet</th>
                                <th class="px-3 py-2 text-start font-medium">Rôle</th>
                                <th class="px-3 py-2 text-end font-medium">Affectation</th>
                                <th class="px-3 py-2 text-end font-medium">Tâches</th>
                                <th class="px-3 py-2 text-end font-medium">Retenu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach ($this->courante()->projets as $ligne)
                                <tr wire:key="ligne-{{ $ligne->projet->id }}">
                                    <td class="px-3 py-2.5">
                                        <a href="{{ route('projets.show', $ligne->projet) }}" wire:navigate class="hover:underline">
                                            {{ $ligne->projet->nom }}
                                        </a>
                                        @if ($ligne->parDelegation)
                                            <div class="text-xs text-violet-600 dark:text-violet-400">Par délégation</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $ligne->role?->label() ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-end tabular-nums text-zinc-500 dark:text-zinc-400">
                                        {{ number_format($ligne->planifiee, 1, ',', ' ') }} %
                                    </td>
                                    <td class="px-3 py-2.5 text-end tabular-nums text-zinc-500 dark:text-zinc-400">
                                        {{ number_format($ligne->assignee, 1, ',', ' ') }} %
                                        <span class="text-xs">({{ $ligne->tachesOuvertes }})</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-end font-medium tabular-nums">
                                        {{ number_format($ligne->retenue(), 1, ',', ' ') }} %
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-zinc-50 dark:bg-zinc-800/60">
                            <tr>
                                <td colspan="4" class="px-3 py-2 text-end text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Total</td>
                                <td class="px-3 py-2 text-end font-semibold tabular-nums">
                                    {{ number_format($this->courante()->total(), 1, ',', ' ') }} %
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        {{-- Tâches portées --}}
        <section>
            <flux:heading size="lg" class="mb-1">Tâches en cours</flux:heading>
            <flux:subheading class="mb-3">{{ $this->taches()->count() }} tâche(s) ouverte(s)</flux:subheading>

            @if ($this->taches()->isEmpty())
                <flux:callout icon="check-circle" color="green" heading="Aucune tâche ouverte" />
            @else
                <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach ($this->taches()->take(12) as $tache)
                        <li class="flex items-start gap-3 bg-white px-3 py-2.5 dark:bg-zinc-900" wire:key="tache-{{ $tache->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm leading-snug text-zinc-800 dark:text-zinc-100">{{ $tache->libelle }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $tache->project->nom }}
                                    @if ($tache->deleguePar)
                                        · confiée par {{ $tache->deleguePar->name }}
                                    @endif
                                </div>
                            </div>

                            <div class="shrink-0 text-end">
                                <div @class([
                                    'text-xs tabular-nums',
                                    'font-medium text-red-600 dark:text-red-400' => $tache->enRetard(),
                                    'text-zinc-600 dark:text-zinc-300' => ! $tache->enRetard(),
                                ])>{{ $tache->date_echeance?->format('d/m/Y') ?? '—' }}</div>
                                <flux:badge :color="$tache->statut->color()" size="sm">{{ $tache->statut->label() }}</flux:badge>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($this->taches()->count() > 12)
                    <flux:text size="sm" class="mt-2">+ {{ $this->taches()->count() - 12 }} autre(s) tâche(s)</flux:text>
                @endif
            @endif
        </section>
    </div>
</div>
