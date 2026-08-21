<?php

use App\Models\FlashReport;
use App\Models\Project;
use App\Services\FlashReportBuilder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public string $semaine = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->semaine = FlashReportBuilder::lundiDe()->format('Y-m-d');
    }

    #[Computed]
    public function peutRediger(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    /**
     * @return Collection<int, FlashReport>
     */
    #[Computed]
    public function rapports(): Collection
    {
        return $this->project->flashReports()->with('auteur')->get();
    }

    #[Computed]
    public function dernier(): ?FlashReport
    {
        return $this->rapports()->first()?->load(['items', 'project.steps.workflowStep', 'project.members.user']);
    }

    /**
     * Crée (ou récupère) le rapport de la semaine choisie et ouvre sa rédaction.
     */
    public function preparer(): void
    {
        $this->authorize('update', $this->project);

        $this->validate(
            ['semaine' => ['required', 'date']],
            attributes: ['semaine' => 'semaine'],
        );

        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->project->load(['tasks', 'milestones', 'deliverables']),
            \Illuminate\Support\Facades\Date::parse($this->semaine),
            auth()->id(),
        );

        $this->redirectRoute('flash-reports.edit', $rapport, navigate: true);
    }

    public function supprimer(int $id): void
    {
        $rapport = $this->project->flashReports()->findOrFail($id);

        $this->authorize('delete', $rapport);

        $rapport->delete();
        unset($this->rapports, $this->dernier);

        Flux::toast(variant: 'success', text: 'Flash report supprimé.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="lg">Flash reports hebdomadaires</flux:heading>
            <flux:subheading>{{ $this->rapports()->count() }} rapport(s) archivé(s)</flux:subheading>
        </div>

        @if ($this->peutRediger())
            <div class="flex items-end gap-2">
                <flux:input wire:model="semaine" type="date" label="Semaine du (lundi)" class="w-48" />
                <flux:button wire:click="preparer" variant="primary" icon="sparkles">
                    Préparer le rapport
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->peutRediger())
        <flux:callout icon="information-circle" color="blue">
            Le rapport est pré-rempli automatiquement : activités réalisées sur la semaine choisie,
            activités à réaliser la semaine suivante, et points d'attention issus des dérives détectées.
        </flux:callout>
    @endif

    {{-- Historique --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">Semaine</th>
                    <th class="px-4 py-2 text-start font-medium">Avancement</th>
                    <th class="px-4 py-2 text-start font-medium">Santé</th>
                    <th class="px-4 py-2 text-start font-medium">Auteur</th>
                    <th class="px-4 py-2 text-start font-medium">Statut</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($this->rapports() as $rapport)
                    <tr wire:key="rapport-{{ $rapport->id }}">
                        <td class="px-4 py-3 tabular-nums">
                            <a href="{{ route('flash-reports.edit', $rapport) }}" wire:navigate class="font-medium hover:underline">
                                {{ $rapport->semaine_du->format('d/m/Y') }} → {{ $rapport->semaine_au->format('d/m/Y') }}
                            </a>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format($rapport->avancement_pct, 2, ',', ' ') }} %</td>
                        <td class="px-4 py-3">
                            @if ($rapport->sante)
                                <flux:badge :color="$rapport->sante->color()" size="sm">{{ $rapport->sante->label() }}</flux:badge>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $rapport->auteur?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$rapport->statut->color()" size="sm">{{ $rapport->statut->label() }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <flux:button :href="route('flash-reports.edit', $rapport)" icon="arrow-right" variant="ghost" size="xs" inset wire:navigate />
                            @can('delete', $rapport)
                                <flux:button
                                    wire:click="supprimer({{ $rapport->id }})"
                                    wire:confirm="Supprimer ce flash report ?"
                                    icon="trash"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                            Aucun flash report pour ce projet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Aperçu du dernier rapport --}}
    @if ($this->dernier())
        <div>
            <flux:heading size="lg" class="mb-3">Dernier rapport</flux:heading>
            <x-flash-slide :report="$this->dernier()" />
        </div>
    @endif
</div>
