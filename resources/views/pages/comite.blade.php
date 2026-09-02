<?php

use App\Enums\FlashReportStatus;
use App\Models\FlashReport;
use App\Services\ComiteBuilder;
use App\Services\FlashReportBuilder;
use App\Support\Comite\Diapositive;
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
        return app(ComiteBuilder::class)->rapports(
            auth()->user(),
            $this->lundi(),
            $this->inclureBrouillons,
        );
    }

    /**
     * Séquence des diapositives : titre, puis pour chaque rubrique du sommaire
     * un intercalaire suivi de ses projets.
     *
     * @return Collection<int, Diapositive>
     */
    #[Computed]
    public function diapositives(): Collection
    {
        return app(ComiteBuilder::class)->sequence(
            auth()->user(),
            $this->lundi(),
            $this->inclureBrouillons,
        );
    }

    /**
     * Projets actifs dont le flash report de la semaine manque.
     *
     * @return Collection<int, Diapositive>
     */
    #[Computed]
    public function manquants(): Collection
    {
        return $this->diapositives()->where('type', 'sans_rapport')->values();
    }

    #[Computed]
    public function total(): int
    {
        return $this->diapositives()->count();
    }

    #[Computed]
    public function courant(): ?Diapositive
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

            {{-- Le fichier reprend exactement la séquence projetée à l'écran. --}}
            <flux:button
                :href="route('comite.export', ['semaine' => $semaine, 'brouillons' => $inclureBrouillons ? 1 : 0])"
                icon="arrow-down-tray"
                variant="ghost"
                size="sm"
                :disabled="$this->diapositives()->count() <= 1"
                title="Télécharger la présentation au format PowerPoint"
            >
                PowerPoint
            </flux:button>

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

            @if (! $slide || $slide->type === 'titre')
                {{-- Diapositive de titre : le visuel de la maison à sa clarté d'origine.
                     Le voile bleu nuit qui le recouvrait garantissait la lisibilité mais
                     éteignait l'image ; un voile blanc dégradé la laisse vivre et porte
                     le texte en bleu nuit, comme sur les dossiers de comité. --}}
                <div
                    class="relative flex min-h-[680px] flex-col overflow-hidden bg-white bg-cover bg-center p-10 font-slide shadow-xl ring-1 ring-black/10 sm:p-12"
                    style="background-image: url('{{ asset('images/comite-background.jpg') }}')"
                >
                    <div class="absolute inset-0 bg-gradient-to-b from-white/75 via-white/65 to-white/85"></div>

                    {{-- Filet bleu nuit à gauche : la signature des bandeaux de section. --}}
                    <div class="absolute inset-y-0 start-0 w-1.5 bg-mpm-navy"></div>

                    <div class="relative flex flex-1 flex-col items-center justify-center text-center">
                        <img
                            src="{{ asset('images/mediasoft-lafayette.png') }}"
                            alt="Mediasoft Lafayette"
                            class="h-16 w-auto"
                        />

                        <p class="mt-7 text-xs uppercase tracking-[0.35em] text-mpm-navy/70">
                            Direction des Projets &amp; Organisation
                        </p>
                        <h1 class="mt-3 text-5xl font-bold uppercase tracking-tight text-mpm-navy">Comité Projets</h1>

                        <p class="mt-3 inline-flex items-center gap-2 bg-mpm-navy px-4 py-1.5 text-sm font-medium tracking-wide text-white">
                            Du {{ $this->lundi()->format('d/m/Y') }} au {{ $this->lundi()->addDays(4)->format('d/m/Y') }}
                        </p>

                        {{-- Les trois chiffres du dossier, posés sur du blanc plein pour
                             rester lisibles quelle que soit la zone de l'image. --}}
                        <div class="mt-8 grid w-full max-w-2xl grid-cols-3 divide-x divide-mpm-rule border border-mpm-rule bg-white/85 shadow-sm">
                            @foreach ([
                                ['valeur' => $this->indicateurs()['projets'], 'libelle' => 'Projets présentés', 'ton' => 'text-mpm-navy'],
                                ['valeur' => $this->indicateurs()['alerte'], 'libelle' => 'En alerte', 'ton' => 'text-mpm-red'],
                                ['valeur' => $this->indicateurs()['publies'], 'libelle' => 'Rapports publiés', 'ton' => 'text-mpm-green'],
                            ] as $chiffre)
                                <div class="px-4 py-4">
                                    <div class="text-4xl font-bold tabular-nums {{ $chiffre['ton'] }}">{{ $chiffre['valeur'] }}</div>
                                    <div class="mt-1 text-[11px] uppercase tracking-wide text-zinc-500">{{ $chiffre['libelle'] }}</div>
                                </div>
                            @endforeach
                        </div>

                        @if ($this->manquants()->isNotEmpty())
                            {{-- w-full : sans lui, l'encart se réduit à la largeur d'un mot
                                 dans une colonne centrée. --}}
                            <div class="mt-6 flex w-full max-w-2xl items-start gap-3 border border-amber-300 bg-amber-50/95 px-4 py-3 text-start">
                                <flux:icon name="exclamation-triangle" variant="mini" class="mt-0.5 shrink-0 text-amber-600" />
                                <p class="text-sm leading-snug text-amber-900">
                                    {{ $this->manquants()->count() }}
                                    {{ Str::plural('projet actif', $this->manquants()->count()) }}
                                    {{ $this->manquants()->count() > 1 ? "n'ont" : "n'a" }} pas de flash report cette semaine.
                                    {{ $this->manquants()->count() > 1 ? 'Ils passent' : 'Il passe' }} quand même à l'ordre du jour,
                                    {{ $this->manquants()->count() > 1 ? 'signalés' : 'signalé' }} comme donnée manquante.
                                </p>
                            </div>
                        @endif

                        @if ($this->diapositives()->where('type', 'rubrique')->isEmpty())
                            <div class="mt-6 w-full max-w-lg border border-amber-300 bg-amber-50/95 px-4 py-3 text-sm text-amber-900">
                                Aucun projet actif ni flash report pour cette semaine.
                            </div>
                        @else
                            {{-- Sommaire des rubriques --}}
                            <ol class="mt-8 grid w-full max-w-3xl gap-2 sm:grid-cols-2">
                                @foreach ($this->diapositives()->where('type', 'rubrique') as $rang => $rubrique)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="allerA({{ $rang }})"
                                            class="flex w-full items-center gap-3 border border-mpm-rule bg-white/90 px-4 py-2.5 text-start shadow-sm transition hover:border-mpm-navy hover:bg-white"
                                        >
                                            <span class="text-lg font-bold tabular-nums text-mpm-navy/40">
                                                {{ str_pad((string) $rubrique->categorie->rang(), 2, '0', STR_PAD_LEFT) }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-semibold uppercase text-mpm-navy">
                                                    {{ $rubrique->categorie->label() }}
                                                </span>
                                                <span class="block text-xs text-zinc-500">
                                                    {{ $rubrique->projets->count() }} {{ Str::plural('projet', $rubrique->projets->count()) }}
                                                    @if ($rubrique->rapportsManquants->isNotEmpty())
                                                        · <span class="font-medium text-amber-700">{{ $rubrique->rapportsManquants->count() }} sans rapport</span>
                                                    @endif
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                    {{-- Bandeau des distinctions, repris du dossier --}}
                    {{-- Le bandeau des distinctions ferme la diapositive, bord à bord : il
                         est le pied de page du dossier, pas un encart posé dessus. --}}
                    <div class="relative -mx-10 -mb-10 mt-8 flex flex-wrap items-end justify-center gap-x-8 gap-y-4 border-t border-mpm-rule bg-white/85 px-10 pb-6 pt-5 sm:-mx-12 sm:-mb-12 sm:px-12">
                        @foreach ([
                            ['award-elite.png', 'World Business Leader Award', 'Mediasoft Lafayette, Elite Member'],
                            ['award-uemoa.png', 'World Business Award', '2008 · Meilleure entreprise d\'ingénierie UEMOA'],
                            ['award-excellence.jpg', 'Prix d\'Excellence 2023', 'Vulgarisation des usages du numérique en Côte d\'Ivoire'],
                        ] as [$fichier, $titre, $mention])
                            <div class="flex items-center gap-2.5 text-start">
                                <img src="{{ asset('images/'.$fichier) }}" alt="" class="h-10 w-auto" />
                                <div class="max-w-44">
                                    <div class="text-[11px] font-bold uppercase tracking-wide text-mpm-navy">{{ $titre }}</div>
                                    <div class="text-[10px] leading-tight text-zinc-500">{{ $mention }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($slide->type === 'rubrique')
                {{-- Intercalaire de rubrique --}}
                <div class="flex min-h-[680px] flex-col justify-center bg-white p-12 font-slide shadow-xl ring-1 ring-black/10">
                    <div class="flex items-baseline gap-5 border-b-4 border-mpm-burgundy pb-4">
                        <span class="text-7xl font-bold tabular-nums text-mpm-navy/20">
                            {{ str_pad((string) $slide->categorie->rang(), 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h1 class="text-4xl font-bold uppercase tracking-tight text-mpm-navy">
                            {{ $slide->categorie->label() }}
                        </h1>
                    </div>

                    <ul class="mt-8 space-y-1.5">
                        @foreach ($slide->projets as $rapport)
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

                    @if ($slide->rapportsManquants->isNotEmpty())
                        {{-- Ces projets n'ont rien rendu : ils figurent au sommaire de
                             la rubrique, sans chiffre inventé pour combler le vide. --}}
                        <div class="mt-6">
                            <div class="text-xs font-bold uppercase tracking-wide text-mpm-burgundy">
                                Sans flash report cette semaine
                            </div>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($slide->rapportsManquants as $projet)
                                    <li class="flex flex-wrap items-center gap-3 border border-dashed border-mpm-burgundy/40 bg-mpm-burgundy/5 px-4 py-2.5">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-bold text-black">{{ $projet->nom }}</span>
                                            <span class="block truncate text-xs text-neutral-500">
                                                {{ $projet->client->sigle ?? $projet->client->nom }} ·
                                                {{ $projet->chefProjet?->name ?? 'Sans chef de projet' }}
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-xs font-bold uppercase tracking-wide text-mpm-burgundy">
                                            Non rendu
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <img
                        src="{{ asset('images/mediasoft-lafayette.png') }}"
                        alt="Mediasoft Lafayette"
                        class="mt-auto h-12 w-auto self-end pt-8"
                    />
                </div>
            @elseif ($slide->type === 'sans_rapport')
                <x-comite-sans-rapport :project="$slide->projet" :semaine="$this->lundi()" />
            @else
                <x-flash-slide :report="$slide->rapport" presentation />
            @endif
        </div>
    </main>

    {{-- Sommaire cliquable --}}
    @if ($this->diapositives()->count() > 1)
        <footer class="flex flex-wrap items-center gap-1.5 border-t border-zinc-200 bg-zinc-50 px-5 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach ($this->diapositives() as $rang => $diapo)
                @if ($diapo->type === 'titre')
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        @class([
                            'rounded px-2 py-1 text-xs transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-500 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >Titre</button>
                @elseif ($diapo->type === 'rubrique')
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        wire:key="rubrique-{{ $diapo->categorie->value }}"
                        @class([
                            'ms-2 rounded px-2 py-1 text-xs font-semibold uppercase tracking-wide transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >{{ str_pad((string) $diapo->categorie->rang(), 2, '0', STR_PAD_LEFT) }}</button>
                @elseif ($diapo->type === 'projet')
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        wire:key="diapo-{{ $diapo->rapport->id }}"
                        @class([
                            'flex items-center gap-1.5 rounded px-2 py-1 text-xs transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-500 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >
                        @if ($diapo->rapport->sante)
                            <span @class([
                                'size-1.5 rounded-full',
                                'bg-red-500' => $diapo->rapport->sante->color() === 'red',
                                'bg-amber-500' => $diapo->rapport->sante->color() === 'amber',
                                'bg-green-500' => $diapo->rapport->sante->color() === 'green',
                            ])></span>
                        @endif
                        {{ $diapo->rapport->project->code }}
                    </button>
                @else
                    {{-- Un projet sans flash report : il figure au sommaire comme les
                         autres, avec une pastille creuse qui dit qu'aucune santé n'a
                         été déclarée cette semaine. --}}
                    <button
                        type="button"
                        wire:click="allerA({{ $rang }})"
                        wire:key="sans-rapport-{{ $diapo->projet->id }}"
                        title="Pas de flash report cette semaine"
                        @class([
                            'flex items-center gap-1.5 rounded px-2 py-1 text-xs italic transition',
                            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $index === $rang,
                            'text-zinc-400 hover:bg-zinc-200 dark:text-zinc-500 dark:hover:bg-zinc-800' => $index !== $rang,
                        ])
                    >
                        <span class="size-1.5 rounded-full border border-zinc-300 dark:border-zinc-600"></span>
                        {{ $diapo->projet->code }}
                    </button>
                @endif
            @endforeach
        </footer>
    @endif
</div>
