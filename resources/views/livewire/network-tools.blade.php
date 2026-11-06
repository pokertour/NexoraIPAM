<div>
    <div class="page-heading">
        <div><p class="eyebrow">UTILITAIRES RÉSEAU</p><h1 class="page-title mt-2">Outils</h1><p class="muted mt-2">Calculez une plage CIDR et identifiez le titulaire d’un préfixe MAC.</p></div>
    </div>

    <div class="settings-grid dashboard-reveal">
        <section class="card settings-card">
            <div><h2>Calculatrice IPv4 et IPv6</h2><p>Réseau, limites de la plage et nombre d’adresses.</p></div>
            <form wire:submit="calculate" class="grid gap-4">
                <x-field name="cidr" label="Adresse et préfixe CIDR" wire:model="cidr" placeholder="192.0.2.15/24 ou 2001:db8::1/64" maxlength="49" required/>
                <x-button type="submit" class="justify-self-start">Calculer</x-button>
            </form>
            @if ($network)
                <dl class="grid gap-2 border-t border-slate-500/20 pt-4 text-sm">
                    @foreach (['version' => 'Famille', 'cidr' => 'Réseau', 'mask' => 'Masque', 'broadcast' => 'Broadcast', 'first' => 'Première adresse', 'last' => 'Dernière adresse', 'capacity' => 'Adresses dans la plage utilisable'] as $key => $label)
                        @if ($network[$key] !== null)
                            <div class="flex flex-wrap justify-between gap-x-3"><dt class="muted">{{ $label }}</dt><dd><code>{{ $network[$key] }}</code></dd></div>
                        @endif
                    @endforeach
                </dl>
            @endif
        </section>

        <section class="card settings-card">
            <div><h2>Recherche MAC</h2><p>Recherche locale dans les préfixes publics IEEE.</p></div>
            <form wire:submit="lookup" class="grid gap-4">
                <x-field name="mac" label="Adresse MAC" wire:model="mac" placeholder="00:11:22:33:44:55" maxlength="32" required/>
                <x-button type="submit" class="justify-self-start">Rechercher</x-button>
            </form>
            @if ($vendor)
                <div class="border-t border-slate-500/20 pt-4">
                    <code>{{ $vendor['mac'] }}</code>
                    @switch($vendor['kind'])
                        @case('found')
                            <p class="mt-2 font-semibold">{{ $vendor['organization'] }}</p>
                            <p class="muted">Préfixe {{ $vendor['prefix'] }} · {{ $vendor['registry'] }}</p>
                            @break
                        @case('group') <p class="muted mt-2">Adresse de groupe ou de diffusion.</p> @break
                        @case('local') <p class="muted mt-2">Adresse administrée localement, par exemple une MAC aléatoire. Son fabricant ne peut pas être déduit du préfixe.</p> @break
                        @case('unavailable') <p class="muted mt-2">Le registre local est indisponible. Installez les données IEEE sur le serveur.</p> @break
                        @default <p class="muted mt-2">Aucun titulaire trouvé dans les registres installés.</p>
                    @endswitch
                </div>
            @endif
        </section>
    </div>
</div>
