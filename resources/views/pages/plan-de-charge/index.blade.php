<?php

use App\Models\Project;
use App\Services\ChargeService;
use App\Support\Charge\ChargeCollaborateur;
use App\Support\Charge\Periode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Plan de charge')] class extends Component {
    /**
     * Granularité d'analyse : le mois pour le COPIL, la semaine pour le COPRO.
     */
    #[Url(except: 'mois')]
    public string $granularite = 'mois';

    /**
     * Décalage en périodes par rapport à aujourd'hui.
     */
    public int $decalage = 0;

    public bool $seulementSurcharges = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    #[Computed]
    public function periode(): Periode
    {
        $reference = $this->granularite === 'semaine'
            ? Date::now()->addWeeks($this->decalage)
            : Date::now()->addMonths($this->decalage);

        return $this->granularite === 'semaine'
            ? Periode::semaine($reference)
            : Periode::mois($reference);
    }

    /**
     * @return Collection<int, ChargeCollaborateur>
     */
    #[Computed]
    public function charges(): Collection
    {
        return app(ChargeService::class)
            ->pourPeriode($this->periode())
            ->when(
                $this->seulementSurcharges,
                fn (Collection $c) => $c->filter(fn (ChargeCollaborateur $x) => $x->enSurcharge())->values(),
            );
    }

    /**
     * @return array<string, int|float>
     */
    #[Computed]
    public function synthese(): array
    {
        $toutes = app(ChargeService::class)->pourPeriode($this->periode());

        return [
            'collaborateurs' => $toutes->count(),
            'surcharges' => $toutes->filter(fn (ChargeCollaborateur $c) => $c->enSurcharge())->count(),
            'sansMarge' => $toutes->filter(fn (ChargeCollaborateur $c) => $c->couleur() === 'amber')->count(),
            'moyenne' => round($toutes->avg(fn (ChargeCollaborateur $c) => $c->total()) ?? 0, 1),
        ];
    }

    public function changerGranularite(string $granularite): void
    {
        $this->granularite = $granularite === 'semaine' ? 'semaine' : 'mois';
        $this->decalage = 0;

        $this->viderCache();
    }

    public function decaler(int $pas): void
    {
        $this->decalage += $pas;

        $this->viderCache();
    }

    public function revenirAujourdhui(): void
    {
        $this->decalage = 0;

        $this->viderCache();
    }

    private function viderCache(): void
    {
        unset($this->periode, $this->charges, $this->synthese);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Plan de charge</flux:heading>
            <flux:subheading>{{ $this->periode()->libelle }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
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

            <flux:separator vertical class="mx-1 h-6" />

            <flux:button wire:click="decaler(-1)" icon="chevron-left" variant="ghost" size="sm" />
            <flux:button wire:click="revenirAujourdhui" variant="ghost" size="sm" :disabled="$decalage === 0">
                Aujourd'hui
            </flux:button>
            <flux:button wire:click="decaler(1)" icon="chevron-right" variant="ghost" size="sm" />
        </div>
    </div>

    {{-- Comment la charge est calculée : la règle ne doit pas rester implicite --}}
    <flux:callout icon="information-circle" color="blue">
        La charge d'un collaborateur combine deux lectures : son <strong>affectation nominale</strong>,
        pondérée par le poids du rôle — un sponsor ne consomme pas un temps plein —, et les
        <strong>tâches qui lui sont confiées</strong>. Sur un projet donné, la plus élevée des deux est
        retenue, afin de ne pas compter deux fois le même travail tout en faisant apparaître
        les tâches déléguées à quelqu'un sans affectation.
    </flux:callout>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Collaborateurs mobilisés" :value="$this->synthese()['collaborateurs']" icon="users" color="blue" />
        <x-stat label="En surcharge" :value="$this->synthese()['surcharges']" sub="au-delà de 100 %" icon="exclamation-triangle" color="red" />
        <x-stat label="Sans marge" :value="$this->synthese()['sansMarge']" sub="entre 85 et 100 %" icon="clock" color="amber" />
        <x-stat label="Charge moyenne" :value="number_format($this->synthese()['moyenne'], 1, ',', ' ').' %'" icon="chart-bar" color="green" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:checkbox wire:model.live="seulementSurcharges" label="Afficher seulement les surcharges" />
        <flux:text size="sm">{{ $this->charges()->count() }} collaborateur(s)</flux:text>
    </div>

    {{-- Charge par collaborateur --}}
    <div class="grid gap-3">
        @forelse ($this->charges() as $charge)
            <section
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                wire:key="charge-{{ $charge->collaborateur->id }}"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <flux:avatar :name="$charge->collaborateur->name" size="sm" />
                        <div>
                            <a
                                href="{{ route('plan-de-charge.show', $charge->collaborateur) }}"
                                wire:navigate
                                class="font-medium text-zinc-900 hover:underline dark:text-white"
                            >{{ $charge->collaborateur->name }}</a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $charge->collaborateur->poste ?? '—' }} ·
                                {{ $charge->nombreProjets() }} projet(s) ·
                                {{ $charge->tachesOuvertes() }} tâche(s) ouverte(s)
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <div @class([
                            'text-2xl font-semibold tabular-nums',
                            'text-red-600 dark:text-red-400' => $charge->couleur() === 'red',
                            'text-amber-600 dark:text-amber-400' => $charge->couleur() === 'amber',
                            'text-green-600 dark:text-green-400' => $charge->couleur() === 'green',
                        ])>{{ number_format($charge->total(), 1, ',', ' ') }} %</div>
                        @if ($charge->deleguee() > 0)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                dont {{ number_format($charge->deleguee(), 1, ',', ' ') }} % délégué
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Répartition par projet --}}
                <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    @foreach ($charge->projets as $ligne)
                        <div
                            class="h-full border-e border-white dark:border-zinc-900 {{ $ligne->porteeParLesTaches() ? 'bg-violet-500' : 'bg-blue-500' }}"
                            style="width: {{ min(100, $ligne->retenue()) }}%"
                            title="{{ $ligne->projet->nom }} — {{ number_format($ligne->retenue(), 1, ',', ' ') }} %"
                        ></div>
                    @endforeach
                </div>

                <ul class="mt-3 grid gap-1.5 sm:grid-cols-2">
                    @foreach ($charge->projets as $ligne)
                        <li class="flex items-center gap-2 text-sm">
                            <span @class([
                                'size-2 shrink-0 rounded-full',
                                'bg-violet-500' => $ligne->porteeParLesTaches(),
                                'bg-blue-500' => ! $ligne->porteeParLesTaches(),
                            ])></span>
                            <a href="{{ route('projets.show', $ligne->projet) }}" wire:navigate class="min-w-0 flex-1 truncate text-zinc-700 hover:underline dark:text-zinc-200">
                                {{ $ligne->projet->nom }}
                            </a>
                            @if ($ligne->role)
                                <span class="shrink-0 text-xs text-zinc-400">{{ $ligne->role->label() }}</span>
                            @endif
                            <span class="shrink-0 text-sm font-medium tabular-nums text-zinc-600 dark:text-zinc-300">
                                {{ number_format($ligne->retenue(), 1, ',', ' ') }} %
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:text>Aucune charge sur cette période.</flux:text>
            </div>
        @endforelse
    </div>

    <div class="flex flex-wrap items-center gap-5 text-xs text-zinc-500 dark:text-zinc-400">
        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-blue-500"></span> Charge d'affectation</span>
        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-violet-500"></span> Charge portée par les tâches confiées</span>
    </div>
</div>
