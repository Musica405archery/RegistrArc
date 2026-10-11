# RegistrArc

**Le module de greffe pour I@nseo** : suivi des paiements des engagements, factures, remises de chèques en banque et encaissement sur téléphone pendant une compétition de tir à l'arc.

![Liste des engagements](docs/images/liste-engagements.png)

RegistrArc s'installe dans I@nseo comme module personnalisé. Il lit les inscriptions du tournoi ouvert (archers, catégories, départs, cibles) et calcule ce que chacun doit payer selon les tarifs du club. Ses propres données (paiements, paramètres, chèques groupés) sont enregistrées dans des fichiers séparés : **il ne modifie jamais la base de données I@nseo**.

## Sommaire

1. [Fonctionnalités](#fonctionnalités)
2. [Installation](#installation)
3. [Avant la compétition : les paramètres](#avant-la-compétition--les-paramètres)
4. [Pendant la compétition : la liste des engagements](#pendant-la-compétition--la-liste-des-engagements)
5. [Le mode Kiosk sur téléphone](#le-mode-kiosk-sur-téléphone)
6. [Factures](#factures)
7. [Remise de chèques en banque](#remise-de-chèques-en-banque)
8. [Mise à jour du module](#mise-à-jour-du-module)
9. [Données, sauvegardes et sécurité](#données-sauvegardes-et-sécurité)
10. [Questions fréquentes](#questions-fréquentes)
11. [Pour les développeurs](#pour-les-développeurs)

## Fonctionnalités

- **Liste des engagements** avec filtres, tri, recherche et montant dû pour chaque départ.
- **Encaissement** archer par archer ou en groupe, avec le mode de paiement (espèces, chèque, virement, gratuit, ou vos propres modes).
- **Chèque groupé** : un seul chèque pour plusieurs engagements (une famille, un club…).
- **Mode Kiosk** : un écran simplifié pour encaisser depuis un téléphone ou une tablette.
- **Tarifs** par type de club (organisateur ou extérieur), par âge (jeunes ou adultes) et par nombre de départs, plus des **règles avancées** pour les cas particuliers.
- **Factures** individuelles ou groupées, prêtes à imprimer ou à enregistrer en PDF.
- **Bordereau de remise de chèques** pour le dépôt en banque.
- **Mise à jour du module** en un clic depuis GitHub.

## Installation

1. Copiez le dossier du module dans votre installation I@nseo, sous `Modules/Custom/RegistrArc/`.
2. Vérifiez que le serveur web peut écrire dans ce dossier : le module crée lui-même un sous-dossier `data/` pour ses fichiers. Sous Linux, si besoin :
   ```bash
   mkdir -p Modules/Custom/RegistrArc/data
   chmod 775 Modules/Custom/RegistrArc/data
   ```
3. Ouvrez un tournoi dans I@nseo : le module apparaît dans le menu **Participants → Greffe**.

**Droits nécessaires :**

| Pour… | Il faut… |
|---|---|
| Voir le menu Greffe | les droits en écriture sur les participants (placement sur cible) |
| Consulter les pages | les droits en lecture sur les participants |
| Encaisser, modifier les paramètres, importer | les droits en écriture sur les participants |
| Installer une mise à jour | être administrateur I@nseo |

Un compte en lecture seule voit toutes les pages, mais les boutons de modification sont masqués et toute tentative d'écriture est refusée par le serveur.

## Avant la compétition : les paramètres

Depuis la liste des engagements, cliquez sur **⚙ Paramétrer tarifs & paiements**. Les paramètres sont propres à chaque tournoi.

### Classes d'âge

![Classes d'âge](docs/images/parametres-classes-age.png)

Toutes les catégories présentes dans le tournoi sont réparties en deux colonnes : **Jeunes** à gauche, **Adultes** à droite. Faites glisser une catégorie d'une colonne à l'autre, ou cliquez sur sa flèche.

**Par défaut**, toute catégorie en dessous de U21 (U11, U13, U15, U18…) est classée Jeunes. U21 et les catégories seniors sont classées Adultes. Une catégorie que vous déplacez reste où vous l'avez mise.

### Tarifs d'inscription et règles avancées

![Tarifs et règles avancées](docs/images/parametres-tarifs-regles.png)

Pour chaque type de club et chaque classe d'âge, vous saisissez :
- le prix du **1er départ** ;
- le **supplément** payé pour **chaque** départ suivant.

Pour un archer inscrit à *n* départs : **total = 1er départ + (n − 1) × supplément**.

| Exemple (valeurs par défaut) | 1 départ | 2 départs | 3 départs |
|---|---|---|---|
| Jeune, club extérieur (8 € + 6 €) | 8 € | 14 € | 20 € |
| Adulte, club extérieur (10 € + 8 €) | 10 € | 18 € | 26 € |
| Adulte, club organisateur (5 € + 5 €) | 5 € | 10 € | 15 € |

Le **club organisateur** est celui déclaré comme organisateur dans les informations du tournoi I@nseo. Les départs d'un même archer sont reconnus grâce à sa licence ; ils sont numérotés n° 1, n° 2… dans l'ordre des départs.

**Les règles avancées** fixent un prix pour des cas particuliers (tarif découverte, finale, archers d'une région…). Une règle a :

- un **nom**, affiché dans la liste et sur la facture ;
- une **portée**, c'est-à-dire ce que la règle examine ;
- une **valeur** à comparer ;
- un **prix fixe** ;
- une case **Actif** pour la désactiver sans la supprimer.

| Portée | La règle s'applique si… | Exemple de valeur |
|---|---|---|
| Catégorie | la catégorie contient la valeur | `U11`, `CL`, `COS1F` |
| Région | les 2 premiers chiffres du code club correspondent | `10` |
| Département | les 3e et 4e chiffres du code club correspondent | `69` |
| Qualification individuelle / équipe | l'archer y est inscrit (`OUI`) ou non (`NON`) | `OUI` |
| Finale individuelle / équipe, double mixte | idem ; proposées seulement si le tournoi comporte ces épreuves | `OUI` |

> ⚠️ Quand une règle s'applique à un engagement, son prix **remplace** le tarif de base. Si plusieurs règles s'appliquent au même engagement, leurs prix **s'additionnent** et leurs noms sont affichés ensemble (« Règle A + Règle B »).

### Modes de paiement

![Modes de paiement](docs/images/parametres-modes-paiement.png)

Les modes Espèces (`ESP`), Chèque (`CHQ`), Virement (`VIR`) et Gratuit (`GRA`) sont proposés par défaut ; vous pouvez en ajouter (par exemple `CB` pour la carte bancaire) ou modifier leurs libellés. Le mode **Gratuit** est protégé : un engagement réglé en `GRA` compte pour 0 €. Le mode **Chèque** est celui qui alimente la remise de chèques.

N'oubliez pas de cliquer sur **Enregistrer les paramètres** en bas de la page.

### Exporter ou importer les paramètres

En haut de la page Paramètres, **Exporter le JSON** télécharge toute la configuration (classes d'âge, tarifs, règles, modes de paiement). **Importer** la recharge, par exemple pour reprendre les tarifs de l'an dernier ou les copier sur un autre ordinateur. Avant de remplacer la configuration, le module vous avertit si le fichier vient d'un autre tournoi ou s'il est plus ancien que la configuration actuelle.

## Pendant la compétition : la liste des engagements

C'est l'écran principal (capture en haut de cette page). Chaque ligne correspond à un départ d'un archer : numéro d'engagement, licence, nom, club, catégorie, départ, cible, montant, statut et paiement.

- **Filtres** : club, catégorie, départ, statut de paiement et recherche libre (licence, nom, prénom, club). Ils sont mémorisés séparément pour chaque tournoi ; **Réinitialiser** les efface.
- **Tri** : cliquez sur l'en-tête d'une colonne ; un second clic inverse l'ordre.
- **Indications** : « Archer non affecté » signale un archer sans cible ; un badge violet rappelle qu'une règle avancée s'applique.

**Encaisser un archer** : sur sa ligne, choisissez le mode de paiement puis **Valider**. **Annuler** remet l'engagement à « Non payé ».

**Encaisser plusieurs engagements** : cochez les lignes, choisissez le mode de paiement dans « Actions sur les engagements sélectionnés », puis **Valider paiement** (ou **Retirer paiement**). Si vous validez **au moins 2 engagements par chèque**, le module propose de créer un **chèque groupé** : ils apparaîtront ensemble sur la remise de chèques.

**Autres boutons :**

- **Facture** (sur une ligne) ou **Facture groupée** (lignes cochées) : voir [Factures](#factures).
- **Remise de chèque** : voir [Remise de chèques en banque](#remise-de-chèques-en-banque).
- **Liste Greffe** : imprime la liste telle qu'elle est affichée (filtres compris).
- **Exporter / Importer inscriptions** : transfère les états de paiement d'une installation RegistrArc à une autre, par exemple d'un ordinateur de pré-inscription vers celui du greffe. Les archers sont retrouvés par licence, catégorie, départ et cible ; l'import ne crée ni ne supprime aucune inscription I@nseo, il applique seulement les paiements.

## Le mode Kiosk sur téléphone

Le bouton **Mode Kiosk** ouvre un écran pensé pour encaisser debout, sur un téléphone ou une tablette connecté au même réseau que l'ordinateur I@nseo.

| Liste | Encaissement |
|---|---|
| ![Mode Kiosk](docs/images/kiosk.png) | ![Encaissement dans le mode Kiosk](docs/images/kiosk-encaissement.png) |

1. Cherchez l'archer (nom, licence ou club) ou filtrez par départ. Par défaut, seuls les archers **à payer** sont affichés ; cochez **Afficher les payés** pour voir tout le monde.
2. Touchez une ou plusieurs lignes : le total s'affiche en bas.
3. Choisissez le mode de paiement, cochez **Reçu demandé** si l'archer veut un reçu, puis **Encaisser**.

Chaque ligne indique la cible, le départ, la catégorie, le club, le montant et le statut. Le résumé en haut donne le nombre d'engagements à encaisser, le montant restant dû, le nombre d'engagements payés et le nombre de reçus demandés. Les filtres sont mémorisés sur l'appareil, pour chaque tournoi.

La case **Reçu demandé** n'existe que dans le mode Kiosk ; la liste des engagements affiche ensuite l'indication « Reçu » sur les lignes concernées.

## Factures

![Facture groupée](docs/images/facture.png)

- **Facture individuelle** : bouton **Facture** sur la ligne de l'engagement.
- **Facture groupée** : cochez au moins 2 engagements, puis **Facture groupée**.

La facture s'ouvre dans un nouvel onglet. Elle reprend le concours (nom, organisateur, lieu, dates, logos du tournoi s'ils sont définis dans I@nseo), les archers facturés regroupés par club, le détail de chaque engagement (tarif standard ou règle avancée, statut, mode de paiement) et le total. Le bouton **Imprimer / Enregistrer en PDF** utilise l'impression du navigateur.

Le numéro de facture est construit à partir du tournoi, de la date, de l'heure et des engagements : il n'est **pas conservé**, une nouvelle génération donne un nouveau numéro.

## Remise de chèques en banque

![Remise de chèques](docs/images/remise-cheques.png)

Le bouton **Remise de chèque** prépare le bordereau à joindre au dépôt en banque :

- **sans sélection**, il présente tous les chèques groupés, puis tous les autres paiements par chèque ;
- **avec des lignes cochées**, il ne présente que les chèques de ces engagements.

En haut du bordereau : le nombre de chèques, le nombre d'engagements et le total de la remise. Chaque chèque a des zones à compléter à la main (numéro de chèque, banque émettrice) et le bas de page prévoit « Préparé par » et « Déposé le ».

Les chèques groupés restent à jour automatiquement : si le paiement d'un engagement est annulé ou passé à un autre mode, il quitte son groupe, et un groupe réduit à un seul engagement redevient un chèque individuel. Un engagement ne peut appartenir qu'à un seul chèque groupé.

## Mise à jour du module

Quand une nouvelle version est publiée, un bouton vert **⬆ Mise à jour** apparaît dans la liste des engagements, à gauche de **Mode Kiosk**. Il n'est visible que pour un administrateur I@nseo.

![Bouton de mise à jour](docs/images/bouton-mise-a-jour.png)

Il ouvre la page de mise à jour : version installée, version disponible et nouveautés. **Installer la version…** fait le reste.

![Page de mise à jour](docs/images/mise-a-jour.png)

- Tous les fichiers sont téléchargés avant d'en remplacer un seul : si un téléchargement échoue, rien n'est modifié.
- Les anciens fichiers sont sauvegardés dans `data/backup_<version>_<date>/`.
- Vos données (`data/`) et votre `module.json` ne sont jamais touchés.
- Sans internet, le module fonctionne normalement ; le bouton n'apparaît simplement pas. La vérification est faite au plus toutes les 6 heures (30 minutes après un échec) ; **Vérifier à nouveau** force un contrôle immédiat.

La première installation se fait à la main ; les suivantes peuvent passer par ce bouton.

## Données, sauvegardes et sécurité

**Où sont les données ?** Dans `Modules/Custom/RegistrArc/data/`, un fichier par tournoi et par type :

| Fichier | Contenu |
|---|---|
| `payments_<n° tournoi>.json` | paiements (statut, mode, reçu demandé, date) |
| `settings_<n° tournoi>.json` | classes d'âge, tarifs, règles, modes de paiement |
| `cheque_groups_<n° tournoi>.json` | chèques groupés |

Pour sauvegarder le greffe d'un tournoi, copiez ces fichiers. Chaque fichier a aussi une copie `.bak` de sa version précédente.

**Fiabilité.** Plusieurs postes peuvent encaisser en même temps (ordinateur du greffe et téléphones en mode Kiosk) : chaque enregistrement se fait sous verrou, sans risque d'écraser le paiement saisi par un autre poste. Si un fichier devient illisible, le module refuse d'écrire par-dessus et indique où se trouve la copie `.bak`.

**Plusieurs tournois.** Les données de chaque tournoi sont séparées. Attention aux onglets : dans un même navigateur, ouvrir un autre tournoi dans I@nseo change le tournoi actif de tous les onglets. Si vous validez un paiement depuis un onglet resté sur l'ancien tournoi, RegistrArc refuse l'enregistrement et vous demande de recharger la page.

**Protection.** Le dossier `data/` est protégé contre l'accès direct depuis le web (fichier `.htaccess`, pour Apache). Les formulaires sont protégés contre les envois forgés depuis un autre site.

## Questions fréquentes

**Le message « le dossier de stockage JSON n'est pas accessible en écriture » s'affiche.**
Le serveur web ne peut pas écrire dans `Modules/Custom/RegistrArc/data/`. Créez le dossier et donnez-lui les droits d'écriture (voir [Installation](#installation)).

**Un archer a le mauvais tarif.**
Vérifiez dans les Paramètres que sa catégorie est dans la bonne colonne (Jeunes ou Adultes), que son club est bien le club organisateur s'il doit en bénéficier, et qu'aucune règle avancée active ne s'applique à lui.

**« Formulaire expiré ou invalide ».**
La page était ouverte depuis longtemps ou la session I@nseo a changé. Rechargez la page et recommencez : rien n'a été enregistré.

**« Le tournoi ouvert a changé depuis l'affichage de cette page ».**
Un autre tournoi a été ouvert dans I@nseo entre-temps. Rechargez la page : elle affichera le tournoi actuellement ouvert.

**Le bouton Mise à jour n'apparaît jamais.**
Il n'est visible que pour un administrateur I@nseo, quand le serveur a accès à internet et qu'une version plus récente est publiée. La page `update.php` du module indique la version disponible ou la raison de l'échec.

## Pour les développeurs

| Fichier | Rôle |
|---|---|
| `index.php` | liste des engagements, encaissement, import / export des inscriptions |
| `kiosk.php` | mode Kiosk |
| `config_tarifs.php` | paramètres du tournoi |
| `invoice.php` | factures |
| `bankchequedeposite.php` | bordereau de remise de chèques |
| `update.php` | page de mise à jour |
| `menu.php` | entrée du menu I@nseo |
| `lib/registrarc.inc.php` | fonctions communes : paramètres, tarification, paiements, chèques groupés, sécurité des formulaires |
| `lib/registrarc_storage.inc.php` | stockage JSON : écriture atomique, verrous, copies `.bak` |
| `lib/registrarc_update.inc.php` | mise à jour depuis GitHub |
| `module.json` | dépôt et branche d'origine des mises à jour (configuration locale, jamais écrasée) |
| `version.json` | version du module et liste des fichiers distribués |

Le module ne modifie jamais la structure des tables I@nseo : il se contente de les lire.

**Publier une nouvelle version :** sur la branche `main`, augmentez `version` dans `version.json` (format `1.2.3`), mettez à jour `date` et `notes`, et ajoutez dans `files` tout nouveau fichier du module. Les installations la proposent au contrôle suivant.

---

*Module développé pour la gestion des compétitions de tir à l'arc avec I@nseo.*

Made with <3 avec Open WebUI
