# RegistrArc

**RegistrArc** est un module complémentaire pour le logiciel de gestion de compétitions I@nseo. Il permet de gérer les engagements et les paiements des archers participants à un tournoi.

---

## 🎯 À quoi sert ce module ?

Quand vous organisez un tournoi de tir à l'arc avec I@nseo, **RegistrArc** vous aide à :

- **Suivre qui est inscrit** et pour quelles catégories/départs
- **Gérer les paiements** : savoir qui a payé, combien, et quand
- **Automatiser la tarification** : appliquer des règles selon l'âge, le club, le nombre de départs…
- **Générer des factures** au format PDF, individualisées ou groupées par club
- **Organiser les archers** sur les cibles et les lignes de départ

---

## ✨ Fonctionnalités principales

### 📋 Liste des archers engagés
- Voir d'un coup d'œil tous les participants inscrits
- Identifier le nombre de départs et les catégories de chaque archer
- Connaître le club et la région de chacun

### 💰 Suivi des règlements
- Enregistrer les paiements (montant, date, moyen de paiement, référence)
- Distinguer les arriérés des paiements partiels
- Marquer un archer comme "réglé" d'un simple clic
- Conserver l'historique de tous les règlements

### 🧮 Tarification automatique
Le module calcule automatiquement le montant à payer en fonction de **règles configurables** :

| Critère | Exemple |
|---|---|
| Par catégorie | Junior 免费, Senior 6€, Vétéran 4€… |
| Par nombre de départs | 1 départ = 6€, 2 départs = 10€… |
| Par club ou région | Tarif réduit pour les clubs de votre région |
| Par type de qualification | Individuelle, par équipe, les deux |

> Si vous ne configurez rien, un tarif par défaut s'applique.

### 📄 Factures PDF
- Générer une facture individuelle par archer
- Générer une **facture groupée** par club (pour votre comptabilité)
- Format A4, prêt à imprimer ou à envoyer par e-mail
- Mention du nom du tournoi, de la date, et des détails de paiement

### 🎯 Positionnement sur cibles et départs
- Identifier rapidement le positionnement en phase éliminatoire (poids sur cible)
- Consulter les informations de positionnement gauche/droite et haut/bas

### 🔍 Filtres, recherche et export
- **Filtrer** par catégorie, club, région ou état de paiement
- **Rechercher** un archer par son nom ou son club
- **Trier** la liste selon différents critères
- **Exporter** la configuration des tarifs au format JSON (sauvegarde)
- **Importer** une configuration JSON précédemment sauvegardée
- **Imprimer** la liste des archers engagés

---

## 🔧 Installation

### Prérequis
- Disposer d'une installation fonctionnelle d'**I@nseo**
- Avoir accès au dossier d'installation sur le serveur

### Étapes d'installation

1. **Téléchargez** le dossier `RegistrArc` contenant les fichiers du module
2. **Copiez** l'ensemble du dossier dans le répertoire :
   ```
   /votre-dossier-ianseo/Modules/Custom/RegistrArc/
   ```
3. **Vérifiez** que les droits d'écriture sont activés sur le dossier `RegistrArc` (le module crée un sous-dossier `data` pour stocker ses informations)
4. **Accédez au module** depuis I@nseo : dans le menu de gestion du tournoi, ouvrez la section **Modules** puis **RegistrArc**

> Le module crée automatiquement le dossier `data` à la première utilisation si les droits sont correctement configurés.

---

## 💡 Conseils d'utilisation

### Avant le tournoi
1. Configurez vos **tarifs** dans la section dédiée du module
2. Définissez les règles spécifiques (catégories, régions, nombre de départs)
3. Exportez votre configuration en JSON pour disposer d'une sauvegarde

### Pendant le tournoi
- Utilisez les **filtres** pour vous concentrer sur les archers non encore réglés
- Mettez à jour les paiements en temps réel depuis la liste principale
- Générez les factures au fur et à mesure ou par lot avant la fin de l'événement

### Pour la facturation club
- Utilisez la **facture groupée par club** pour regrouper les engagements de tous les archers d'un même club
- Imprimez ou envoyez par e-mail la facture au responsable de club après le tournoi

---

## 📂 Fichiers du module

| Fichier | Rôle |
|---|---|
| `index.php` | Interface principale : liste des archers, gestion des paiements |
| `config_tarifs.php` | Configuration des règles de tarification |
| `invoice.php` | Génération des factures PDF |
| `menu.php` | Intégration au menu I@nseo |

---

## 👤 Auteur

**Musica405Archery**

---

## 📝 Licence

Voir les informations contained dans le dépôt.
