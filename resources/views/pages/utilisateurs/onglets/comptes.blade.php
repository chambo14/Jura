<?php

use App\Models\Profile;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url(as: 'q', except: '')]
    public string $recherche = '';

    #[Url(except: '')]
    public string $filtreProfil = '';

    public bool $inclureInactifs = false;

    public ?int $enEdition = null;

    public string $nom = '';

    public string $email = '';

    public string $profilId = '';

    public string $poste = '';

    public string $equipe = '';

    public string $telephone = '';

    public bool $actif = true;

    /**
     * Mot de passe provisoire affiché une seule fois après création.
     */
    public ?string $motDePasseProvisoire = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function utilisateurs(): Collection
    {
        return User::query()
            ->when($this->recherche !== '', function ($query) {
                $terme = '%'.$this->recherche.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $terme)->orWhere('email', 'like', $terme));
            })
            ->when($this->filtreProfil !== '', fn ($q) => $q->where('profile_id', $this->filtreProfil))
            ->when(! $this->inclureInactifs, fn ($q) => $q->where('actif', true))
            ->with('profile')
            ->withCount('projetsPilotes')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Profile>
     */
    #[Computed]
    public function profils(): Collection
    {
        return Profile::ordered()
            ->withCount(['users as comptes_actifs' => fn ($q) => $q->where('actif', true)])
            ->get();
    }

    public function nouveau(): void
    {
        $this->authorize('create', User::class);

        $this->reset('enEdition', 'nom', 'email', 'poste', 'equipe', 'telephone', 'motDePasseProvisoire');
        $this->profilId = (string) ($this->profils()->last()?->id ?? '');
        $this->actif = true;
        $this->resetValidation();

        Flux::modal('utilisateur')->show();
    }

    public function editer(int $id): void
    {
        $utilisateur = User::findOrFail($id);

        $this->authorize('update', $utilisateur);

        $this->remplirFormulaire($utilisateur);
        $this->motDePasseProvisoire = null;
        $this->resetValidation();

        Flux::modal('utilisateur')->show();
    }

    public function enregistrer(): void
    {
        $donnees = $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->enEdition)],
            'profilId' => ['required', 'exists:profiles,id'],
            'poste' => ['nullable', 'string', 'max:255'],
            'equipe' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:40'],
            'actif' => ['boolean'],
        ], attributes: [
            'nom' => 'nom',
            'email' => 'adresse e-mail',
            'profilId' => 'profil',
        ]);

        $attributs = [
            'name' => $donnees['nom'],
            'email' => Str::lower($donnees['email']),
            'profile_id' => $donnees['profilId'],
            'poste' => $donnees['poste'] ?: null,
            'equipe' => $donnees['equipe'] ?: null,
            'telephone' => $donnees['telephone'] ?: null,
            'actif' => $donnees['actif'],
        ];

        if ($this->enEdition) {
            $utilisateur = User::findOrFail($this->enEdition);
            $this->authorize('update', $utilisateur);

            // On ne se retire pas soi-même l'accès à l'application.
            if (auth()->user()->is($utilisateur)) {
                $attributs['actif'] = true;
                $attributs['profile_id'] = $utilisateur->profile_id;
            }

            $utilisateur->update($attributs);

            Flux::modal('utilisateur')->close();
            Flux::toast(variant: 'success', text: 'Compte mis à jour.');
        } else {
            $this->authorize('create', User::class);

            // Mot de passe provisoire : communiqué au collaborateur, à changer ensuite.
            $provisoire = Str::password(14);

            $utilisateur = User::create([...$attributs, 'password' => $provisoire]);

            // email_verified_at ne figure pas dans l'attribut #[Fillable] du modèle :
            // passé à create(), il serait écarté sans le moindre avertissement.
            $utilisateur->forceFill(['email_verified_at' => now()])->save();

            $this->motDePasseProvisoire = $provisoire;

            Flux::toast(variant: 'success', text: 'Compte créé.');
        }

        unset($this->utilisateurs, $this->profils);
    }

    /**
     * Régénère un mot de passe provisoire pour un compte existant.
     */
    public function reinitialiserMotDePasse(int $id): void
    {
        $utilisateur = User::findOrFail($id);

        $this->authorize('update', $utilisateur);

        $provisoire = Str::password(14);
        $utilisateur->update(['password' => $provisoire]);

        $this->remplirFormulaire($utilisateur);
        $this->motDePasseProvisoire = $provisoire;

        Flux::modal('utilisateur')->show();
    }

    public function basculerActivation(int $id): void
    {
        $utilisateur = User::findOrFail($id);

        $this->authorize('deactivate', $utilisateur);

        $utilisateur->update(['actif' => ! $utilisateur->actif]);

        unset($this->utilisateurs, $this->profils);

        Flux::toast(
            variant: 'success',
            text: $utilisateur->actif ? 'Compte réactivé.' : 'Compte désactivé.',
        );
    }

    private function remplirFormulaire(User $utilisateur): void
    {
        $this->enEdition = $utilisateur->id;
        $this->nom = $utilisateur->name;
        $this->email = $utilisateur->email;
        $this->profilId = (string) ($utilisateur->profile_id ?? '');
        $this->poste = $utilisateur->poste ?? '';
        $this->equipe = $utilisateur->equipe ?? '';
        $this->telephone = $utilisateur->telephone ?? '';
        $this->actif = $utilisateur->actif;
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex justify-end">
        <flux:button wire:click="nouveau" icon="plus" variant="primary">Nouveau compte</flux:button>
    </div>

    {{-- Répartition par profil --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->profils() as $profil)
            <x-stat
                :label="$profil->nom"
                :value="$profil->comptes_actifs"
                icon="users"
                :color="in_array($profil->couleur, ['violet', 'purple'], true) ? 'violet' : (in_array($profil->couleur, ['amber', 'blue', 'green', 'red'], true) ? $profil->couleur : 'zinc')"
            />
        @endforeach
    </div>

    {{-- Filtres --}}
    <div class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="recherche" icon="magnifying-glass" placeholder="Nom ou e-mail…" label="Recherche" />

        <flux:select wire:model.live="filtreProfil" label="Profil">
            <flux:select.option value="">Tous les profils</flux:select.option>
            @foreach ($this->profils() as $profil)
                <flux:select.option :value="$profil->id">{{ $profil->nom }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox wire:model.live="inclureInactifs" label="Afficher les comptes désactivés" class="self-end pb-2" />
    </div>

    {{-- Liste --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">Collaborateur</th>
                    <th class="px-4 py-2 text-start font-medium">Profil</th>
                    <th class="px-4 py-2 text-start font-medium">Poste</th>
                    <th class="px-4 py-2 text-center font-medium">Projets pilotés</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($this->utilisateurs() as $utilisateur)
                    <tr wire:key="utilisateur-{{ $utilisateur->id }}" @class(['opacity-50' => ! $utilisateur->actif])>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <flux:avatar :name="$utilisateur->name" size="xs" />
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium">{{ $utilisateur->name }}</span>
                                        @unless ($utilisateur->actif)
                                            <flux:badge color="zinc" size="sm">Désactivé</flux:badge>
                                        @endunless
                                    </div>
                                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $utilisateur->email }}</div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            @if ($utilisateur->profile)
                                <flux:badge :color="$utilisateur->profile->couleur" size="sm">
                                    {{ $utilisateur->profile->nom }}
                                </flux:badge>
                            @else
                                <flux:badge color="red" size="sm">Sans profil</flux:badge>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $utilisateur->poste ?? '—' }}
                            @if ($utilisateur->equipe)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $utilisateur->equipe }}</div>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-center tabular-nums text-zinc-600 dark:text-zinc-300">
                            {{ $utilisateur->projets_pilotes_count }}
                        </td>

                        <td class="px-4 py-3 text-end whitespace-nowrap">
                            <flux:tooltip content="Modifier">
                                <flux:button wire:click="editer({{ $utilisateur->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                            </flux:tooltip>

                            <flux:tooltip content="Réinitialiser le mot de passe">
                                <flux:button
                                    wire:click="reinitialiserMotDePasse({{ $utilisateur->id }})"
                                    wire:confirm="Générer un nouveau mot de passe provisoire pour ce compte ?"
                                    icon="key"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                />
                            </flux:tooltip>

                            @can('deactivate', $utilisateur)
                                <flux:tooltip :content="$utilisateur->actif ? 'Désactiver' : 'Réactiver'">
                                    <flux:button
                                        wire:click="basculerActivation({{ $utilisateur->id }})"
                                        :icon="$utilisateur->actif ? 'no-symbol' : 'arrow-path'"
                                        variant="ghost"
                                        size="xs"
                                        inset
                                    />
                                </flux:tooltip>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-500 dark:text-zinc-400">
                            Aucun compte ne correspond à ces critères.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Création / modification --}}
    <flux:modal name="utilisateur" class="md:w-[30rem]">
        <form wire:submit="enregistrer" class="space-y-5">
            <flux:heading size="lg">{{ $enEdition ? 'Modifier le compte' : 'Nouveau compte' }}</flux:heading>

            @if ($motDePasseProvisoire)
                <flux:callout icon="key" color="amber" heading="Mot de passe provisoire">
                    <p class="mt-1 font-mono text-base tracking-wide">{{ $motDePasseProvisoire }}</p>
                    <p class="mt-2 text-xs">
                        Communiquez-le au collaborateur : il ne sera plus affiché après fermeture
                        de cette fenêtre. Invitez-le à le changer dès sa première connexion.
                    </p>
                </flux:callout>
            @endif

            <flux:input wire:model="nom" label="Nom et prénoms" required />
            <flux:input wire:model="email" type="email" label="Adresse e-mail" required />

            <flux:select wire:model="profilId" label="Profil" required>
                <flux:select.option value="">Choisir un profil…</flux:select.option>
                @foreach ($this->profils() as $profil)
                    <flux:select.option :value="$profil->id">{{ $profil->nom }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="poste" label="Poste" placeholder="Chef de projet" />
                <flux:input
                    wire:model="equipe"
                    label="Équipe métier"
                    placeholder="Monétique, Digital, Recette…"
                    description="Maille de lecture du tableau des équipes"
                />
            </div>

            <flux:input wire:model="telephone" label="Téléphone" />

            <flux:checkbox wire:model="actif" label="Compte actif" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Fermer</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
