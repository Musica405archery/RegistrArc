# RegistrArc : informations techniques

Pour la personne qui installe, maintient ou fait évoluer le module. Le mode d'emploi pour les bénévoles est dans le [README](../README.md).

## Installation

1. Copier le module dans `Modules/Custom/RegistrArc/` de l'installation I@nseo.
2. Le serveur web doit pouvoir écrire dans ce dossier : le module y crée `data/`. Sous Linux, si besoin :
   ```bash
   mkdir -p Modules/Custom/RegistrArc/data
   chmod 775 Modules/Custom/RegistrArc/data
   ```
3. Le menu **Participants → Greffe** apparaît pour les comptes ayant les droits en écriture sur les participants (sous-fonction placement sur cible).

| Action | Droit I@nseo vérifié |
|---|---|
| Consulter les pages | `AclParticipants`, lecture |
| Encaisser, modifier les paramètres, importer | `AclParticipants`, écriture (contrôlé côté serveur à chaque envoi) |
| Mettre à jour le module | `AclRoot`, écriture |

## Fichiers du module

| Fichier | Rôle |
|---|---|
| `index.php` | liste des engagements : encaissement, annulation, remboursement, note, import / export des inscriptions ; vue par archer avec le passage au greffe (`?view=archer`) |
| `kiosk.php` | mode Kiosk (`kiosk.php?q=licence` ouvre directement un archer) |
| `config_tarifs.php` | paramètres du tournoi, en 4 parties (catégories, tarifs, paiements, export / import) ; numéro du concours rappelé pour retrouver ses fichiers JSON ; logos des modes de paiement (`registrarc_payment_icon()`, SVG dessinés dans `lib/registrarc.inc.php`) |
| `invoice.php` | factures : aperçu, émission numérotée (`?num=2026-0001`) |
| `bankchequedeposite.php` | bordereau de remise de chèques, remises déposées (`?remise=R1`) |
| `caisse.php` | reçoit les formulaires de la caisse (fond, clôture) et affiche les rapports imprimables : clôture (`?cloture=C1`), caisse en cours (`?imprimer=1`). Sans paramètre, renvoie vers le tableau de bord |
| `dashboard.php` | tableau de bord en deux vues (Greffe, Trésorier ; `?page=tresorier` ouvre la vue Trésorier), chacune en parties repliables `registrarc_dash_section_open()` ; emplacements `data-viz-tiles` et `data-viz-cards` (identifiants des cartes de `assets/js/dashboard.js`) ; caisse, sauvegarde / restauration, clôture du concours |
| `fdm.php` | feuilles de marque (vue FdM de la liste) : `?ids=12,15`, avec les options de `Qualification/PrintScore.php` (complète + totaux progressifs, toutes distances, flèches saisies, drapeau du club, en-tête de page). Un archer : `CreateSessionScorecard()` d'I@nseo (feuille personnelle remplie). Plusieurs : une page par archer dans un seul PDF, avec `QualificationScorecards()` et `ScorePDF::DrawScoreNew()` d'I@nseo, placées comme le fait `CreateSessionScorecard()` (tir sur cible, 1 à 4 distances ; parcours et 3D : une feuille à la fois). Lecture seule |
| `export.php` | téléchargements : `?type=engagements`, `journal` (CSV), `backup` (JSON) |
| `helloasso.php` | import des paiements HelloAsso |
| `qrcodes.php` | étiquettes QR codes à imprimer |
| `clubs.php` | villes des clubs pour la carte du tableau de bord : ville détectée, correction mémorisée par numéro de club |
| `aide.php` | mode d'emploi : le `README.md` affiché dans le module (`aide.php#la-caisse` ouvre directement une partie) ; bouton « Signaler un problème » vers les issues du dépôt de `module.json` |
| `update.php` | page de mise à jour |
| `menu.php` | entrée du menu I@nseo |
| `lib/registrarc_page.inc.php` | début commun des pages : environnement I@nseo, bibliothèques, `registrarc_page_tour_id()` (tournoi ouvert et droit demandé) |
| `lib/registrarc.inc.php` | fonctions communes : paramètres, tarifs de base, chèques groupés, sécurité des formulaires ; charge les autres fichiers de `lib/` |
| `lib/registrarc_engagements.inc.php` | **calcul unique des montants** utilisé par toutes les pages (tarif, règles, plafond, statut) |
| `lib/registrarc_caisse.inc.php` | opérations de paiement journalisées, remises déposées, fond de caisse, clôtures de caisse, concours clôturé |
| `lib/registrarc_caisse_view.inc.php` | affichage de la caisse, partagé par la page Trésorier du tableau de bord et les rapports de `caisse.php` |
| `lib/registrarc_invoices.inc.php` | registre des factures émises |
| `lib/registrarc_dashboard.inc.php` | données du tableau de bord (vues Greffe et Trésorier) : un enregistrement par engagement, sans nom ni licence (`ci` : passé au greffe), et les opérations de caisse ; dessin par `assets/js/dashboard.js` |
| `lib/registrarc_clubs.inc.php` | ville des clubs : détection dans le nom du club parmi les communes de son département, corrections (`data/club_cities.json`), repli au centre du département |
| `lib/data/communes.json` | communes de métropole et de Corse avec leur centre (geo.api.gouv.fr, Licence Ouverte, généré), pour placer les clubs sans service en ligne |
| `lib/registrarc_departements.inc.php` | nom et centre de chaque département (fichier généré à partir des contours IGN Admin Express, Licence Ouverte, via france-geojson) pour la carte des clubs |
| `lib/registrarc_backup.inc.php` | sauvegarde et restauration |
| `lib/registrarc_helloasso.inc.php` | lecture et rapprochement des exports HelloAsso |
| `lib/registrarc_ui.inc.php` | habillage commun des pages d'administration |
| `lib/registrarc_storage.inc.php` | stockage JSON : écriture atomique, verrous, copies `.bak` |
| `lib/registrarc_update.inc.php` | mise à jour depuis GitHub |
| `lib/js/qrcode.js` | génération des QR codes (qrcode-generator, Kazuhiko Arase, licence MIT) |
| `lib/js/marked.min.js` | mise en forme du README dans `aide.php` (marked 12.0.2, Christopher Jeffrey, licence MIT) |
| `lib/js/leaflet/` | carte des clubs du tableau de bord (Leaflet 1.9.4, Vladimir Agafonkin, licence BSD-2-Clause, fichiers identiques au paquet npm). Le fond de carte est chargé en ligne depuis `tile.openstreetmap.org` : l'adresse IP de l'appareil qui affiche la carte est donc vue par OpenStreetMap |
| `README.md`, `docs/images/` | mode d'emploi et ses captures, distribués pour être lus dans le module sans internet |
| `assets/css/`, `assets/js/` | styles et scripts des pages (un fichier par page, `admin.css` pour l'habillage commun), chargés par `registrarc_asset_url()` qui ajoute la date du fichier pour forcer le rechargement après une mise à jour |
| `module.json` | dépôt et branche d'origine des mises à jour (configuration locale, jamais écrasée) |
| `version.json` | version du module, liste des fichiers distribués et leurs empreintes SHA-256 |
| `tests/` | tests automatiques (non distribués) |
| `tools/version_hashes.php` | calcul des empreintes avant publication (non distribué) |

Le module ne modifie jamais la structure des tables I@nseo : il se contente de les lire.

**Règle importante** : tout changement de paiement passe par `registrarc_apply_payment_changes()` (ou ses raccourcis `registrarc_record_payments`, `registrarc_cancel_payments`, `registrarc_refund_payments`), qui écrit aussi le journal. Tout montant affiché vient de `registrarc_load_engagements()`. `EnIndFEvent` y est recalculé : vrai seulement si la case de l'archer est cochée **et** si sa catégorie (EventClass, division, classe, sous-classe) est dans une épreuve individuelle avec `EvFinalFirstPhase <> 0` ; la détection des finales dans les paramètres demande aussi `EvFinalFirstPhase <> 0`.

## Données

Tout est dans `Modules/Custom/RegistrArc/data/`. Par tournoi :

| Fichier | Contenu |
|---|---|
| `payments_<n°>.json` | paiements : statut (`PAYE`, `NON_PAYE`, `REMBOURSE`), mode, montant, date, opérateur, remise ; pour un remboursement : mode, montant et motif (`refund_reason`, obligatoire). Supprimer le paiement d'un engagement remboursé écrit deux opérations au journal : `refund_cancel` (+, mode du remboursement) et `cancel` (−, mode du paiement) |
| `settings_<n°>.json` | classes d'âge, tarifs, règles, modes de paiement, plafond. Les tarifs sont des totaux cumulés par nombre de départs (`{"1": 10, "2": 17, "3": 21}`), de 2 paliers au nombre de départs du concours (`ToNumSession`) ; au-delà du dernier, le dernier supplément se répète (`registrarc_tarif_step()`). Options du greffe dans `greffe` : `checkin_on_payment` (vrai par défaut : valider un paiement depuis la liste ou le mode Kiosk pointe aussi l'archer au greffe, via `registrarc_record_payments()` ; jamais pour les sources `import` et `helloasso`) |
| `cheque_groups_<n°>.json` | chèques groupés |
| `archers_<n°>.json` | par engagement : note et passage au greffe (`checked_in` : date, `checked_in_by` : opérateur), pointé dans la vue par archer de la liste |
| `journal_<n°>.json` | journal des opérations, uniquement en ajout |
| `deposits_<n°>.json` | remises de chèques déposées |
| `cash_float_<n°>.json` | fond de caisse : `mode` `detail` avec la quantité par valeur en centimes dans `detail` (`{"1000": 2, "50": 4}` = 2 billets de 10 € et 4 pièces de 0,50 €), ou `simple` avec un seul montant dans `amount`. Le comptage de clôture suit le même mode |
| `closures_<n°>.json` | clôtures de caisse ; chaque clôture retient sa position dans le journal (`journal_from` / `journal_to`), le fond de caisse du moment (`cash_float_mode`, `cash_float`, `cash_float_detail`), les encaissements en espèces (`cash_takings`), les espèces attendues (fond compris) et le comptage, détaillé ou simplifié au choix (`count_mode` du formulaire, quel que soit le fond) : `cash_counted`, `cash_counted_detail` (vide pour un comptage simplifié) |
| `state_<n°>.json` | concours clôturé ou non |

Commun à tous les tournois : `invoices.json`, registre des factures émises (compteur par année et copie figée de chaque facture), et `club_cities.json`, villes des clubs corrigées dans la page « Villes des clubs » (par numéro de club).

- **Écritures fiables** : chaque modification se fait sous verrou exclusif (`<fichier>.lock`) pendant tout le cycle lecture / modification / écriture, puis par fichier temporaire et renommage. Plusieurs postes peuvent encaisser en même temps.
- **Copie de secours** : la version précédente de chaque fichier est gardée en `.bak`. Si un fichier devient illisible, le module refuse d'écrire par-dessus.
- **Clôtures de caisse** : les périodes sont découpées par position dans le journal et non par heure, pour qu'une opération ne soit jamais perdue ni comptée deux fois.
- **Protection** : `data/` reçoit automatiquement un `.htaccess` (Apache), un `web.config` (IIS) et un `index.html` vide. Avec **nginx**, ces fichiers sont ignorés : il faut ajouter dans la configuration du site
  ```nginx
  location ~ /Modules/Custom/RegistrArc/data/ { deny all; }
  ```
  Vérification : l'adresse `…/Modules/Custom/RegistrArc/data/payments_1.json` doit répondre 403 ou 404. Avec Apache, `.htaccess` n'est lu que si `AllowOverride` l'autorise (c'est le cas de l'installation Windows d'I@nseo).
- **Formulaires** : ils portent un jeton anti-CSRF et le numéro du tournoi affiché ; un envoi depuis un onglet resté sur un autre tournoi, ou vers un concours clôturé, est refusé.
- **Sauvegarde** : bouton du tableau de bord (`export.php?type=backup`), ou copie du dossier `data/`.

## Mise à jour du module

- `module.json` indique le dépôt (`github_url`), la branche (`github_branch`, `main` par défaut), le dossier (`github_path`) et la façon de lire la dernière version (`github_ref`). Il n'est jamais remplacé par une mise à jour.
- **Toujours la dernière version** (`"github_ref": "latest"`, valeur par défaut, même si la ligne manque) : le module demande à l'API GitHub le dernier commit de la branche (`api.github.com/repos/…/commits/<branche>`), puis lit `version.json` et **tous les fichiers à ce commit précis**. Le cache de `raw.githubusercontent.com` (plusieurs minutes) ne joue plus, et une installation ne peut jamais mélanger deux versions. Le commit lu s'affiche sur la page de mise à jour. Si l'API ne répond pas (hors ligne, limite de 60 requêtes par heure sans jeton), repli automatique sur la lecture à la branche. `"github_ref": "branch"` force l'ancienne méthode.
- La version publiée est lue dans `version.json` (au dernier commit, voir ci-dessus). Le résultat est gardé en cache dans `data/update_check.json` : 6 h, ou 30 min après un échec. `update.php?force=1` refait la vérification tout de suite.
- L'installation télécharge d'abord tous les fichiers listés dans `files` dans un dossier temporaire et ne remplace rien si un seul échoue. Les fichiers remplacés sont sauvegardés dans `data/backup_<version>_<date>/`. `version.json` est remplacé en dernier. `data/` et `module.json` ne sont jamais touchés.
- Chaque fichier téléchargé doit correspondre à son empreinte SHA-256 (`sha256` dans `version.json`), sinon rien n'est installé. Cela écarte un fichier tronqué, ou un mélange de versions quand le cache de GitHub sert encore d'anciens fichiers juste après une publication. Ces empreintes viennent du même dépôt que les fichiers : elles ne protègent pas contre un dépôt GitHub compromis.

### Publier une nouvelle version

Sur la branche `main` :

1. Augmenter `version` dans `version.json` (format `1.2.3`).
2. Mettre à jour `date` et `notes` (les notes s'affichent sur la page de mise à jour).
3. Ajouter dans `files` tout nouveau fichier du module.
4. Lancer les tests, puis calculer les empreintes (en dernier, une fois tous les fichiers dans leur version finale) :
   ```bash
   php tests/run.php
   php tools/version_hashes.php
   ```
   Avec le PHP fourni par I@nseo sous Windows : `C:\ianseo\php\php.exe -n -d extension_dir=C:\ianseo\php\ext -d extension=mbstring tests\run.php` (même chose pour l'outil). `version_hashes.php` signale aussi un fichier suivi par git mais oublié dans `files`.
5. Faire le commit de tous les fichiers, `version.json` compris, et le pousser. Un fichier modifié après le calcul des empreintes ferait refuser la mise à jour : il suffit alors de relancer l'outil et de pousser à nouveau.

Les installations proposent la nouvelle version au contrôle suivant. Le `README.md` et les captures de `docs/images/` sont listés dans `files` : ils sont copiés sur les installations et affichés par `aide.php`. Une nouvelle capture doit donc être ajoutée à `files` (l'outil d'empreintes le signale). `docs/MAINTENANCE.md` n'est pas distribué : dans l'aide, son lien ouvre GitHub.

Le README est écrit pour GitHub et pour l'aide du module à la fois : liens internes vers les titres (`#la-caisse`), sections `<details>`, encadrés `> [!TIP]`, images en chemins relatifs (`docs/images/…`). Les images hébergées ailleurs (badges) ne sont pas affichées dans le module.
