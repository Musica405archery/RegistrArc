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
| `index.php` | liste des engagements : encaissement, annulation, remboursement, note, import / export des inscriptions |
| `kiosk.php` | mode Kiosk (`kiosk.php?q=licence` ouvre directement un archer) |
| `config_tarifs.php` | paramètres du tournoi |
| `invoice.php` | factures : aperçu, émission numérotée (`?num=2026-0001`) |
| `bankchequedeposite.php` | bordereau de remise de chèques, remises déposées (`?remise=R1`) |
| `caisse.php` | caisse en cours, clôtures (`?cloture=C1`) |
| `dashboard.php` | tableau de bord, sauvegarde / restauration, clôture du concours |
| `export.php` | téléchargements : `?type=engagements`, `journal` (CSV), `backup` (JSON) |
| `helloasso.php` | import des paiements HelloAsso |
| `qrcodes.php` | étiquettes QR codes à imprimer |
| `update.php` | page de mise à jour |
| `menu.php` | entrée du menu I@nseo |
| `lib/registrarc.inc.php` | fonctions communes : paramètres, tarifs de base, chèques groupés, sécurité des formulaires ; charge les autres fichiers de `lib/` |
| `lib/registrarc_engagements.inc.php` | **calcul unique des montants** utilisé par toutes les pages (tarif, règles, plafond, majoration, statut) |
| `lib/registrarc_caisse.inc.php` | opérations de paiement journalisées, remises déposées, clôtures de caisse, concours clôturé |
| `lib/registrarc_invoices.inc.php` | registre des factures émises |
| `lib/registrarc_backup.inc.php` | sauvegarde et restauration |
| `lib/registrarc_helloasso.inc.php` | lecture et rapprochement des exports HelloAsso |
| `lib/registrarc_ui.inc.php` | habillage commun des pages d'administration |
| `lib/registrarc_storage.inc.php` | stockage JSON : écriture atomique, verrous, copies `.bak` |
| `lib/registrarc_update.inc.php` | mise à jour depuis GitHub |
| `lib/js/qrcode.js` | génération des QR codes (qrcode-generator, Kazuhiko Arase, licence MIT) |
| `module.json` | dépôt et branche d'origine des mises à jour (configuration locale, jamais écrasée) |
| `version.json` | version du module et liste des fichiers distribués |

Le module ne modifie jamais la structure des tables I@nseo : il se contente de les lire.

**Règle importante** : tout changement de paiement passe par `registrarc_apply_payment_changes()` (ou ses raccourcis `registrarc_record_payments`, `registrarc_cancel_payments`, `registrarc_refund_payments`), qui écrit aussi le journal. Tout montant affiché vient de `registrarc_load_engagements()`.

## Données

Tout est dans `Modules/Custom/RegistrArc/data/`. Par tournoi :

| Fichier | Contenu |
|---|---|
| `payments_<n°>.json` | paiements : statut (`PAYE`, `NON_PAYE`, `REMBOURSE`), mode, montant, date, opérateur, remise |
| `settings_<n°>.json` | classes d'âge, tarifs, règles, modes de paiement, plafond et majoration |
| `cheque_groups_<n°>.json` | chèques groupés |
| `archers_<n°>.json` | note et inscription tardive, par engagement |
| `journal_<n°>.json` | journal des opérations, uniquement en ajout |
| `deposits_<n°>.json` | remises de chèques déposées |
| `closures_<n°>.json` | clôtures de caisse ; chaque clôture retient sa position dans le journal (`journal_from` / `journal_to`) |
| `state_<n°>.json` | concours clôturé ou non |

Commun à tous les tournois : `invoices.json`, registre des factures émises (compteur par année et copie figée de chaque facture).

- **Écritures fiables** : chaque modification se fait sous verrou exclusif (`<fichier>.lock`) pendant tout le cycle lecture / modification / écriture, puis par fichier temporaire et renommage. Plusieurs postes peuvent encaisser en même temps.
- **Copie de secours** : la version précédente de chaque fichier est gardée en `.bak`. Si un fichier devient illisible, le module refuse d'écrire par-dessus.
- **Clôtures de caisse** : les périodes sont découpées par position dans le journal et non par heure, pour qu'une opération ne soit jamais perdue ni comptée deux fois.
- **Protection** : `data/` reçoit automatiquement un `.htaccess` (`Require all denied`, Apache) et un `index.html` vide. Les formulaires portent un jeton anti-CSRF et le numéro du tournoi affiché ; un envoi depuis un onglet resté sur un autre tournoi, ou vers un concours clôturé, est refusé.
- **Sauvegarde** : bouton du tableau de bord (`export.php?type=backup`), ou copie du dossier `data/`.

## Mise à jour du module

- `module.json` indique le dépôt (`github_url`), la branche (`github_branch`, `main` par défaut) et le dossier (`github_path`). Il n'est jamais remplacé par une mise à jour.
- La version publiée est lue dans `version.json` sur la branche indiquée. Le résultat est gardé en cache dans `data/update_check.json` : 6 h, ou 30 min après un échec.
- L'installation télécharge d'abord tous les fichiers listés dans `files` dans un dossier temporaire et ne remplace rien si un seul échoue. Les fichiers remplacés sont sauvegardés dans `data/backup_<version>_<date>/`. `version.json` est remplacé en dernier. `data/` et `module.json` ne sont jamais touchés.

### Publier une nouvelle version

Sur la branche `main` :

1. Augmenter `version` dans `version.json` (format `1.2.3`).
2. Mettre à jour `date` et `notes` (les notes s'affichent sur la page de mise à jour).
3. Ajouter dans `files` tout nouveau fichier du module.

Les installations proposent la nouvelle version au contrôle suivant. Les captures de `docs/images/` ne sont pas listées dans `files` : elles ne sont pas copiées sur les installations.
