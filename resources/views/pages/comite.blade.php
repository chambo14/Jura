<?php

use App\Enums\FlashReportStatus;
use App\Enums\ProjectCategory;
use App\Models\FlashReport;
use App\Services\FlashReportBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts.presentation')]
#[Title('Comité Projets')]
class extends Component {
    #[Url(except: '')]
    public string $semaine = '';

    /**
     * 0 = diapositive de titre, puis intercalaires de rubrique et projets.
     */
    public int $index = 0;

    /**
     * Inclure les brouillons en plus des rapports publiés.
     */
    public bool $inclureBrouillons = true;

    public function mount(): void
    {
        if ($this->semaine === '') {
            $this->semaine = FlashReportBuilder::lundiDe()->format('Y-m-d');
        }
    }

    #[Computed]
    public function lundi(): CarbonInterface
    {
        return FlashReportBuilder::lundiDe(Date::parse($this->semaine));
    }

    /**
     * @return Collection<int, FlashReport>
     */
    #[Computed]
    public function rapports(): Collection
    {
        return FlashReport::query()
            ->whereHas('project', fn ($q) => $q->visibleTo(auth()->user())->actifs())
            ->pourSemaine($this->lundi())
            ->when(
                ! $this->inclureBrouillons,
                fn ($q) => $q->where('statut', FlashReportStatus::Publie->value),
            )
            ->with([
                'items',
                'auteur',
                'workflowStep',
                'project.client',
                'project.chefProjet',
                'project.backUp',
                'project.sponsor',
                'project.referentTechnique',
                'project.members.user',
                'project.steps.workflowStep',
            ])
            ->get()
            ->sortBy([
                fn (FlashReport $a, FlashReport $b) => $a->project->categorie->rang() <=> $b->project->categorie->rang(),
                fn (FlashReport $a, FlashReport $b) => $a->project->ordre_comite <=> $b->project->ordre_comite,
                fn (FlashReport $a, FlashReport $b) => $a->project->nom <=> $b->project->nom,
            ])
            ->values();
    }

    /**
     * Séquence des diapositives : titre, puis pour chaque rubrique du sommaire
     * un intercalaire suivi de ses projets.
     *
     * @return Collection<int, array{type: string, categorie?: ProjectCategory, rapport?: FlashReport, projets?: Collection<int, FlashReport>}>
     */
    #[Computed]
    public function diapositives(): Collection
    {
        $sequence = collect([['type' => 'titre']]);

        foreach (ProjectCategory::ordonnees() as $categorie) {
            $rapports = $this->rapports()->filter(
                fn (FlashReport $rapport) => $rapport->project->categorie === $categorie,
            )->values();

            if ($rapports->isEmpty()) {
                continue;
            }

            $sequence->push(['type' => 'rubrique', 'categorie' => $categorie, 'projets' => $rapports]);

            foreach ($rapports as $rapport) {
                $sequence->push(['type' => 'projet', 'categorie' => $categorie, 'rapport' => $rapport]);
            }
        }

        return $sequence->values();
    }

    #[Computed]
    public function total(): int
    {
        return $this->diapositives()->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function courant(): ?array
    {
        return $this->diapositives()->get($this->index);
    }

    public function precedent(): void
    {
        $this->index = max(0, $this->index - 1);
    }

    public function suivant(): void
    {
        $this->index = min($this->total() - 1, $this->index + 1);
    }

    public function allerA(int $index): void
    {
        $this->index = max(0, min($this->total() - 1, $index));
    }

    public function changerSemaine(int $decalage): void
    {
        $this->semaine = $this->lundi()->addWeeks($decalage)->format('Y-m-d');
        $this->index = 0;

        $this->viderCache();
    }

    public function updatedInclureBrouillons(): void
    {
        $this->index = 0;

        $this->viderCache();
    }

    private function viderCache(): void
    {
        unset($this->lundi, $this->rapports, $this->diapositives, $this->total, $this->courant);
    }

    /**
     * Compteurs affichés sur la diapositive de titre.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function indicateurs(): array
    {
        return [
            'projets' => $this->rapports()->count(),
            'alerte' => $this->rapports()->filter(fn (FlashReport $r) => $r->sante?->value === 'rouge')->count(),
            'publies' => $this->rapports()->where('statut', FlashReportStatus::Publie)->count(),
        ];
    }
}; ?>

<div
    class="flex min-h-screen flex-col"
    x-data
    x-on:keydown.window.arrow-right="$wire.suivant()"
    x-on:keydown.window.arrow-left="$wire.precedent()"
    x-on:keydown.window.space.prevent="$wire.suivant()"
>
    {{-- Barre de contrôle --}}
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center gap-3">
            <flux:button :href="route('dashboard')" icon="arrow-left" variant="ghost" size="sm" wire:navigate>
                Quitter
            </flux:button>

            <div class="hidden text-sm text-zinc-500 dark:text-zinc-400 sm:block">
                Comité Projets · semaine du {{ $this->lundi()->translatedFormat('j F Y') }}
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:switch wire:model.live="inclureBrouillons" label="Inclure les brouillons" />

            <flux:separator vertical class="mx-1 h-6" />

            <flux:button wire:click="changerSemaine(-1)" icon="chevron-double-left" variant="ghost" size="sm" title="Semaine précédente" />
            <flux:button wire:click="changerSemaine(1)" icon="chevron-double-right" variant="ghost" size="sm" title="Semaine suivante" />

            <flux:separator vertical class="mx-1 h-6" />

            <flux:button wire:click="precedent" icon="chevron-left" variant="filled" size="sm" :disabled="$index === 0" />
            <span class="min-w-16 text-center text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                {{ $index + 1 }} / {{ $this->total() }}
            </span>
            <flux:button wire:click="suivant" icon="chevron-right" variant="filled" size="sm" :disabled="$index >= $this->total() - 1" />
        </div>
    </header>

    {{-- Diapositive, posée sur une table lumineuse neutre comme une projection --}}
    <main class="flex flex-1 items-start justify-center overflow-auto bg-neutral-200 p-6 dark:bg-zinc-950">
        <div class="w-full max-w-[1280px]" wire:key="slide-{{ $index }}">
            @php $slide = $this->courant(); @endphp

            @if (! $slide || $slide['type'] === 'titre')
                {{-- Diapositive de titre : visuel et distinctions du dossier de comité,
                     sous un voile bleu nuit qui garantit la lisibilité en projection. --}}
                <div
                    class="relative flex min-h-[680px] flex-col items-center justify-center overflow-hidden bg-mpm-navy bg-cover bg-center p-12 text-center font-slide shadow-xl ring-1 ring-black/10"
                    style="background-image: url('{{ asset('images/comite-background.jpg') }}')"
                >
                    <div class="absolute inset-0 bg-mpm-navy/90"></div>

                    <div class="relative flex flex-col items-center">
                        <img src="{{ asset('images/mediasoft-lafayette.png') }}" alt="Mediasoft Lafayette" class="h-20 w-auto rounded bg-white p-2" />

                        <p class="mt-8 text-sm uppercase tracking-[0.3em] text-white/70">
                            Direction des Projets &amp; Organisation
                        </p>
                        <h1 class="mt-4 text-5xl font-bold uppercase tracking-tight text-white">Comité Projets</h1>
                        <p class="mt-3 text-xl text-white/90">
                            Du {{ $this->lundi()->format('d/m/Y') }} au {{ $this->lundi()->addDays(4)->format('d/m/Y') }}
                        </p>

                        <div class="mt-9 grid grid-cols-3 gap-10">
                            <div>
                                <div class="text-4xl font-bold text-white">{{ $this->indicateurs()['projets'] }}</div>
                                <div class="mt-1 text-xs uppercase tracking-wide text-white/60">Projets présentés</div>
                            </div>
                            <div>
                                <div class="text-4xl font-bold text-red-300">{{ $this->indicateurs()['alerte'] }}</div>
                                <div class="mt-1 text-xs uppercase tracking-wide text-white/60">En alerte</div>
                            </div>
                            <div>
                                <div class="text-4xl font-bold text-green-300">{{ $this->indicateurs()['publies'] }}</div>
                                <div class="mt-1 text-xs uppercase tracking-wide text-white/60">Rapports publiés</div>
                            </div>
                        </div>

                        @if ($this->rapports()->isEmpty())
                            <flux:callout icon="exclamation-triangle" color="amber" class="mt-9 max-w-lg">
                                Aucun flash report pour cette semaine. Préparez-les depuis l'écran « Flash reports ».
                            </flux:callout>
                        @else
                            {{-- Sommaire des rubriques --}}
                            <ol class="mt-9 grid w-full max-w-3xl gap-2 sm:grid-cols-2">
                                @foreach ($this->diapositives()->where('type', 'rubrique') as $rang => $rubrique)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="allerA({{ $rang }})"
                                            class="flex w-full items-center gap-3 border border-white/20 bg-white/10 px-4 py-2.5 text-start transition hover:bg-white/20"
                                        >
                                            <span class="text-lg font-bold tabular-nums text-white/50">
                                                {{ str_pad((string) $rubrique['categorie']->rang(), 2, '0', STR_PAD_LEFT) }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium uppercase text-white">
                                                    {{ $rubrique['categorie']->label() }}
                                                </span>
                                                <span class="block text-xs text-white/60">
                                                    {{ $rubrique['projets']->count() }} projet(s)
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                    {{-- Bandeau des distinctions, repris du dossier --}}
                    <div class="relative mt-10 flex flex-wrap items-end justify-center gap-x-8 gap-y-4 border-t border-white/15 pt-5">
                        @foreach ([
                            ['award-elite.png', 'World Business Leader Award', 'Mediasoft Lafayette, Elite Member'],
                            ['award-uemoa.png', 'World Business Award', '2008 · Meilleure entreprise d\'ingénierie UEMOA'],
                            ['award-excellence.jpg', 'Prix d\'Excellence 2023', 'Vulgarisation des usages du numérique en Côte d\'Ivoire'],
                        ] as [$fichier, $titre, $mention])
                            <div class="flex items-center gap-2.5 text-start">
                                <img src="{{ asset('images/'.$fichier) }}" alt="" class="h-10 w-auto" />
                                <div class="max-w-44">
                                    <div class="text-[11px] font-bold uppercase tracking-wide text-white/85">{{ $titre }}</div>
                                    <div class="text-[10px] leading-tight text-white/55">{{ $mention }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($slide['type'] === 'rubrique')
                {{-- Intercalaire de rubrique --}}
                <div class="flex min-h-[680px] flex-col justify-center bg-white p-12 font-slide shadow-xl ring-1 ring-black/10">
                    <div class="flex items-baseline gap-5 border-b-4 border-mpm-burgundy pb-4">
                        <span class="text-7xl font-bold tabular-nums text-mpm-navy/20">
                            {{ str_pad((string) $slide['categorie']->rang(), 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h1 class="text-4xl font-bold uppercase tracking-tight text-mpm-navy">
                            {{ $slide['categorie']->label() }}
                        </h1>
                    </div>

                    <ul class="mt-8 space-y-1.5">
                        @foreach ($slide['projets'] as $rapport)
                            <li class="flex flex-wrap items-center gap-3 border border-mpm-rule px-4 py-2.5">
                                @if ($rapport->sante)
                                    <span @class([
                                        'size-2.5 shrink-0 rounded-full',
                                        'bg-mpm-red' => $rapport->sante->color() === 'red',
                                        'bg-mpm-amber' => $rapport->sante->color() === 'amber',
                                        'bg-mpm-green' => $rapport->sante->color() === 'green',
                                    ])></span>
                                @endif

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-bold text-black">{{ $rapport->project->nom }}</span>
                                    <span class="block truncate text-xs text-neutral-500">
                                        {{ $rapport->project->client->sigle ?? $rapport->project->client->nom }} ·
                                        {{ $rapport->project->chefProjet?->name ?? 'Sans chef de projet' }}
                                    </span>
                                </span>

                                <span class="shrink-0 text-sm font-bold tabular-nums text-mpm-navy">
                                    {{ number_format($rapport->avancement_pct, 1, ',', ' ') }} %
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <img
                        src="{{ asset('images/mediasoft-lafayette.png') }}"
                        alt="Mediasoft Lafayette"
                        class="mt-auto h-12 w-auto self-end pt-8"
                    />
                </div>
            @else
                <x-flash-slide :report="$slide['rapport']" presentation />
            @endif
        </div>
    </main>

    {{-- Sommaire cliquable --}}
    @if ($this->rapports()->isNotEmpty())
        <footer class="flex flex-wrap items-center gap-1.5 border-t border-zinc-200 bg-zinc-50 px-5 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach ($this->diapositives() as $rang => $diapo)
                @if ($diapo['type'] === 'titre')
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        @class([
                            'rounded px-2 py-1 text-xs transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-500 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >Titre</button>
                @elseif ($diapo['type'] === 'rubrique')
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        wire:key="rubrique-{{ $diapo['categorie']->value }}"
                        @class([
                            'ms-2 rounded px-2 py-1 text-xs font-semibold uppercase tracking-wide transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >{{ str_pad((string) $diapo['categorie']->rang(), 2, '0', STR_PAD_LEFT) }}</button>
                @else
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        wire:key="diapo-{{ $diapo['rapport']->id }}"
                        @class([
                            'flex items-center gap-1.5 rounded px-2 py-1 text-xs transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-500 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >
                        @if ($diapo['rapport']->sante)
                            <span @class([
                                'size-1.5 rounded-full',
                                'bg-red-500' => $diapo['rapport']->sante->color() === 'red',
                                'bg-amber-500' => $diapo['rapport']->sante->color() === 'amber',
                                'bg-green-500' => $diapo['rapport']->sante->color() === 'green',
                            ])></span>
                        @endif
                        {{ $diapo['rapport']->project->code }}
                    </button>
                @endif
            @endforeach
        </footer>
    @endif
</div>
