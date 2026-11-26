# Translating the admin panel

How the admin panel's own words are stored, how a word is written so it shows right in every language, and the French
glossary. This is about the **panel's** languages — the words of QuickSite's own interface. A **site's** translations (the
words of the website an author builds) are a different system, described in `ARCHITECTURE.md`.

## 1. The word files

- The panel's words live in `secure/admin/translations/`, one JSON file per language: `en.json`, `fr.json`. A file's name
  is its language code; the panel offers every language it finds there. Only `*.json` files are read.
- `en.json` is the reference: every key the panel reads is in it. Another language's file holds the same keys, nested the
  same way, each with its word.
- A key that a language's file does not hold shows its English word, key by key. A key that no file holds shows its own
  path (`section.key`), so a missing word is visible, never hidden.
- A key that `en.json` lacks is never added to another language's file; a key is never written twice in one file.
- QuickSite's own words on an author's site (the default toasts, the light / dark switch, a build's 404 page) are not
  panel words: they live in `secure/src/translations/`, the starting-word files, one per language, a language with no
  file taking English.

## 2. How a word is written

| Part | Rule |
|---|---|
| Placeholders | `{name}` is filled by the panel with a value (a name, a number, an element). Keep every placeholder of the English, spelled exactly, as many times; move it where the language needs it. A server-side word may also use `:name`, kept the same way. |
| Counted words | A word that depends on a number is two keys, `<key>.one` and `<key>.other`; the panel picks the form with the browser's plural rules for the panel's language. Write both. In French, 0 and 1 take `.one`. |
| Numbers, dates, sizes | Numbers and dates are written by the browser in the panel's language, never as words. A size's unit is a word (`common.size.*`). |
| Markup | Tags inside a word (`<code>`, `<b>`, `<strong>`, `<em>`, `<kbd>`, `<br>`, a link) are kept, as many as the English has, around the matching words. |
| Joins | Where the panel writes a label, then punctuation, then a value, the punctuation is a word of its own: `common.join.colon` (the colon after a label) and `common.join.semicolon` (between messages in a list). A language that spaces its punctuation writes the space in these two words. |
| Code | CSS, JSON, HTML tag and attribute names shown as code, HTTP methods, command names, key paths, URLs and a sample value a person types as it is stay as written. |

**What stays English in every language:** a prompt the panel sends to an AI model (the Workflows runner's request to
continue a cut answer), a command's own message from the server, a command's documentation (its description, parameters,
validation rules and examples: the API's reference, the same on every installation), the verb and data-attribute catalogs'
descriptions, the AI connection presets' instructions, and the browser's own controls. In any panel language but English,
the command console says so above each command (`commandForm.docsInEnglish`).

**A new word:** add it to `en.json` first, then to each other language's file at the same path. A word one language
lacks still shows (in English) until it is written.

## 3. French

### Conventions

| Convention | Rule |
|---|---|
| Address | *vous*, always; an instruction in the *vous* imperative (*Cliquez*, *Choisissez*). |
| Buttons and actions | The infinitive (*Enregistrer*, *Supprimer*, *Créer le compte*). |
| Capitals | Sentence case: the first word and proper names only (*Paramètres du projet*, not *Paramètres du Projet*). |
| Before `:` `;` `?` `!` | A no-break space (U+00A0, written `\u00a0` in the file), so the sign never starts a line. None where a colon starts a placeholder (`:name`), a time or an address. |
| Quotes | « » with a no-break space inside, in a sentence; straight `"` only inside code and samples. |
| Ellipsis | One character, `…`. |
| Apostrophe | Straight `'`. |
| *e.g.* / *i.e.* | *ex. :* / *c.-à-d.* |
| A sentence around a placeholder | It agrees whatever fills it: the agreement goes with a known noun (*La clé « {key} » a été créée*), never with the unknown value. |
| *successfully* | *avec succès*. |
| Length | A button, tab, chip or menu entry stays close to its English length; where French cannot, the longer word is kept. |
| Tone | Natural French, not a word-for-word copy of the English. |

### Terms

**Pages and menu**

| English | French |
|---|---|
| Dashboard · Visual Editor · Command (the console) · History | Tableau de bord · Éditeur visuel · Commande (la console de commandes) · Historique |
| APIs · API Endpoints (menu) | APIs · Endpoints API |
| Build (the menu group) · Builds (the page) · a build | Construire · Compilations · une compilation |
| Media · Site · Sitemap · Translations · Social previews | Médias · Site · Plan du site · Traductions · Aperçus de réseaux sociaux |
| OAuth providers · My memberships · Members | Fournisseurs OAuth · Mes adhésions · Membres |
| Compliance · Storage · Privacy | Conformité · Stockage · Confidentialité |
| Optimize · CSS Refiner · Settings · AI Connections · Embed Security · My account | Optimiser · Affineur CSS · Paramètres · Connexions IA · Sécurité d'intégration · Mon compte |
| Simple / Advanced (the modes) | Simple / Avancé |

**The editor**

| English | French |
|---|---|
| Select · Move · Text · CSS · Interactions · Translations · Workflows (the tools) | Sélection · Déplacer · Texte · CSS · Interactions · Traductions · Workflows |
| Source · Theme · Selectors · Motion (the CSS tool's tabs) | Source · Thème · Sélecteurs · Animation |
| Add Element · HTML Tag · Component · Snippet · Complex | Ajouter un élément · Balise HTML · Composant · Snippet · Complexe |
| element · node · tag · attribute · class · selector | élément · nœud · balise · attribut · classe · sélecteur |
| keyframes · a keyframe's frame · easing · event · trigger | keyframes (*une keyframe*) · une étape · courbe d'accélération · événement · déclencheur |
| state store · wizard · draft · preview | store d'état (*le store*) · assistant · brouillon · aperçu |
| page · route · menu · footer · home | page · route · menu · pied de page · accueil |

**Words, files, projects**

| English | French |
|---|---|
| a word (a translation's value) · key · translation key | un texte · clé · clé de traduction |
| language · default language · project · site | langue · langue par défaut · projet · site |
| file · asset · picture · starred | fichier · fichier (in Médias) · image · favori |
| upload · download · backup · restore · export · import | envoyer (*l'envoi*) · télécharger · sauvegarde · restaurer · exporter · importer |
| command · run (noun) · to run · workflow · prompt | commande · exécution · exécuter · workflow (*le workflow*) · prompt (*le prompt*) |
| AI · AI connection · model · endpoint · token | IA · connexion IA · modèle · endpoint (*l'endpoint*) · jeton |
| schema · header · body · underscore | schéma · en-tête · corps · tiret bas |
| cookie · consent · consent layer · banner · cookie policy · data collected | cookie · consentement · dispositif de consentement · bannière · politique de cookies · donnée collectée |
| storage scope (localStorage, sessionStorage, cookie) · quota · changes left | type de stockage · quota · modifications restantes |

**People and access**

| English | French |
|---|---|
| member · membership · invitation · join request · proposal | membre · adhésion · invitation · demande d'adhésion · proposition |
| role · permissions · owner | rôle · autorisations · propriétaire |
| the roles: viewer · editor · designer · developer · admin · owner | lecteur · rédacteur · designer · développeur · admin · propriétaire (lower case) |
| account · username · password · sign in · sign out | compte · nom d'utilisateur · mot de passe · se connecter (*Connexion*) · se déconnecter (*Déconnexion*) |
| operator · installation · visitor · author | opérateur · installation · visiteur · auteur |

**Actions**

| English | French |
|---|---|
| Save · Cancel · Close · Apply · Confirm | Enregistrer · Annuler · Fermer · Appliquer · Confirmer |
| Delete (destroys) · Remove (takes out of a list) | Supprimer · Retirer |
| Edit · Rename · Duplicate · Create · Add | Modifier · Renommer · Dupliquer · Créer · Ajouter |
| Reset · Refresh · Search · Filter · Copy · Clear | Réinitialiser · Actualiser · Rechercher · Filtrer · Copier · Effacer |
| Select (pick in a list or a dialog) · select an element in the editor | Choisir (*— Choisir une API —*) · Sélectionner |
| Optional · Required · Loading… · Error · unknown error | Optionnel · Requis · Chargement… · Erreur · erreur inconnue |

**Genders of the borrowed words:** le workflow · le snippet · le store · l'endpoint (m.) · le prompt · le cookie · le jeton ·
l'API (f.) · la keyframe.
