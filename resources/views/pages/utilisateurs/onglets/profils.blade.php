<?php

use App\Enums\Permission;
use App\Models\Profile;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $enEdition = null;

    public string $nom = '';

    public string $description = '';

    public string $couleur = 'zinc';

    /**
     * Droits cochés dans le formulaire, indexés par code de permission.
     *
     * @var array<string, bool>
     */
    public array $droits = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Profile::class);

        $this->droits = array_fill_keys(Permission::codes(), false);
    }

    /**
     * @return Collection<int, Profile>
     */
    #[Computed]
    public function profils(): Collection
    {
        return Profile::ordered()->withCount(['users', 'users as users_actifs_count' => fn ($q) => $q->where('actif', true)])->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function couleurs(): array
    {
        return ['zinc', 'blue', 'purple', 'violet', 'amber', 'green', 'teal', 'red'];
    }

    public function nouveau(): void
    {
        $this->authorize('create', Profile::class);

        $this->reset('enEdition', 'nom', 'description');
        $this->couleur = 'zinc';
        $this->droits = array_fill_keys(Permission::codes(), false);
        $this->resetValidation();

        Flux::modal('profil')->show();
    }

    public function editer(int $id): void
    {
        $profil = Profile::findOrFail($id);

        $this->authorize('update', $profil);

        $this->enEdition = $profil->id;
        $this->nom = $profil->nom;
        $this->description = $profil->description ?? '';
        $this->couleur = $profil->couleur;
        $this->droits = collect(Permission::codes())
            ->mapWithKeys(fn (string $code) => [$code => (bool) $profil->getAttribute($code)])
            ->all();
        $this->resetValidation();

        Flux::modal('profil')->show();
    }

    public function enregistrer(): void
    {
        $donnees = $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'couleur' => ['required', 'string', Rule::in($this->couleurs())],
            'droits' => ['array'],
            'droits.*' => ['boolean'],
        ], attributes: ['nom' => 'nom du profil', 'couleur' => 'couleur']);

        $permissions = collect(Permission::codes())
            ->mapWithKeys(fn (string $code) => [$code => (bool) ($donnees['droits'][$code] ?? false)])
            ->all();

        if ($this->enEdition) {
            $profil = Profile::findOrFail($this->enEdition);
            $this->authorize('update', $profil);

            // Garde-fou : ne pas retirer le dernier droit d'administration en service.
            $retireLAdministration = $profil->gere_utilisateurs && ! $permissions[Permission::GereUtilisateurs->value];

            if ($retireLAdministration && ! $profil->peutPerdreLAdministration()) {
                $this->addError(
                    'droits.'.Permission::GereUtilisateurs->value,
                    "C'est le seul profil administrateur encore attribué : retirer ce droit rendrait l'administration inaccessible.",
                );

                return;
            }

            $profil->update([
                'nom' => $donnees['nom'],
                'description' => $donnees['description'] ?: null,
                'couleur' => $donnees['couleur'],
                ...$permissions,
            ]);

            Flux::toast(variant: 'success', text: 'Profil mis à jour.');
        } else {
            $this->authorize('create', Profile::class);

            Profile::create([
                'code' => $this->codeDisponible($donnees['nom']),
                'nom' => $donnees['nom'],
                'description' => $donnees['description'] ?: null,
                'couleur' => $donnees['couleur'],
                'ordre' => (int) (Profile::max('ordre') ?? 0) + 1,
                'systeme' => false,
                ...$permissions,
            ]);

            Flux::toast(variant: 'success', text: 'Profil créé.');
        }

        unset($this->profils);

        Flux::modal('profil')->close();
    }

    public function supprimer(int $id): void
    {
        $profil = Profile::findOrFail($id);

        $this->authorize('delete', $profil);

        $profil->delete();

        unset($this->profils);

        Flux::toast(variant: 'success', text: 'Profil supprimé.');
    }

    /**
     * Code technique dérivé du nom, rendu unique par suffixe si besoin.
     */
    private function codeDisponible(string $nom): string
    {
        $base = Str::slug($nom, '_') ?: 'profil';
        $code = $base;
        $suffixe = 2;

        while (Profile::where('code', $code)->exists()) {
            $code = $base.'_'.$suffixe++;
        }

        return $code;
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex justify-end">
        <flux:button wire:click="nouveau" icon="plus" variant="primary">Nouveau profil</flux:button>
    </div>

    <flux:callout icon="information-circle" color="blue">
        Les quatre profils MPM livrés avec l'application ne peuvent pas être supprimés,
        mais leurs droits restent ajustables. Un profil que vous créez est supprimable
        tant qu'aucun compte ne lui est rattaché.
    </flux:callout>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($this->profils() as $profil)
            <section
                class="flex flex-col rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                wire:key="profil-{{ $profil->id }}"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$profil->couleur" size="sm">{{ $profil->nom }}</flux:badge>
                            @if ($profil->systeme)
                                <flux:badge color="zinc" size="sm">Système</flux:badge>
                            @endif
                        </div>
                        @if ($profil->description)
                            <p class="mt-2 text-sm leading-snug text-zinc-600 dark:text-zinc-300">{{ $profil->description }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <flux:tooltip content="Modifier">
                            <flux:button wire:click="editer({{ $profil->id }})" icon="pencil-square" variant="ghost" size="xs" inset />
                        </flux:tooltip>

                        @can('delete', $profil)
                            <flux:tooltip content="Supprimer">
                                <flux:button
                                    wire:click="supprimer({{ $profil->id }})"
                                    wire:confirm="Supprimer ce profil ?"
                                    icon="trash"
                                    variant="ghost"
                                    size="xs"
                                    inset
                                />
                            </flux:tooltip>
                        @endcan
                    </div>
                </div>

                {{-- Droits accordés, en toutes lettres --}}
                <ul class="mt-3 space-y-1.5">
                    @foreach (Permission::cases() as $permission)
                        @php $accorde = $profil->accorde($permission); @endphp

                        <li class="flex items-start gap-2 text-sm">
                            <flux:icon
                                :name="$accorde ? 'check-circle' : 'x-circle'"
                                variant="mini"
                                @class([
                                    'mt-0.5 shrink-0',
                                    'text-green-600 dark:text-green-400' => $accorde,
                                    'text-zinc-300 dark:text-zinc-600' => ! $accorde,
                                ])
                            />
                            <span @class([
                                'text-zinc-700 dark:text-zinc-200' => $accorde,
                                'text-zinc-400 dark:text-zinc-500' => ! $accorde,
                            ])>{{ $permission->label() }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    {{ $profil->users_actifs_count }} compte(s) actif(s)
                    @if ($profil->users_count > $profil->users_actifs_count)
                        · {{ $profil->users_count - $profil->users_actifs_count }} désactivé(s)
                    @endif
                    @if ($profil->lectureSeule())
                        · <span class="font-medium">consultation seule</span>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    {{-- Création / modification --}}
    <flux:modal name="profil" class="md:w-[34rem]">
        <form wire:submit="enregistrer" class="space-y-5">
            <flux:heading size="lg">{{ $enEdition ? 'Modifier le profil' : 'Nouveau profil' }}</flux:heading>

            <flux:input wire:model="nom" label="Nom du profil" placeholder="Responsable qualité" required />
            <flux:textarea wire:model="description" label="Description" rows="2" placeholder="À quoi sert ce profil ?" />

            <flux:select wire:model="couleur" label="Couleur">
                @foreach ($this->couleurs() as $teinte)
                    <flux:select.option :value="$teinte">{{ Str::ucfirst($teinte) }}</flux:select.option>
                @endforeach
            </flux:select>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Droits accordés</legend>

                @foreach (Permission::cases() as $permission)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:checkbox wire:model="droits.{{ $permission->value }}" class="mt-0.5" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $permission->label() }}</span>
                            <span class="block text-xs leading-snug text-zinc-500 dark:text-zinc-400">{{ $permission->description() }}</span>
                        </span>
                    </label>

                    @error('droits.'.$permission->value)
                        <flux:text class="!text-red-600 dark:!text-red-400">{{ $message }}</flux:text>
                    @enderror
                @endforeach
            </fieldset>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
