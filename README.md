# RegistrArc

**Module de Greffe pour I@nseo**

RegistrArc est un module de gestion des engagements et de facturation pour les compétitions de tir à l'arc gérées via I@nseo. Il permet de suivre les paiements, générer des factures et préparer les remises de chèques en banque.

## 🎯 À quoi sert ce module ?

RegistrArc simplifie la vie du greffe pendant une compétition en centralisant :

- La liste complète des engagements (archers inscrits)
- Le suivi des paiements (payé / non payé, mode de paiement)
- La génération de factures individuelles ou groupées
- La préparation des remises de chèques en banque
- Le paramétrage des tarifs d'engagement

## 📋 Liste des engagements

C'est l'écran principal du module. Il affiche tous les archers inscrits à la compétition avec, pour chacun :

- Son numéro d'engagement
- Sa licence
- Son nom, prénom, club
- Sa catégorie (ex : Senior Homme, U15Femme...)
- Son départ (session) et sa cible
- Le tarif appliqué et le montant dû
- Son statut de paiement et le mode de paiement utilisé

**Filtres et tri disponibles :**
- Filtrage par club, catégorie, départ, statut de paiement
- Tri croissant/décroissant sur n'importe quelle colonne (nom, montant, statut, etc.) en cliquant sur l'en-tête de colonne

**Import d'engagements :**
Un bouton d'import permet de charger un fichier JSON d'engagements exporté depuis I@nseo. Le module tente de faire correspondre automatiquement chaque ligne importée avec les engagements déjà présents dans la base (via la licence, la catégorie, le départ et la cible), afin de récupérer les informations de paiement déjà saisies.

## 💰 Gestion des paiements

Pour chaque engagement, vous pouvez :

1. **Marquer comme payé / non payé**
2. **Choisir le mode de paiement** parmi :
   - `ESP` – Espèces
   - `CHQ` – Chèque
   - `VIR` – Virement
   - `GRA` – Gratuit

**Traitement en masse :**
Cochez plusieurs engagements dans la liste, puis choisissez une action groupée (ex : valider le paiement en chèque pour tous les engagements sélectionnés). Si vous sélectionnez le mode **Chèque** pour 2 engagements ou plus, le module vous propose automatiquement de créer un **groupement de chèques**, pratique pour la remise en banque groupée.

## 🧮 Paramétrage des tarifs (page Paramètres)

C'est ici que vous définissez combien chaque archer doit payer selon sa situation. Deux niveaux de paramétrage sont disponibles : les **tarifs de base** et les **règles avancées**.

### 1. Tarifs de base

Les tarifs sont définis selon deux critères :

| Critère | Valeurs possibles |
|---|---|
| Type de club | Club organisateur / Autres clubs |
| Catégorie d'âge | Jeunes / Adultes |
| Nombre d'engagements | 1er engagement / 2ème engagement (et suivants) |

Par exemple, par défaut :
- Un jeune d'un club extérieur paiera **8 €** pour son 1er engagement et **14 €** pour un 2ème.
- Un adulte du club organisateur paiera **5 €** pour son 1er engagement et **10 €** pour un 2ème.

👉 Ces montants sont entièrement modifiables depuis la page de paramétrage, selon les tarifs décidés par votre club pour la compétition.

### 2. Règles avancées

Les règles avancées permettent de gérer des cas particuliers qui ne rentrent pas dans la grille tarifaire classique (ex : tarif fixe pour une finale, gratuité pour certains archers, etc.).

**Comment fonctionne une règle :**

- **Portée (scope)** : à quoi s'applique la règle (ex : engagement en finale individuelle, finale par équipe, double mixte...)
- **Condition (match)** : les critères que doit remplir l'engagement pour que la règle s'applique
- **Action** : ce que fait la règle si elle s'applique — actuellement, il s'agit d'un **tarif fixe** qui remplace ou s'ajoute au calcul standard
- **Libellé** : un texte affiché sur la facture pour expliquer pourquoi ce tarif a été appliqué (ex : "Tarif finale équipe")
- **Actif** : chaque règle peut être activée ou désactivée sans être supprimée

**Détection automatique des phases finales :**
Le module analyse la compétition et détecte automatiquement si des phases finales existent :
- Finale individuelle
- Finale par équipe
- Double mixte

⚠️ Si aucune finale n'est détectée pour un type donné, la règle correspondante n'est pas proposée dans l'interface — inutile de configurer une règle "finale équipe" si votre compétition n'a pas de finale équipe.

**Cumul de règles :**
Si plusieurs règles s'appliquent à un même engagement, leurs montants s'additionnent et leurs libellés sont concaténés sur la facture (ex : *"Tarif finale équipe + Supplément mixte"*).

### 3. Import / Export des paramètres

- **Export** : téléchargez votre configuration de tarifs au format JSON, avec le code du tournoi et la date d'enregistrement inclus.
- **Import** : réimportez un fichier de configuration précédemment exporté.

⚠️ **Sécurité à l'import** : le module vérifie automatiquement :
- Si le **code du tournoi** du fichier importé correspond à celui du tournoi en cours (sinon, un avertissement s'affiche)
- Si la **date d'enregistrement** du fichier importé est plus ancienne que celle de la configuration actuelle (pour éviter d'écraser par erreur une configuration plus récente par une plus ancienne)

Un message de confirmation détaillé s'affiche avant tout remplacement, reprenant ces informations.

## 🧾 Facturation

- **Facture individuelle** : cliquez sur l'icône facture d'un engagement pour l'ouvrir dans un nouvel onglet, prête à imprimer.
- **Facture groupée** : sélectionnez au moins 2 engagements dans la liste, puis cliquez sur "Facture groupée" pour générer un document unique reprenant l'ensemble.

Chaque facture reprend :
- Les informations de la compétition (nom, lieu, date, logo)
- Le détail des engagements concernés
- Le tarif appliqué pour chaque ligne (tarif standard ou règle avancée, identifiable par un badge de couleur différente)
- Le montant total et le mode de paiement

## 🏦 Remise de chèques en banque

Accessible via le bouton **Remise de chèque**, cet écran permet de préparer le bordereau à joindre lors du dépôt des chèques en banque.

**Trois façons de l'utiliser :**
1. **Sans sélection** : affiche tous les groupes de chèques existants, puis les paiements en chèque non groupés
2. **Avec une sélection d'engagements** : affiche uniquement les chèques correspondant aux engagements cochés dans la liste
3. **Avec un groupe existant** : affiche le détail d'un groupement de chèques déjà créé

**Le document généré affiche :**
- Le nombre total de chèques
- Le nombre d'engagements concernés
- Le montant total de la remise
- Des zones de signature manuelle et de champs à compléter à la main si besoin

Ce document est prêt à être imprimé au format A4.

## 🚀 Comment accéder au module ?

RegistrArc apparaît dans le menu **Greffe** de l'interface I@nseo, dans la section **Participants**, sous réserve de disposer des droits d'accès nécessaires (lecture/écriture sur la gestion des participants).

## 🖨️ Impression

La plupart des écrans (factures, remises de chèques, listes d'engagements) disposent d'une mise en page optimisée pour l'impression au format A4, directement depuis le navigateur (Ctrl+P ou bouton d'impression dédié).

## 📝 Notes importantes

- Les paiements et les groupes de chèques sont conservés **séparément pour chaque compétition** (tournoi).
- Les numéros de facture générés **ne sont pas persistants** : ils sont recalculés à chaque génération et incluent systématiquement la date et l'heure de création.
- Toujours vérifier les avertissements affichés lors d'un import de paramètres, afin de ne pas écraser une configuration tarifaire plus récente par erreur.

---

*Module développé pour la gestion des compétitions de tir à l'arc via I@nseo.*


Made with <3 avec Open>