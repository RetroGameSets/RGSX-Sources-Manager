# RGSX Sources Manager

[English version](./README.en.md)

Téléchargements rapides:
- Windows (portable): https://github.com/RetroGameSets/rgsx-sources-manager/releases/latest/download/RGSX_Sources_Manager_Windows.zip
- PHP (hébergé, minimal): https://github.com/RetroGameSets/rgsx-sources-manager/releases/latest/download/RGSX_Sources_Manager_PHP.zip

RGSX Sources Manager est l’interface d’administration du catalogue MariaDB central pour:
- Modifier directement les plateformes, images et jeux du catalogue partagé
- Scraper des sources (archive.org, 1fichier, myrient, edgeemu.net, Vimm…) puis ajouter ou mettre à jour les résultats
- Conserver les données existantes pendant les fusions, avec déduplication par nom

Ce dépôt contient une version fonctionnelle qui peut s’utiliser:
- En local sous Windows (avec PHP portable inclus)
- Sur un serveur web disposant de PHP

---

## 1) Utilisation locale Windows

Prérequis: Windows 10/11. Aucun PHP à installer (inclus dans `data/php_local_server`).
Le poste doit pouvoir joindre MariaDB; pour un accès distant, l’hébergeur doit autoriser l’adresse IP du poste.

Étapes:
1. Télécharger et extraire l’archive du projet dans un dossier (sans espace si possible).
2. Ouvrir le dossier et exécuter `RGSX_Manager.bat`.
3. Le script démarre un petit serveur PHP intégré sur `127.0.0.1:8088` et ouvre votre navigateur à l’URL:
  - `http://127.0.0.1:8088/data/rgsx_database_manager.php`
4. Saisissez l’hôte, le port, la base MariaDB, l’utilisateur et son mot de passe, puis utilisez **Plateformes**, **Jeux** et **Scraper**. Cochez l’option de mémorisation pour enregistrer la connexion dans un cookie chiffré de ce navigateur.

Notes:
- Si un pare-feu demande une autorisation pour PHP, acceptez l’accès local.
- Le serveur intégré s’arrête lorsque vous fermez la fenêtre qui s’est ouverte ("PHP Server").

---

## 2) Utilisation sur un serveur hébergé avec PHP pour un accès depuis n'importe quel système pc ou mobile.

Prérequis: Serveur Web (Apache/Nginx) + PHP 8.1 ou plus récent.

Déploiement minimal:
1. Copier sur le serveur `data/rgsx_database_manager.php`, `data/rgsx_catalog_db.php`, `data/rgsx_sources_manager.php`, `data/rgsx_catalog_api.php` et `data/assets`.
2. Déployer les fichiers côte à côte dans un répertoire `/rgsx/` servi en HTTPS.
3. Accéder dans un navigateur à l’URL (exemple):
  - `https://votre-domaine.tld/rgsx/rgsx_database_manager.php`

Remarques:
- Le chemin exact dépend de la structure de vos hôtes virtuels. Le fichier doit être accessible via HTTPS.
- Pour un déploiement complet (avec portable PHP côté serveur), préférez une installation classique PHP/Apache.

---

## Synchronisation du catalogue RGSX

### Manager Windows local connecté à MariaDB

Lancez `RGSX_Manager.bat`, puis renseignez l’hôte, le port, le nom de base, l’utilisateur et le mot de passe MariaDB dans la page de connexion. L’option de mémorisation conserve ces paramètres dans un cookie chiffré et protégé contre l’accès JavaScript pendant 30 jours ; sans cette option, la session de connexion reste limitée à la session du navigateur. Utilisez **Se déconnecter / changer de base** pour fermer la connexion et saisir un autre serveur.

Le Manager n’intègre aucun hôte, nom de base ni utilisateur par défaut. Des variables d’environnement `RGSX_MYSQL_HOST`, `RGSX_MYSQL_PORT`, `RGSX_MYSQL_DATABASE` et `RGSX_MYSQL_USER` peuvent préremplir le formulaire. Le compte doit disposer des droits d’écriture et de création des triggers ; le compte de lecture seule de l’API ne convient pas au Manager.

Dans **Plateformes**, les commandes de visibilité masquent temporairement une source entière ou une plateforme du catalogue distribué à RGSX. Elles ne suppriment aucune donnée MariaDB ; à la prochaine synchronisation, le client retire uniquement les plateformes masquées et leurs données. Lorsqu’une plateforme est réactivée, il télécharge les données de ces seules plateformes.

Pour préremplir les paramètres dans un déploiement serveur, configurez le processus PHP avec vos propres valeurs:

```sh
export RGSX_MYSQL_HOST=db.example.tld
export RGSX_MYSQL_PORT=3306
export RGSX_MYSQL_DATABASE=your_database
export RGSX_MYSQL_USER=your_manager_user
export RGSX_MYSQL_SSL_CA=/etc/ssl/certs/mysql-ca.pem
```

Le mot de passe est demandé à la connexion et n’est prérempli que si l’option de mémorisation a été cochée. Le cookie est chiffré avec une clé conservée hors de la racine Web ; configurez `RGSX_MANAGER_REMEMBER_KEY` pour garder une clé stable sur un serveur dont le dossier temporaire est nettoyé. Le serveur PHP doit avoir `pdo_mysql` et `openssl` activés. Les formulaires POST du Manager sont protégés par un jeton CSRF de session.

### API de catalogue pour RGSX

Les clients RGSX ne se connectent jamais à MariaDB et ne reçoivent aucun identifiant SQL. Ils appellent `https://votre-domaine.tld/rgsx/rgsx_catalog_api.php` en HTTPS. L’API n’accepte que `GET` et trois actions autorisées (`manifest`, `snapshot`, `changes`); les requêtes utilisent des paramètres préparés. Le catalogue est public en lecture seule et paginé, avec une limitation de débit; aucun jeton embarqué dans l’application n’est considéré comme un secret.

Créez un compte dédié sur le serveur, avec `SELECT` uniquement sur les tables nécessaires (`platforms`, `games`, `platform_assets`, `catalog_changes`, `catalog_visibility`, `schema_meta`). Configurez ses identifiants dans l’environnement PHP ou un fichier secret hors racine web :

```sh
export RGSX_CATALOG_API_ENV_FILE=/srv/rgsx/secrets/catalog-api.env
```

Le fichier `/srv/rgsx/secrets/catalog-api.env` doit être hors de la racine web, lisible uniquement par PHP-FPM, et contenir `RGSX_CATALOG_DB_HOST`, `RGSX_CATALOG_DB_PORT`, `RGSX_CATALOG_DB_NAME`, `RGSX_CATALOG_DB_USER`, `RGSX_CATALOG_DB_PASSWORD` et éventuellement `RGSX_CATALOG_DB_SSL_CA`. Le compte SQL doit être dédié et avoir `SELECT` uniquement sur les tables catalogue; limite son hôte à `localhost` si possible. Retire toutes les IP des clients RGSX de la liste Remote MySQL cPanel. Si le Manager Windows se connecte directement à MariaDB, autorise uniquement l’IP fixe de ce poste de confiance; si le Manager est hébergé sur le serveur de base, utilise `localhost` et désactive l’accès distant. Le compte administrateur du Manager est séparé.

Le Manager est le seul composant qui écrit le catalogue central. RGSX synchronise un snapshot paginé au premier lancement, puis uniquement les changements; il conserve une copie SQLite locale pour accélérer la navigation. La synchronisation ne remplace jamais l’historique, les jeux téléchargés ni les caches utilisateur.

<!-- Documentation historique conservée ci-dessous pour référence des anciens flux. -->

## Ancienne interface et flux historiques

L’application se présente en 4 onglets

### 1) Scraper
- Importer un ZIP de data (fichier ou URL):
  - Bouton "Charger" pour envoyer un fichier ZIP contenant `systems_list.json`, `games/*.json`, `images/*`.
  - Bouton "Utiliser base RGSX officielle" remplit l’URL avec la source RGSX officielle pour avoir une base complète à modifier.
- Zone "URLs ou HTML":
  - Collez une ou plusieurs URLs à scrapper (archive.org, 1fichier, myrient, pages HTML quelconques).
  - Collez directement l'URL d'un fichier `.torrent` : le contenu est analysé immédiatement et les jeux listés avec leurs liens téléchargeables.
  - Si l'URL pointe vers un **feed JSON** (tableau `[[nom, url, taille], …]`), les entrées sont importées directement sans parseur HTML (ex: `rgsx_feed.php` déployé sur un serveur).
  - Les liens `.torrent` trouvés sur une page HTML sont également expandés automatiquement à la volée.
  - Indiquez le mot de passe si un dossier 1fichier est protégé.
  - Cliquez sur "Scraper".
- Fichier torrent (upload direct):
  - Importez un fichier `.torrent` local via le champ dédié ; le contenu est analysé et les jeux listés comme source torrent.
- Résultats:
  - Chaque source détectée affiche son nombre de fichiers et la taille totale.
  - **Filtre d'extensions** : les extensions détectées (ex: `wsquashfs`, `zip`) s'affichent sous forme de cases à cocher. Décochez les extensions à exclure avant d'attacher.
  - Pour attacher le résultat à une plateforme: choisissez une plateforme (liste) et cliquez "Attacher à la plateforme".
  - Vous pouvez aussi "Ajouter tous" (tous les résultats) sur une plateforme choisie.

### 2) Plateformes (systems_list.json)
- Ajouter une plateforme:
  - Sélectionnez un nom dans la liste, renseignez `platform_name` et `folder`.
  - Optionnel: image (`platform_image_file`). Si aucune image, l’outil propose `<platform_name>.png` par défaut.
- Liste paginée:
  - Sélecteur "Par page": 10 / 20 / 25 / 50 / 100 (20 par défaut).
  - Navigation "Préc/Next".
- Modifier/Supprimer:
  - Bouton "Modifier" ouvre une ligne d’édition inline pour changer nom/dossier et image.
  - Bouton "Voir" affiche un aperçu si l’image est connue dans `images/`.

### 3) Jeux (games/*.json)
- Importer des plateformes de jeux:
  - Uploader un ou plusieurs fichiers `games/Platform.json` (ou un ZIP, recommandé pour >20).
- Ajouter une ligne (manuellement):
  - Choisir le fichier plateforme, puis renseigner Nom, URL, Taille.
- Affichage par plateforme (accordion):
  - Affiche le nombre de lignes et la table des jeux.
  - Chaque ligne comporte Modifier/Supprimer. Les noms et URLs longs sont tronqués (ellipsis, URL au milieu) pour lisibilité.
- Pagination côté systèmes (onglet 2) indépendante du contenu des jeux.

### 4) Package ZIP
- Actions:
  - "Créer le ZIP": génère `games.zip` contenant `systems_list.json` (racine), `images/` et `games/`.
  - "Télécharger systems_list.json": pour récupérer uniquement ce fichier.

---

## Dépannage

- Le serveur intégré ne démarre pas:
  - Vérifiez que `data/php_local_server/php.exe` existe.
  - Exécutez `RGSX_Manager.bat` depuis un dossier avec des droits suffisants.
- Les listes Batocera ne se remplissent pas:
  - Vérifiez `data/assets/batocera_systems.json` et la console réseau du navigateur.
- Le ZIP généré est vide ou incomplet:
  - Assurez-vous d’avoir ajouté des systèmes et des jeux dans la session avant de cliquer sur "Créer le ZIP".

---

## Licence

Ce projet contient des composants tiers (PHP portable) sous leur(s) licence(s) respective(s).
