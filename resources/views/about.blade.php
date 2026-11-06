<x-layouts.app title="À propos">
    <div class="about-page">
        <header class="card about-intro">
            <div class="about-intro-copy">
                <x-logo class="block w-56 max-w-full sm:w-64"/>
                <h1 class="page-title mt-4">À propos de NexoraIPAM</h1>
                <p class="muted mt-2">Gestion open source des adresses IP, des sous-réseaux et des VLAN.</p>
            </div>
        </header>

        <div class="settings-grid about-details">
            <section class="card settings-card">
                <div class="about-section-heading">
                    <span class="stat-icon tone-violet"><x-icon name="vlans" class="size-4"/></span>
                    <h2>Versions et mises à jour</h2>
                </div>

                <dl class="about-facts">
                    <div><dt>Version installée</dt><dd><code>{{ $version }}</code></dd></div>
                    <div>
                        <dt>Dernière version sur GitHub</dt>
                        <dd>
                            @if ($latest['status'] === 'found')
                                <a class="table-link font-semibold" href="{{ $repositoryUrl }}/releases/tag/{{ $latest['version'] }}">{{ $latest['version'] }}</a>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($updateAvailable === true)
                    <div><span class="badge tone-amber">Mise à jour disponible</span></div>
                @elseif ($updateAvailable === false)
                    <div><span class="badge tone-cyan">À jour</span></div>
                @elseif ($latest['status'] === 'found')
                    <p>Comparaison indisponible pour cette version de développement.</p>
                @elseif ($latest['status'] === 'missing')
                    <p>Aucune release GitHub accessible pour ce dépôt.</p>
                @else
                    <p>Impossible de vérifier les mises à jour pour le moment.</p>
                @endif
            </section>

            <section class="card settings-card">
                <div class="about-section-heading">
                    <span class="stat-icon tone-blue"><x-icon name="info" class="size-4"/></span>
                    <h2>Projet open source</h2>
                </div>

                <dl class="about-facts">
                    <div><dt>Auteur</dt><dd>pokertour</dd></div>
                    <div><dt>Licence</dt><dd><a class="table-link" href="{{ $repositoryUrl }}/blob/HEAD/LICENSE">GNU AGPL v3</a></dd></div>
                </dl>

                <div>
                    <a class="button button-secondary" href="{{ $repositoryUrl }}">
                        Voir le code sur GitHub <x-icon name="arrow" class="size-4"/>
                    </a>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
