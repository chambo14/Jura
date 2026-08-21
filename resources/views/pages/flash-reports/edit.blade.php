<?php

use App\Enums\FlashItemType;
use App\Enums\FlashReportStatus;
use App\Models\FlashReport;
use App\Models\FlashReportItem;
use App\Services\FlashReportBuilder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public FlashReport $flashReport;

    public string $synthese = '';

    public string $lienPlanning = '';

    /** @var array<int, array{id: int, libelle: string, date: string, statut: string}> */
    public array $lignes = [];

    public string $nouvelleRubrique = FlashItemType::Realisee->value;

    public string $nouveauLibelle = '';

    public string $nouvelleDate = '';

    public bool $apercu = false;

    public function mount(FlashReport $flashReport): void
    {
        $this->authorize('view', $flashReport);

        $this->flashReport = $flashReport->load(['items', 'project.steps.workflowStep', 'project.members.user', 'auteur']);
        $this->synthese = $flashReport->synthese ?? '';
        $this->lienPlanning = $flashReport->lien_planning ?? '';
        $this->chargerLignes();
    }

    private function chargerLignes(): void
    {
        $this->lignes = $this->flashReport->items()->orderBy('type')->orderBy('ordre')->get()
            ->map(fn (FlashReportItem $item) => [
                'id' => $item->id,
                'type' => $item->type->value,
                'libelle' => $item->libelle,
                'date' => $item->date_ref?->format('Y-m-d') ?? '',
                'statut' => $item->statut_libelle ?? '',
            ])
            ->all();
    }

    #[Computed]
    public function modifiable(): bool
    {
        return auth()->user()->can('update', $this->flashReport);
    }

    /**
     * Index des lignes du formulaire, groupés par rubrique pour l'affichage.
     *
     * @return Collection<string, Collection<int, int>>
     */
    #[Computed]
    public function indexParRubrique(): Collection
    {
        return collect($this->lignes)
            ->keys()
            ->groupBy(fn (int $index) => $this->lignes[$index]['type']);
    }

    public function enregistrer(): void
    {
        $this->authorize('update', $this->flashReport);

        $this->validate([
            'synthese' => ['nullable', 'string', 'max:4000'],
            'lienPlanning' => ['nullable', 'url', 'max:2048'],
            'lignes.*.libelle' => ['required', 'string', 'max:1000'],
            'lignes.*.date' => ['nullable', 'date'],
            'lignes.*.statut' => ['nullable', 'string', 'max:50'],
        ], attributes: [
            'lienPlanning' => 'lien planning',
            'lignes.*.libelle' => 'libellé',
        ]);

        $this->flashReport->update([
            'synthese' => $this->synthese ?: null,
            'lien_planning' => $this->lienPlanning ?: null,
        ]);

        foreach ($this->lignes as $ordre => $ligne) {
            $this->flashReport->items()->whereKey($ligne['id'])->update([
                'libelle' => $ligne['libelle'],
                'date_ref' => $ligne['date'] ?: null,
                'statut_libelle' => $ligne['statut'] ?: null,
                'ordre' => $ordre + 1,
            ]);
        }

        $this->flashReport->load('items');

        Flux::toast(variant: 'success', text: 'Flash report enregistré.');
    }

    public function ajouterLigne(): void
    {
        $this->authorize('update', $this->flashReport);

        $this->validate([
            'nouveauLibelle' => ['required', 'string', 'max:1000'],
            'nouvelleDate' => ['nullable', 'date'],
        ], attributes: ['nouveauLibelle' => 'libellé']);

        app(FlashReportBuilder::class)->ajouterLigne(
            $this->flashReport,
            FlashItemType::from($this->nouvelleRubrique),
            $this->nouveauLibelle,
            $this->nouvelleDate ? \Illuminate\Support\Facades\Date::parse($this->nouvelleDate) : null,
        );

        $this->reset('nouveauLibelle', 'nouvelleDate');
        $this->flashReport->load('items');
        $this->chargerLignes();
        unset($this->indexParRubrique);
    }

    public function supprimerLigne(int $id): void
    {
        $this->authorize('update', $this->flashReport);

        $this->flashReport->items()->whereKey($id)->delete();
        $this->flashReport->load('items');
        $this->chargerLignes();
        unset($this->indexParRubrique);
    }

    /**
     * Recharge les chiffres du projet dans le rapport sans toucher aux rubriques.
     */
    public function rafraichir(): void
    {
        $this->authorize('update', $this->flashReport);

        app(FlashReportBuilder::class)->rafraichir($this->flashReport);

        $this->flashReport->refresh()->load(['items', 'project.steps.workflowStep']);

        Flux::toast(variant: 'success', text: 'Chiffres réalignés sur le projet.');
    }

    public function publier(): void
    {
        $this->authorize('publish', $this->flashReport);

        $this->enregistrer();

        app(FlashReportBuilder::class)->publier($this->flashReport);

        $this->flashReport->refresh()->load(['items', 'project.steps.workflowStep']);

        Flux::toast(variant: 'success', text: 'Flash report publié : il apparaît désormais en mode comité.');
    }

    public function depublier(): void
    {
        $this->authorize('unpublish', $this->flashReport);

        $this->flashReport->update(['statut' => FlashReportStatus::Brouillon, 'publie_at' => null]);
        $this->flashReport->refresh();

        Flux::toast(variant: 'success', text: 'Flash report repassé en brouillon.');
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title('Flash report — '.$this->flashReport->project->nom);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    {{-- En-tête --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('projets.index')" wire:navigate>Projets</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('projets.show', $flashReport->project)" wire:navigate>
                    {{ $flashReport->project->code }}
                </flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Flash report</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <flux:heading size="xl" class="mt-2">{{ $flashReport->project->nom }}</flux:heading>
            <flux:subheading>Rapport {{ $flashReport->periodeLabel() }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :color="$flashReport->statut->color()">{{ $flashReport->statut->label() }}</flux:badge>

            <flux:button wire:click="$toggle('apercu')" :icon="$apercu ? 'pencil-square' : 'eye'" variant="filled" size="sm">
                {{ $apercu ? 'Rédiger' : 'Aperçu' }}
            </flux:button>

            @if ($this->modifiable())
                <flux:button wire:click="rafraichir" icon="arrow-path" variant="ghost" size="sm">Réaligner</flux:button>
                <flux:button wire:click="enregistrer" icon="check" variant="filled" size="sm">Enregistrer</flux:button>
                <flux:button wire:click="publier" wire:confirm="Publier ce flash report ? Les chiffres seront figés." icon="paper-airplane" variant="primary" size="sm">
                    Publier
                </flux:button>
            @endif

            @can('unpublish', $flashReport)
                @if ($flashReport->statut === App\Enums\FlashReportStatus::Publie)
                    <flux:button wire:click="depublier" icon="arrow-uturn-left" variant="ghost" size="sm">Dépublier</flux:button>
                @endif
            @endcan
        </div>
    </div>

    @if ($apercu || ! $this->modifiable())
        {{-- Rendu tel qu'il sera projeté en comité --}}
        <x-flash-slide :report="$flashReport" />
    @else
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="flex flex-col gap-6 xl:col-span-2">
                @foreach (FlashItemType::cases() as $rubrique)
                    @php $indexes = $this->indexParRubrique()->get($rubrique->value, collect()); @endphp

                    <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-2.5 dark:border-zinc-700">
                            <flux:icon :name="$rubrique->icon()" variant="mini" class="text-zinc-400" />
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-700 dark:text-zinc-200">
                                {{ $rubrique->label() }}
                            </h3>
                            <flux:badge color="zinc" size="sm" class="ms-auto">{{ $indexes->count() }}</flux:badge>
                        </header>

                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($indexes as $index)
                                <div class="flex flex-wrap items-start gap-2 p-3" wire:key="ligne-{{ $lignes[$index]['id'] }}">
                                    <flux:input
                                        wire:model="lignes.{{ $index }}.date"
                                        type="date"
                                        size="sm"
                                        class="w-40"
                                    />
                                    <flux:input
                                        wire:model="lignes.{{ $index }}.libelle"
                                        size="sm"
                                        class="min-w-64 flex-1"
                                    />
                                    <flux:input
                                        wire:model="lignes.{{ $index }}.statut"
                                        size="sm"
                                        class="w-32"
                                        placeholder="Statut"
                                    />
                                    <flux:button
                                        wire:click="supprimerLigne({{ $lignes[$index]['id'] }})"
                                        icon="trash"
                                        variant="ghost"
                                        size="sm"
                                        inset
                                    />
                                </div>
                            @empty
                                <p class="px-4 py-6 text-center text-sm text-zinc-400">Aucune ligne dans cette rubrique.</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach

                {{-- Ajout d'une ligne --}}
                <form wire:submit="ajouterLigne" class="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-700">
                    <flux:select wire:model="nouvelleRubrique" label="Rubrique" class="w-56">
                        @foreach (FlashItemType::cases() as $cas)
                            <flux:select.option :value="$cas->value">{{ $cas->shortLabel() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="nouvelleDate" type="date" label="Date" class="w-40" />
                    <flux:input wire:model="nouveauLibelle" label="Libellé" class="min-w-64 flex-1" required />
                    <flux:button type="submit" variant="primary" icon="plus">Ajouter</flux:button>
                </form>
            </div>

            {{-- Colonne latérale --}}
            <div class="flex flex-col gap-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">Chiffres du rapport</flux:heading>
                    <flux:subheading>Figés à la publication</flux:subheading>

                    <dl class="mt-3 space-y-2 text-sm">
                        @foreach ([
                            'Date début' => $flashReport->date_debut?->format('d/m/Y'),
                            'Date fin initiale' => $flashReport->date_fin_initiale?->format('d/m/Y'),
                            'Date fin révisée' => $flashReport->date_fin_revisee?->format('d/m/Y'),
                            '% Avancement' => number_format($flashReport->avancement_pct, 2, ',', ' ').' %',
                            'Taux de réalisation' => number_format($flashReport->taux_realisation_pct, 2, ',', ' ').' %',
                            'Étape courante' => $flashReport->workflowStep?->libelle,
                        ] as $label => $valeur)
                            <div class="flex justify-between gap-3">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                <dd class="text-end font-medium tabular-nums">{{ $valeur ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">Synthèse</flux:heading>
                    <flux:textarea wire:model="synthese" rows="8" class="mt-3" placeholder="Message clé à retenir pour le comité…" />
                    <flux:input wire:model="lienPlanning" type="url" label="Lien planning" class="mt-3" placeholder="https://…" />
                </section>
            </div>
        </div>
    @endif
</div>
