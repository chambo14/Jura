<?php

use App\Enums\MemberRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ChargeService;
use App\Services\ProjectProvisioner;
use App\Support\Charge\ChargeCollaborateur;
use App\Support\Charge\Periode;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Project $project;

    public ?int $membreEnEdition = null;

    public string $membreUser = '';

    public string $membreRole = MemberRole::Developpeur->value;

    public int $membreAllocation = 100;

    public string $membreDebut = '';

    public string $membreFin = '';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    #[Computed]
    public function peutModifier(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    /**
     * @return Collection<int, ProjectMember>
     */
    #[Computed]
    public function membres(): Collection
    {
        return $this->project->members()
            ->with('user')
            ->get()
            ->sortBy(fn (ProjectMember $m) => array_search($m->role, MemberRole::cases(), true))
            ->values();
    }

    /**
     * Charge du mois pour les personnes affectées à ce projet, tous projets
     * confondus : affectation nominale pondérée et tâches confiées.
     *
     * @return Collection<int, ChargeCollaborateur>
     */
    #[Computed]
    public function planDeCharge(): Collection
    {
        $ids = $this->membres()->pluck('user_id')->unique();

        return app(ChargeService::class)
            ->pourPeriode(Periode::mois(Date::now()))
            ->filter(fn (ChargeCollaborateur $c) => $ids->contains($c->collaborateur->id))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function utilisateurs(): Collection
    {
        return User::actifs()->orderBy('name')->get();
    }

    public function nouveauMembre(): void
    {
        $this->authorize('update', $this->project);

        $this->reset('membreEnEdition', 'membreUser', 'membreDebut', 'membreFin');
        $this->membreRole = MemberRole::Developpeur->value;
        $this->membreAllocation = 100;

        Flux::modal('membre')->show();
    }

    public function editerMembre(int $id): void
    {
        $this->authorize('update', $this->project);

        $membre = $this->project->members()->findOrFail($id);

        $this->membreEnEdition = $membre->id;
        $this->membreUser = (string) $membre->user_id;
        $this->membreRole = $membre->role->value;
        $this->membreAllocation = $membre->allocation_pct;
        $this->membreDebut = $membre->date_debut?->format('Y-m-d') ?? '';
        $this->membreFin = $membre->date_fin?->format('Y-m-d') ?? '';

        Flux::modal('membre')->show();
    }

    public function enregistrerMembre(): void
    {
        $this->authorize('update', $this->project);

        $donnees = $this->validate([
            'membreUser' => ['required', 'exists:users,id'],
            'membreRole' => ['required', 'string'],
            'membreAllocation' => ['required', 'integer', 'min:1', 'max:100'],
            'membreDebut' => ['nullable', 'date'],
            'membreFin' => ['nullable', 'date', 'after_or_equal:membreDebut'],
        ], attributes: [
            'membreUser' => 'collaborateur',
            'membreRole' => 'rôle',
            'membreAllocation' => 'allocation',
        ]);

        $attributs = [
            'user_id' => $donnees['membreUser'],
            'role' => $donnees['membreRole'],
            'allocation_pct' => $donnees['membreAllocation'],
            'date_debut' => $donnees['membreDebut'] ?: null,
            'date_fin' => $donnees['membreFin'] ?: null,
        ];

        $doublon = $this->project->members()
            ->where('user_id', $attributs['user_id'])
            ->where('role', $attributs['role'])
            ->when($this->membreEnEdition, fn ($q) => $q->whereKeyNot($this->membreEnEdition))
            ->exists();

        if ($doublon) {
            $this->addError('membreRole', 'Ce collaborateur occupe déjà ce rôle sur le projet.');

            return;
        }

        if ($this->membreEnEdition) {
            $this->project->members()->whereKey($this->membreEnEdition)->update($attributs);
        } else {
            $this->project->members()->create($attributs);
        }

        $this->synchroniserPilotage($attributs['role'], (int) $attributs['user_id']);

        $this->project->load('members.user');
        unset($this->membres, $this->planDeCharge);

        Flux::modal('membre')->close();
        Flux::toast(variant: 'success', text: 'Affectation enregistrée.');
    }

    public function retirerMembre(int $id): void
    {
        $this->authorize('update', $this->project);

        $membre = $this->project->members()->findOrFail($id);
        $role = $membre->role;
        $membre->delete();

        // Vide l'en-tête de pilotage si le rôle correspondant n'est plus tenu.
        $colonne = $this->colonnePilotage($role->value);

        if ($colonne && ! $this->project->members()->where('role', $role->value)->exists()) {
            $this->project->update([$colonne => null]);
        }

        $this->project->load('members.user');
        unset($this->membres, $this->planDeCharge);

        Flux::toast(variant: 'success', text: 'Affectation retirée.');
    }

    /**
     * Reporte l'affectation dans l'en-tête « PILOTAGE » quand le rôle y figure.
     */
    private function synchroniserPilotage(string $role, int $userId): void
    {
        if ($colonne = $this->colonnePilotage($role)) {
            $this->project->update([$colonne => $userId]);
        }
    }

    private function colonnePilotage(string $role): ?string
    {
        return match ($role) {
            MemberRole::ChefProjet->value => 'chef_projet_id',
            MemberRole::BackUp->value => 'back_up_id',
            MemberRole::Sponsor->value => 'sponsor_id',
            MemberRole::ReferentTechnique->value => 'referent_technique_id',
            default => null,
        };
    }

    public function resynchroniser(): void
    {
        $this->authorize('update', $this->project);

        app(ProjectProvisioner::class)->synchroniserPilotage($this->project);

        $this->project->load('members.user');
        unset($this->membres, $this->planDeCharge);

        Flux::toast(variant: 'success', text: 'Équipe réalignée sur le pilotage.');
    }
}; ?>

<div class="grid gap-6 xl:grid-cols-3">
    <section class="xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Équipe projet</flux:heading>
                <flux:subheading>{{ $this->membres()->count() }} affectation(s)</flux:subheading>
            </div>

            @if ($this->peutModifier())
                <div class="flex gap-2">
                    <flux:button wire:click="resynchroniser" variant="ghost" size="sm" icon="arrow-path">
                        Réaligner sur le pilotage
                    </flux:button>
                    <flux:button wire:click="nouveauMembre" variant="primary" size="sm" icon="plus">
                        Affecter une ressource
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">Collaborateur</th>
                        <th class="px-4 py-2 text-start font-medium">Rôle</th>
                        <th class="px-4 py-2 text-start font-medium">Allocation</th>
                        <th class="px-4 py-2 text-start font-medium">Période</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @forelse ($this->membres() as $membre)
                        <tr wire:key="membre-{{ $membre->id }}">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <flux:avatar :name="$membre->user->name" size="xs" />
                                    <div class="min-w-0">
                                        <div class="truncate font-medium">{{ $membre->user->name }}</div>
                                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $membre->user->poste }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge :color="$membre->role->color()" size="sm">{{ $membre->role->label() }}</flux:badge>
                            </td>
                            <td class="w-40 px-4 py-3">
                                <x-progress-bar :value="$membre->allocation_pct" color="blue" />
                            </td>
                            <td class="px-4 py-3 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                {{ $membre->date_debut?->format('d/m/Y') ?? '—' }} → {{ $membre->date_fin?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                @if ($this->peutModifier())
                                    <flux:button wire:click="editerMembre({{ $membre->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                                    <flux:button
                                        wire:click="retirerMembre({{ $membre->id }})"
                                        wire:confirm="Retirer cette affectation ?"
                                        icon="trash"
                                        variant="ghost"
                                        size="xs"
                                        inset
                                    />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                                Aucune ressource affectée à ce projet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Plan de charge --}}
    <section>
        <div class="flex items-baseline justify-between gap-2">
            <flux:heading size="lg">Plan de charge</flux:heading>
            <flux:link :href="route('plan-de-charge.index')" class="text-sm" wire:navigate>Vue complète</flux:link>
        </div>
        <flux:subheading>Charge du mois, tous projets confondus</flux:subheading>

        <ul class="mt-4 space-y-3">
            @foreach ($this->planDeCharge() as $charge)
                <li class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('plan-de-charge.show', $charge->collaborateur) }}" wire:navigate class="truncate text-sm font-medium hover:underline">
                            {{ $charge->collaborateur->name }}
                        </a>
                        <span @class([
                            'text-sm font-semibold tabular-nums',
                            'text-red-600 dark:text-red-400' => $charge->couleur() === 'red',
                            'text-amber-600 dark:text-amber-400' => $charge->couleur() === 'amber',
                            'text-zinc-600 dark:text-zinc-300' => $charge->couleur() === 'green',
                        ])>{{ number_format($charge->total(), 1, ',', ' ') }} %</span>
                    </div>

                    <x-progress-bar
                        class="mt-2"
                        :value="min(100, $charge->total())"
                        :color="$charge->couleur() === 'red' ? 'red' : ($charge->couleur() === 'amber' ? 'amber' : 'green')"
                        :show-value="false"
                    />

                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $charge->nombreProjets() }} projet(s) · {{ $charge->tachesOuvertes() }} tâche(s)
                        @if ($charge->deleguee() > 0)
                            · {{ number_format($charge->deleguee(), 0) }} % délégué
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Modale d'affectation --}}
    <flux:modal name="membre" class="md:w-96">
        <form wire:submit="enregistrerMembre" class="space-y-5">
            <flux:heading size="lg">{{ $membreEnEdition ? "Modifier l'affectation" : 'Affecter une ressource' }}</flux:heading>

            <flux:select wire:model="membreUser" label="Collaborateur" placeholder="Choisir…" required>
                @foreach ($this->utilisateurs() as $utilisateur)
                    <flux:select.option :value="$utilisateur->id">{{ $utilisateur->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="membreRole" label="Rôle">
                @foreach (MemberRole::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="membreAllocation" type="number" min="1" max="100" label="Allocation (%)" />

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="membreDebut" type="date" label="Début" />
                <flux:input wire:model="membreFin" type="date" label="Fin" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
