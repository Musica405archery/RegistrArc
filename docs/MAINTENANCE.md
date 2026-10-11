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

## Données

Tout est dans `Modules/Custom/RegistrArc/data/`, un fichier par tournoi et par type :

| Fichier | Contenu |
|---|---|
| `payments_<n° tournoi>.json` | paiements (statut, mode, reçu demandé, date) |
| `settings_<n° tournoi>.json` | classes d'âge, tarifs, règles, modes de paiement |
| `cheque_groups_<n° tournoi>.json` | chèques groupés |

- **Écritures fiables** : chaque modification se fait sous verrou exclusif (`<fichier>.lock`) pendant tout le cycle lecture / modification / écriture, puis par fichier temporaire et renommage. Deux postes qui encaissent en même temps ne s'écrasent pas.
- **Copie de secours** : la version précédente de chaque fichier est gardée en `.bak`. Si un fichier devient illisible, le module refuse d'écrire par-dessus.
- **Protection** : `data/` reçoit automatiquement un `.htaccess` (`Require all denied`, Apache) et un `index.html` vide. Les formulaires portent un jeton anti-CSRF et le numéro du tournoi affiché ; un envoi depuis un onglet resté sur un autre tournoi est refusé.
- **Sauvegarde** : copier le dossier `data/`.

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
