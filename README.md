# RGSX Sources Manager

[English version](./README.en.md)

RGSX Sources Manager est un outil tout-en-un pour:
- Scraper des listes de jeux depuis des pages/URLs (archive.org, 1fichier, myrient…)
- Éditer le fichier `systems_list.json` et gérer vos plateformes
- Éditer les listes de jeux par plateforme (`games/*.json`)
- Générer un package ZIP prêt à l’emploi (systems_list.json, images/, games/)

Ce dépôt contient une version fonctionnelle qui peut s’utiliser:
- En local sous Windows (avec PHP portable inclus)
- Sur un serveur web disposant de PHP

---

## 1) Utilisation locale Windows (recommandée)

Prérequis: Windows 10/11. Aucun PHP à installer (inclus dans `data/php_local_server`).

Étapes:
1. Télécharger et extraire l’archive du projet dans un dossier (sans espace si possible).
2. Ouvrir le dossier et exécuter `RGSX_Manager.bat`.
3. Le script démarre un petit serveur PHP intégré sur `127.0.0.1:8088` et ouvre votre navigateur à l’URL:
   - `http://127.0.0.1:8088/data/rgsx_sources_manager.php`
4. L’interface web s’affiche. Vous pouvez alors utiliser les 4 onglets: Scraper, Plateformes, Jeux, Package ZIP.

Notes:
- Si un pare-feu demande une autorisation pour PHP, acceptez l’accès local.
- Le serveur intégré s’arrête lorsque vous fermez la fenêtre qui s’est ouverte ("PHP Server").

---

## 2) Utilisation sur un serveur hébergé avec PHP pour un accès depuis n'importe quel système pc ou mobile.

Prérequis: Serveur Web (Apache/Nginx) + PHP 8.x (ou 7.4+).

Déploiement minimal:
1. Copier sur le serveur le fichier `data/rgsx_sources_manager.php` et le dossier `data/assets` (contenant `lang/`, `batocera_systems.json`, etc.).
2. Définir le DocumentRoot pour qu’il serve ces fichiers, ou placer-les sous un chemin accessible publiquement.
3. Accéder dans un navigateur à l’URL (exemple):
   - `https://votre-domaine.tld/data/rgsx_sources_manager.php`

Remarques:
- Le chemin exact dépend de la structure de vos hôtes virtuels. Le fichier doit être accessible via HTTP.
- Pour un déploiement complet (avec portable PHP côté serveur), préférez une installation classique PHP/Apache.

---

## Découverte de l’interface et flux d’utilisation

L’application se présente en 4 onglets

### 1) Scraper
- Importer un ZIP de data (fichier ou URL):
  - Bouton "Charger" pour envoyer un fichier ZIP contenant `systems_list.json`, `games/*.json`, `images/*`.
  - Bouton "Utiliser base RGSX officielle" remplit l’URL avec la source RGSX officielle pour avoir une base complète à modifier.
- Zone "URLs ou HTML":
  - Collez une ou plusieurs URLs à scrapper (archive.org, 1fichier, myrient).
  - Indiquez le mot de passe si un dossier 1fichier est protégé.
  - Cliquez sur "Scraper".
- Résultats:
  - Chaque source détectée affiche son nombre de fichiers.
  - Pour attacher le resultat à une plateforme: choisissez une plateforme (liste) et cliquez "Attacher à la plateforme".
  - Vous pouvez aussi "Ajouter tous" (tous les resultats) sur une plateforme choisie.

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
