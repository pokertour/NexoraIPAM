# NexoraIPAM

NexoraIPAM est une application open source de gestion d’adresses IP, construite avec Laravel 13, Livewire 4 et Tailwind CSS 4. Cette première version gère les sous-réseaux IPv4 et IPv6, leurs adresses et les VLAN associés.

## Installation locale

Prérequis : PHP 8.3 ou supérieur, Composer, Node.js 20.19+ ou 22.12+, npm et PDO SQLite. Activez l’extension PHP LDAP uniquement pour utiliser l’annuaire.

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
php artisan nexora:install
php artisan serve
```

Connectez-vous sur [localhost:8000](http://localhost:8000) avec `admin@example.com` et le mot de passe temporaire affiché par la commande. Le premier accès impose de le changer. Relancer la commande conserve le compte et son mot de passe existants. En production, configurez `APP_ENV=production`, renseignez l’URL HTTPS dans `APP_URL` et pointez le serveur web vers `public/`. Conservez la clé `APP_KEY` avec les sauvegardes. Elle permet de déchiffrer les paramètres enregistrés.

## Comptes et paramètres

| Rôle | Accès |
| --- | --- |
| Administrateur | Inventaire, utilisateurs et paramètres |
| Opérateur | Consultation et modification de l’inventaire |
| Lecteur | Consultation de l’inventaire |

- **Interne** — créez le compte dans **Utilisateurs** et transmettez le mot de passe temporaire à la personne concernée. Le compte d’installation garde un accès local de secours si cette méthode est désactivée.
- **LDAP** — renseignez l’URL LDAPS, la base DN, le groupe AD autorisé (DN) et le compte de recherche dans **Paramètres**. Installez l’autorité de certification de l’annuaire dans le magasin de confiance du serveur si nécessaire. Les membres directs ou imbriqués de ce groupe se connectent avec leur adresse `mail`. Leur compte Lecteur est créé au premier accès autorisé.
- **OpenID Connect** — configurez un client confidentiel avec découverte HTTPS, flux Authorization Code, PKCE S256 et signature RSA. Enregistrez l’URL de retour affichée dans **Paramètres** chez le fournisseur, puis attribuez l’accès à l’application dans le fournisseur SSO. NexoraIPAM crée un compte Lecteur au premier accès autorisé et l’associe au couple émetteur et sujet.
- **SMTP** — configurez l’expéditeur, le serveur et ses identifiants dans **Paramètres**, puis utilisez **Envoyer un test**. TLS implicite et STARTTLS sont disponibles.

Les réglages d’authentification et SMTP sont chiffrés dans la base. SQLite est utilisé par défaut. Les paramètres nécessaires à une connexion vers une autre base relèvent de la configuration de démarrage de Laravel.

## Emplacements et découverte réseau

Créez une agence, puis ses bureaux et leurs étages dans **Emplacements**. Rattachez les sous-réseaux et les VLAN au niveau voulu. Une adresse IP hérite de l’emplacement de son sous-réseau. Les entrées historiques restent accessibles et peuvent être rattachées depuis l’inventaire.

Pour activer l’exécution des découvertes, installez `ping` (iputils) et `getent` sur l’hôte Linux du worker, puis lancez la file dédiée :

```sh
php artisan queue:work database --queue=discovery
```

Un administrateur lance ensuite la découverte depuis la liste **Sous-réseaux**. Le worker teste jusqu’à 256 cibles. Pour une plage plus grande, il ne teste que les premières adresses déjà inventoriées, jusqu’à cette limite. Il résout les noms des hôtes répondants avec le résolveur système, sans écraser les noms manuels. Le worker doit pouvoir joindre la plage ciblée depuis son propre réseau. Une absence de réponse ICMP ne suffit pas à conclure qu’une adresse est libre.

Pour la découverte automatique, réglez l’intervalle en minutes dans la fiche **Emplacements** avec un compte administrateur. Le réglage concerne les sous-réseaux directement rattachés. Un champ vide désactive la planification. En complément du worker, lancez le planificateur en développement :

```sh
php artisan schedule:work
```

En production, exécutez `php artisan schedule:run` chaque minute et gardez le worker de la file `discovery` actif. Pour relever les MAC accessibles par ARP/NDP, installez `iproute2` sur l’hôte du worker. Pour la recherche de fabricant dans **Outils**, installez le paquet `ieee-data` ou placez ses CSV IEEE dans `/usr/share/ieee-data`. La recherche se fait localement.

Dans **Sous-réseaux**, cliquez sur un nom pour ouvrir sa carte d’adresses et les plages libres. Le lien **Découpage** ouvre les six longueurs de préfixe suivantes lorsqu’elles existent. Les sous-réseaux créés depuis cette carte sont délégués et gèrent leurs adresses séparément du parent.

## Docker

La version publiée est disponible dans GHCR sous le nom `ghcr.io/pokertour/nexoraipam:vX.X.X`. Le conteneur écoute sur le port 8080. Fournissez `APP_KEY` et `APP_URL` avec un fichier d’environnement de déploiement. L’image contient déjà son `APP_VERSION`. Réutilisez le même fichier d’environnement et le même volume lors des mises à jour.

```sh
image=ghcr.io/pokertour/nexoraipam:vX.X.X
docker volume create nexora-data
docker run --rm --env-file nexora.env -v nexora-data:/var/lib/nexora "$image" php artisan migrate --force
docker run --rm --env-file nexora.env -v nexora-data:/var/lib/nexora "$image" php artisan nexora:install
docker run -d --name nexora --env-file nexora.env -v nexora-data:/var/lib/nexora -p 8080:8080 "$image"
```

Pour générer la valeur initiale de `APP_KEY`, utilisez `docker run --rm "$image" php artisan key:generate --show`. Pour la découverte réseau, lancez des conteneurs séparés avec le même volume et le même fichier d’environnement. Utilisez respectivement `php artisan queue:work database --queue=discovery` et `php artisan schedule:work` comme commande. Le worker doit pouvoir joindre les réseaux ciblés.

Dans **Actions → Tests et publication → Run workflow**, saisissez la version `vX.X.X`. Le workflow lance les tests, construit et démarre l’image, pousse les tags de version et `latest` dans GHCR, puis publie la release GitHub. Les pushs et pull requests exécutent les vérifications.

La page **À propos** vérifie la dernière release GitHub. Pour un dépôt privé, fournissez éventuellement `GITHUB_RELEASE_TOKEN` avec un accès en lecture au dépôt.

## Tests

```sh
vendor/bin/phpunit
npm run build
```

## Licence

[GNU AGPL v3 uniquement](LICENSE). La licence autorise la redistribution sous ses conditions de copyleft. Une version modifiée proposée aux utilisateurs sur un réseau doit leur donner accès à son code source correspondant.
