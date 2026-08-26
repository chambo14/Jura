<?php

use App\Models\Attachment;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use App\Services\AttachmentService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Project $project;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fichiers = [];

    /**
     * Cible du dépôt, sous la forme "projet", "livrable:12" ou "tache:34".
     */
    public string $cible = 'projet';

    public string $description = '';

    public string $filtre = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    #[Computed]
    public function peutContribuer(): bool
    {
        return auth()->user()->can('contribute', $this->project);
    }

    /**
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function pieces(): Collection
    {
        return $this->project->documents()
            ->with(['deposePar', 'attachable'])
            ->when($this->filtre !== '', fn ($q) => $q->where('nom', 'like', '%'.$this->filtre.'%'))
            ->get();
    }

    /**
     * @return Collection<int, Deliverable>
     */
    #[Computed]
    public function livrables(): Collection
    {
        return $this->project->deliverables()->orderBy('ordre')->get();
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function taches(): Collection
    {
        return $this->project->tasks()->orderBy('libelle')->get();
    }

    #[Computed]
    public function volume(): string
    {
        $total = (int) $this->project->documents()->sum('taille');

        return (new Attachment(['taille' => $total]))->tailleLisible();
    }

    public function deposer(): void
    {
        $this->authorize('contribute', $this->project);

        $extensions = implode(',', (array) config('documents.extensions'));

        $this->validate([
            'fichiers' => ['required', 'array', 'min:1', 'max:10'],
            'fichiers.*' => ['file', 'max:'.(int) config('documents.taille_max_ko'), 'mimes:'.$extensions],
            'description' => ['nullable', 'string', 'max:255'],
        ], attributes: ['fichiers' => 'fichiers', 'fichiers.*' => 'fichier']);

        $cible = $this->resoudreCible();
        $service = app(AttachmentService::class);

        foreach ($this->fichiers as $fichier) {
            $service->deposer($cible, $fichier, auth()->user(), $this->description);
        }

        $nombre = count($this->fichiers);

        $this->reset('fichiers', 'description');
        unset($this->pieces, $this->volume);

        Flux::toast(
            variant: 'success',
            text: $nombre > 1 ? $nombre.' fichiers déposés.' : 'Fichier déposé.',
        );
    }

    public function retirerFichier(int $id): void
    {
        $piece = $this->project->documents()->findOrFail($id);

        $this->authorize('delete', $piece);

        app(AttachmentService::class)->supprimer($piece);

        unset($this->pieces, $this->volume);

        Flux::toast(variant: 'success', text: 'Fichier retiré.');
    }

    /**
     * Traduit le choix du formulaire en modèle porteur, en vérifiant que la
     * cible appartient bien au projet : l'identifiant vient du navigateur.
     */
    private function resoudreCible(): Project|Deliverable|Task
    {
        [$type, $id] = array_pad(explode(':', $this->cible, 2), 2, null);

        return match ($type) {
            'livrable' => $this->project->deliverables()->findOrFail($id),
            'tache' => $this->project->tasks()->findOrFail($id),
            default => $this->project,
        };
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="grid gap-3 sm:grid-cols-3">
        <x-stat label="Fichiers" :value="$this->pieces()->count()" icon="paper-clip" color="blue" />
        <x-stat label="Volume" :value="$this->volume()" icon="circle-stack" color="violet" />
        <x-stat
            label="Dernier dépôt"
            :value="$this->pieces()->first()?->created_at?->format('d/m/Y') ?? '—'"
            icon="clock"
            color="green"
        />
    </div>

    @if ($this->peutContribuer())
        <form wire:submit="deposer" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">Déposer des fichiers</flux:heading>
            <flux:text size="sm" class="mt-1">
                {{ (int) (config('documents.taille_max_ko') / 1024) }} Mo par fichier, 10 fichiers à la fois.
                Formats bureautiques, images et archives.
            </flux:text>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <flux:input type="file" wire:model="fichiers" label="Fichiers" multiple />

                <flux:select wire:model="cible" label="Rattacher à">
                    <flux:select.option value="projet">Le projet</flux:select.option>

                    @foreach ($this->livrables() as $livrable)
                        <flux:select.option value="livrable:{{ $livrable->id }}">Livrable — {{ $livrable->nom }}</flux:select.option>
                    @endforeach

                    @foreach ($this->taches() as $tache)
                        <flux:select.option value="tache:{{ $tache->id }}">Tâche — {{ $tache->libelle }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="description" label="Description" placeholder="Version, objet du document…" />
            </div>

            <div class="mt-4 flex items-center justify-between gap-3">
                <flux:text size="sm" wire:loading wire:target="fichiers">Transfert en cours…</flux:text>
                <flux:spacer />
                <flux:button type="submit" variant="primary" size="sm" icon="arrow-up-tray">Déposer</flux:button>
            </div>
        </form>
    @endif

    <div class="flex items-end justify-between gap-3">
        <flux:input wire:model.live.debounce.300ms="filtre" placeholder="Rechercher un fichier…" icon="magnifying-glass" class="max-w-xs" />
    </div>

    <div class="rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
        <x-attachment-list
            :attachments="$this->pieces()"
            :peut-retirer="$this->peutContribuer()"
            :rattachement="true"
            vide="Aucun fichier n'est encore rattaché à ce projet."
        />
    </div>
</div>
