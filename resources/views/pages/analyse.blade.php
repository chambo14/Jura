<?php

use App\Models\Project;
use App\Services\PortfolioAnalyticsService;
use App\Support\Analyse\LigneAxe;
use App\Support\Analyse\SyntheseAnalyse;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Analyse du portefeuille')] class extends Component {
    /**
     * Axe de regroupement : le client pour l'angle commercial, le chef de
     * projet pour l'angle pilotage.
     */
    #[Url(except: PortfolioAnalyticsService::AXE_CLIENT)]
    public string $axe = PortfolioAnalyticsService::AXE_CLIENT;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
    }

    public function changerAxe(string $axe): void
    {
        $this->axe = $axe === PortfolioAnalyticsService::AXE_CHEF
            ? PortfolioAnalyticsService::AXE_CHEF
            : PortfolioAnalyticsService::AXE_CLIENT;

        unset($this->lignes);
    }

    /**
     * @return Collection<int, LigneAxe>
     */
    #[Computed]
    public function lignes(): Collection
    {
        return app(PortfolioAnalyticsService::class)->parAxe($this->axe, auth()->user());
    }

    #[Computed]
    public function synthese(): SyntheseAnalyse
    {
        return app(PortfolioAnalyticsService::class)->synthese(auth()->user());
    }

    /**
     * Glissement le plus élevé de l'axe, qui donne l'échelle des barres.
     */
    #[Computed]
    public function echelle(): float
    {
        return max(1.0, (float) $this->lignes()->max(fn (LigneAxe $l) => $l->glissementMoyen));
    }

    /**
     * Y a-t-il au moins un regroupement dont la moyenne repose sur trop peu de projets ?
     */
    #[Computed]
    public function effectifsFaibles(): bool
    {
        return $this->lignes()->contains(fn (LigneAxe $l) => $l->effectifFaible());
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Analyse du portefeuille</flux:heading>
            <flux:subheading>Où le temps se perd, et sur quels regroupements la dérive se concentre</flux:subheading>
        </div>

        <flux:button.group>
            <flux:button
                wire:click="changerAxe('{{ App\Services\PortfolioAnalyticsService::AXE_CLIENT }}')"
                size="sm"
                :variant="$axe === App\Services\PortfolioAnalyticsService::AXE_CLIENT ? 'primary' : 'filled'"
            >Par client</flux:button>
            <flux:button
                wire:click="changerAxe('{{ App\Services\PortfolioAnalyticsService::AXE_CHEF }}')"
                size="sm"
                :variant="$axe === App\Services\PortfolioAnalyticsService::AXE_CHEF ? 'primary' : 'filled'"
            >Par chef de projet</flux:button>
        </flux:button.group>
    </div>

    {{-- La règle de lecture ne doit pas rester implicite : ces chiffres décrivent des projets. --}}
    <flux:callout icon="information-circle" color="blue">
        <flux:callout.text>
        Le <strong>glissement</strong> mesure l'écart en jours entre la date de fin annoncée au départ et
        la date révisée : c'est l'engagement pris qui a bougé. L'<strong>écart d'avancement</strong> compare
        l'avancement déclaré à celui attendu au prorata du calendrier — un écart négatif signale un projet
        en retard sur son propre planning. Ces indicateurs décrivent des <strong>projets</strong>, pas des
        personnes : un glissement se construit à plusieurs, entre décision client, périmètre revu et
        dépendance externe. L'axe « chef de projet » sert à repérer où regarder.
        </flux:callout.text>
    </flux:callout>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat
            label="Glissement moyen"
            :value="number_format($this->synthese()->glissementMoyen, 0, ',', ' ').' j'"
            :sub="'pire cas : '.number_format($this->synthese()->glissementMax, 0, ',', ' ').' j'"
            icon="arrow-trending-up"
            color="red"
        />
        <x-stat
            label="Projets ayant glissé"
            :value="$this->synthese()->projetsGlisses.' / '.$this->synthese()->projets"
            :sub="number_format($this->synthese()->partProjetsGlisses(), 0, ',', ' ').' % du portefeuille'"
            icon="calendar-days"
            color="amber"
        />
        <x-stat
            label="Écart d'avancement moyen"
            :value="$this->synthese()->ecartAvancementMoyen === null
                ? '—'
                : sprintf('%+.1f pts', $this->synthese()->ecartAvancementMoyen)"
            sub="déclaré vs attendu à date"
            icon="chart-bar"
            :color="($this->synthese()->ecartAvancementMoyen ?? 0) <= -App\Services\ProjectHealthService::SEUIL_ECART_ROUGE ? 'red' : 'amber'"
        />
        <x-stat
            label="Jalons en retard"
            :value="$this->synthese()->jalonsEnRetard"
            :sub="'dont '.$this->synthese()->jalonsCritiquesEnRetard.' critique(s)'"
            icon="flag"
            color="red"
        />
    </div>

    @if ($this->lignes()->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:text>Aucun projet à analyser dans votre périmètre.</flux:text>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full min-w-[52rem] text-sm">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                    <tr>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ $axe === App\Services\PortfolioAnalyticsService::AXE_CHEF ? 'Chef de projet' : 'Client' }}
                        </th>
                        <th class="px-3 py-2 text-end font-medium">Projets</th>
                        <th class="px-3 py-2 text-start font-medium">Glissement moyen</th>
                        <th class="px-3 py-2 text-end font-medium">Pire cas</th>
                        <th class="px-3 py-2 text-end font-medium">Écart avancement</th>
                        <th class="px-3 py-2 text-end font-medium">Jalons en retard</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->lignes() as $ligne)
                        <tr class="bg-white dark:bg-zinc-900" wire:key="axe-{{ $axe }}-{{ $loop->index }}">
                            <td class="px-3 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $ligne->libelle }}</div>
                                @if ($ligne->sousTitre)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $ligne->sousTitre }}</div>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-end tabular-nums text-zinc-600 dark:text-zinc-300">
                                {{ $ligne->nombreProjets }}@if ($ligne->effectifFaible())<span class="text-zinc-400" title="Moyenne calculée sur moins de 3 projets">&nbsp;*</span>@endif
                            </td>

                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="relative h-2 w-32 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div
                                            @class([
                                                'h-full rounded-full',
                                                'bg-red-500' => $ligne->couleurGlissement() === 'red',
                                                'bg-amber-500' => $ligne->couleurGlissement() === 'amber',
                                                'bg-green-500' => $ligne->couleurGlissement() === 'green',
                                            ])
                                            style="width: {{ round($ligne->glissementMoyen / $this->echelle() * 100, 1) }}%"
                                        ></div>
                                    </div>
                                    <span @class([
                                        'w-20 shrink-0 text-end text-sm font-medium tabular-nums',
                                        'text-red-600 dark:text-red-400' => $ligne->couleurGlissement() === 'red',
                                        'text-amber-600 dark:text-amber-400' => $ligne->couleurGlissement() === 'amber',
                                        'text-green-600 dark:text-green-400' => $ligne->couleurGlissement() === 'green',
                                    ])>{{ number_format($ligne->glissementMoyen, 0, ',', ' ') }} j</span>
                                </div>
                            </td>

                            <td class="px-3 py-3 text-end tabular-nums text-zinc-600 dark:text-zinc-300">
                                {{ number_format($ligne->glissementMax, 0, ',', ' ') }} j
                            </td>

                            <td class="px-3 py-3 text-end">
                                @if ($ligne->ecartAvancementMoyen === null)
                                    <span class="text-zinc-400">—</span>
                                @else
                                    <span @class([
                                        'text-sm font-medium tabular-nums',
                                        'text-red-600 dark:text-red-400' => $ligne->couleurEcart() === 'red',
                                        'text-amber-600 dark:text-amber-400' => $ligne->couleurEcart() === 'amber',
                                        'text-green-600 dark:text-green-400' => $ligne->couleurEcart() === 'green',
                                    ])>{{ sprintf('%+.1f', $ligne->ecartAvancementMoyen) }} pts</span>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-end">
                                @if ($ligne->jalonsEnRetard === 0)
                                    <span class="text-zinc-400">—</span>
                                @else
                                    <span class="text-sm font-medium tabular-nums text-red-600 dark:text-red-400">
                                        {{ $ligne->jalonsEnRetard }}
                                    </span>
                                    @if ($ligne->jalonsCritiquesEnRetard > 0)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            dont {{ $ligne->jalonsCritiquesEnRetard }} critique(s)
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-5 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-500"></span> Moins de {{ App\Services\ProjectHealthService::SEUIL_GLISSEMENT_ORANGE }} j de glissement</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-500"></span> De {{ App\Services\ProjectHealthService::SEUIL_GLISSEMENT_ORANGE }} à {{ App\Services\ProjectHealthService::SEUIL_GLISSEMENT_ROUGE }} j</span>
            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-500"></span> Au-delà de {{ App\Services\ProjectHealthService::SEUIL_GLISSEMENT_ROUGE }} j</span>
            @if ($this->effectifsFaibles())
                <span>* Moyenne calculée sur moins de 3 projets : à lire avec prudence.</span>
            @endif
        </div>
    @endif
</div>
