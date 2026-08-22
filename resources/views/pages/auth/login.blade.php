<x-layouts::auth :title="__('Connexion')">
    <div class="flex flex-col gap-6">
        <header class="text-center">
            <h1 class="text-3xl font-bold tracking-tight text-mpm-navy dark:text-white">Bon retour !</h1>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                Connectez-vous pour retrouver votre portefeuille projets
                et préparer le comité de la semaine.
            </p>
        </header>

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- @chisel-passkeys --}}
        {{-- Le composant n'apparaît que si l'appareil gère les passkeys,
             et fournit lui-même son séparateur vers la saisie e-mail. --}}
        <x-passkey-verify
            label="Se connecter avec une passkey"
            loading-label="Authentification…"
            separator="Ou continuer avec l'e-mail"
        />
        {{-- @end-chisel-passkeys --}}

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="Adresse e-mail"
                aria-label="Adresse e-mail"
            />

            @error('email')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror

            <!-- Password -->
            <flux:input
                name="password"
                type="password"
                required
                autocomplete="current-password"
                placeholder="Mot de passe"
                aria-label="Mot de passe"
                viewable
            />

            @error('password')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:checkbox name="remember" label="Rester connecté" :checked="old('remember')" />

                @if (Route::has('password.request'))
                    <flux:link class="text-sm" :href="route('password.request')" wire:navigate>
                        Mot de passe oublié ?
                    </flux:link>
                @endif
            </div>

            {{-- Bleu nuit Mediasoft, accent défini sur la carte --}}
            <flux:button variant="primary" type="submit" class="mt-1 w-full" data-test="login-button">
                Se connecter
            </flux:button>
        </form>

        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            Les comptes sont créés par la Direction des Projets &amp; Organisation.
        </p>
    </div>
</x-layouts::auth>
