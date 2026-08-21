<?php

use App\Enums\GovernanceBody;
use App\Enums\ProjectType;
use App\Models\Phase;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Référentiel MPM')] class extends Component {
    public string $typeAffiche = ProjectType::Deploiement->value;

    /**
     * @return Collection<int, Phase>
     */
    #[Computed]
    public function phases(): Collection
    {
        return Phase::ordered()->with('deliverableTypes')->get();
    }

    /**
     * @return Collection<int, WorkflowStep>
     */
    #[Computed]
    public function etapes(): Collection
    {
        return WorkflowStep::forType(ProjectType::from($this->typeAffiche))->with('phase')->get();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl">Référentiel MPM</flux:heading>
        <flux:subheading>
            Méthodologie Projet MEDIASOFT — phases, livrables types et instances de gouvernance
        </flux:subheading>
    </div>

    {{-- Les 8 phases --}}
    <section>
        <flux:heading size="lg" class="mb-3">Les 8 phases</flux:heading>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->phases() as $phase)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2">
                        <span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ $phase->ordre }}
                        </span>
                        <h3 class="font-semibold">{{ $phase->nom }}</h3>
                    </div>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $phase->description }}</p>
                    <p class="mt-2 text-xs text-zinc-400">
                        {{ $phase->deliverableTypes->count() }} livrable(s) type(s)
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Livrables par phase --}}
    <section>
        <flux:heading size="lg" class="mb-3">Livrables types par phase</flux:heading>

        <div class="space-y-4">
            @foreach ($this->phases() as $phase)
                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <header class="border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-700 dark:bg-zinc-800/60">
                        <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ $phase->ordre }}. {{ $phase->nom }}
                        </h3>
                    </header>

                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach ($phase->deliverableTypes as $type)
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <span class="font-medium">{{ $type->nom }}</span>
                                        @unless ($type->obligatoire)
                                            <flux:badge color="zinc" size="sm" class="ms-2">Facultatif</flux:badge>
                                        @endunless
                                        @if ($type->types_projet)
                                            <span class="ms-2 text-xs text-zinc-400">
                                                ({{ collect($type->types_projet)->map(fn ($t) => ProjectType::from($t)->label())->implode(', ') }})
                                            </span>
                                        @endif
                                    </td>
                                    <td class="w-48 px-4 py-2.5 text-end text-zinc-500 dark:text-zinc-400">
                                        {{ $type->responsable }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Bandeau d'avancement par type de projet --}}
    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg">Bandeau d'avancement</flux:heading>
                <flux:subheading>Étapes affichées sur le flash report selon le type de projet</flux:subheading>
            </div>

            <flux:select wire:model.live="typeAffiche" class="w-56">
                @foreach (ProjectType::cases() as $cas)
                    <flux:select.option :value="$cas->value">{{ $cas->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <ol class="flex flex-wrap gap-2">
            @foreach ($this->etapes() as $etape)
                <li class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <span class="text-xs font-mono text-zinc-400">{{ $etape->ordre }}</span>
                    <span>{{ $etape->libelle }}</span>
                    @if ($etape->phase)
                        <flux:badge color="zinc" size="sm">{{ $etape->phase->nom }}</flux:badge>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Gouvernance --}}
    <section>
        <flux:heading size="lg" class="mb-3">Instances de gouvernance</flux:heading>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach (GovernanceBody::cases() as $instance)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ $instance->acronym() }}</span>
                        <flux:badge color="zinc" size="sm">{{ $instance->cadence() }}</flux:badge>
                    </div>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $instance->label() }}</p>
                </div>
            @endforeach
        </div>
    </section>
</div>
