# Notes de session — Plugin eedomus APsystems (apsolar)

Ce document résume tout ce qui a été appris en développant ce plugin, pour éviter de refaire les mêmes erreurs. À donner en contexte à Claude Code en début de session.

## Contexte du projet

Plugin eedomus (box domotique française, https://doc.eedomus.com) qui récupère la production de panneaux solaires APsystems via leur API OpenAPI (doc PDF fournie par APsystems, HMAC-SHA256 signée), inspiré de https://github.com/emlynmac/apsystems-openapi.

Fichiers du projet :
- `eedomus_plugin.json` — manifeste du plugin (périphériques, paramètres)
- `aps_solar.php` — script principal, appelé en continu par le capteur HTTP "today"
- `aps_discover.php` — script utilitaire historique pour retrouver ECU_ID et UID des onduleurs ; conservé dans le repo pour référence mais retiré du zip distribué et du manifeste (superseded par la popup "Panel information" de l'interface web EMA, voir doc utilisateur)
- `img/apsolar.png` — icône du plugin
- `readme_fr.md` / `readme_en.md` — documentation utilisateur

## Piège n°1 — Format du fichier eedomus_plugin.json

Le manifeste doit contenir ces clés de haut niveau obligatoires (sinon erreur "plugin_id invalide") :
```json
{
  "plugin_id": "apsolar",          // alphanumerique MINUSCULE uniquement, 12 caracteres MAX, pas de tiret/underscore
  "icon": "apsolar.png",           // doit exister dans img/
  "name_fr": "...",
  "name_en": "...",
  "version": "1.0",
  "creation_date": "YYYY-MM-DD",
  "modification_date": "YYYY-MM-DD",
  "author": "...",
  "description_fr": "...",
  "description_en": "...",
  "parameters": [...],
  "devices": [...],
  "scripts": [ { "name": "aps_solar.php" }, { "name": "aps_discover.php" } ]
}
```

**Piège majeur** : la clé pour déclarer les scripts est **`"scripts"` (PLURIEL)**, et c'est un **tableau d'objets** `{"name": "fichier.php"}`, PAS `"script": ["fichier.php"]` (singulier + tableau de strings). Avec la mauvaise clé, le fichier PHP est bien présent dans le zip mais eedomus ne le déploie/enregistre jamais → erreur runtime `Script introuvable [xxx.php]` même si le script tourne très bien en exécution manuelle après upload direct.

Référence trouvée : plugin réel `extdsun` d'influman sur GitHub (`influman/extdsun/eedomus_plugin.json`).

## Piège n°2 — Dossier img/ obligatoire dans le zip

Même si aucune icône custom n'est utilisée, le process de soumission du store fait un `scandir()` sur `img/` côté serveur → si le dossier n'existe pas explicitement comme entrée du zip, ça plante avec un warning PHP `scandir(): failed to open dir`. Toujours inclure un dossier `img/` avec au moins un fichier dedans (`zip -r` avec un dossier non vide suffit à créer l'entrée).

## Piège n°3 — Soumission du plugin au store

Ce n'est pas un simple "copier les fichiers" : il faut aller sur **Store eedomus → Publier sur le store → Parcourir → sélectionner le .zip → Envoyer**. Le zip doit avoir tous les fichiers **à la racine** (pas dans un sous-dossier). Le périphérique apparaît alors en **mode Privé**, réservé au compte, sans validation nécessaire.

**Important** : eedomus ne met **pas à jour rétroactivement** un périphérique déjà créé quand on modifie et resoumet le JSON. Après CHAQUE changement du zip touchant aux `devices`/`parameters`, il faut **supprimer entièrement le périphérique existant puis le recréer** depuis le Store, sinon la config affichée peut être un mélange d'ancien et de nouveau (vécu concrètement : unités vides, VAR vides, etc. à cause d'un périphérique recréé par-dessus un ancien).

## Piège n°4 — Champs de périphérique (devices[])

- `parent_id` doit référencer le **`device_id` brut** (ex. `"today"`), PAS `"plugin.devices.today"` (cette syntaxe de substitution `plugin.devices.X` ne s'applique qu'aux valeurs de paramètres runtime comme dans une URL, pas aux champs structurels résolus à la création).
- Un device avec `value_type: "float"` a besoin d'un champ **`value_unit`** (pas `"unit"`) sinon erreur de validation "Veuillez saisir une unité" à la création.
- Le champ natif "Fréquence de la requête" (POLLING) d'un capteur HTTP est exprimé en **MINUTES**, pas en secondes, et plafonné à **1000**.

## Piège n°5 — Fonctions PHP autorisées dans les scripts eedomus (sandbox)

Liste (non exhaustive) confirmée dans https://doc.eedomus.com/view/Scripts : `substr, strlen, str_replace, explode, sprintf, gettype, htmlspecialchars, switch, count, isset, array, date, time, md5, uniqid, rand, base64_encode, hash_hmac, foreach, for, if/else, while`...

**NE SONT PAS autorisées** (ou non confirmées, à éviter par prudence) : `is_array()` (utiliser `gettype($x) == 'array'` à la place), l'opérateur ternaire `? :` (préférer `if/else` explicite par prudence — pas 100% confirmé interdit mais on l'a évité systématiquement).

**Toute fonction utilisateur (définie par vous) doit être préfixée par `sdk_`** (ex. `function sdk_ma_fonction(...)`), sinon erreur "La fonction utilisateur XXX() doit être préfixée par 'sdk_'".

Fonctions SDK spécifiques utilisées : `getArg($nom, $mandatory=true, $default=' ')`, `httpQuery($url, $method, $post, $oauth_token, $headers)`, `sdk_json_decode($json)`, `setValue($periph_id, $value, ...)`, `sdk_header($content_type)` (seulement `'text/xml'` ou `'image/jpg'` supportés), `saveVariable($nom, $valeur)`, `loadVariable($nom)`, `deleteVariable($nom)`.

**Encodage** : les fichiers .php doivent être en **ISO-8859-1 / ANSI** (pas UTF-8), sinon les accents affichés peuvent être corrompus. Par simplicité, on a évité tout accent dans le code (commentaires et chaînes de caractères en français sans accents).

## Piège n°10 — Rendu markdown de doc.php (aide du plugin dans eedomus)

L'aide du plugin (bouton "?" dans eedomus) affiche `readme_fr.md`/`readme_en.md` via `https://secure.eedomus.com/pages/doc.php?type=PLUGIN_ID&file=readme_xx.md`, convertis en HTML par un moteur markdown maison assez limité. Constaté en inspectant le HTML généré (voir historique de session) :

- **Pas de support des tableaux GFM** (`| a | b |`) : les lignes sont juste recopiées telles quelles dans un `<p>`, sans jamais devenir un `<table>`. **Remplacer tout tableau par une liste à puces** (`- **`NOM`** : description`), seule forme testée et fiable dans ce renderer.
- **Les underscores sont traités comme de l'emphase même au milieu d'un mot** (pas de "smart underscore" comme sur GitHub) : `APP_ID` en texte brut devient `APP<em>ID</em SECRET...` (cassé) dans le HTML. **Toujours entourer les identifiants contenant un underscore de backticks** (`` `APP_ID` ``) : à l'intérieur d'un span de code, le contenu n'est pas réinterprété par le parseur markdown, donc l'underscore reste littéral.
- **Le fichier doit être servi en ISO-8859-1**, comme les scripts .php (piège n°5) — pas seulement une bonne pratique ici, un vrai bug constaté : un caractère UTF-8 multi-octets (ex. `≈`, ou un accent français) ressort en mojibake (`â‰ˆ`, `Ã©` pour `é`, `Ã ` pour `à`) dans le HTML généré, preuve qu'eedomus interprète le fichier comme du Latin-1 côté serveur.

**Solution retenue** : `readme_fr.md`/`readme_en.md` restent en **UTF-8 dans les sources** (édition normale, accents complets, plus lisible/diffable en git). La conversion en ISO-8859-1 se fait **uniquement au moment du build**, dans `build.sh` (via `iconv -f UTF-8 -t ISO-8859-1`) et `build.ps1` (via `[System.Text.Encoding]::GetEncoding("ISO-8859-1", ExceptionFallback, ExceptionFallback)`, qui fait échouer le build avec une erreur claire si un caractère hors Latin-1 traîne dans un readme — ex. `≈`, tirets/guillemets typographiques, `œ` — plutôt que de le laisser passer et corrompre silencieusement l'aide en ligne). Le fichier zippé est donc converti, mais le fichier source du repo ne change jamais d'encodage.

## Piège n°6 — Limite des 3 VAR dans les capteurs HTTP (le plus gros piège)

Un capteur HTTP eedomus (module_id 51) ne substitue **que `[VAR1]`, `[VAR2]`, `[VAR3]`** dans ses champs `RAW_URL` et `RAW_XPATH` — jamais VAR4+.

**Pire** : concaténer plusieurs tags `plugin.parameters.X` dans la valeur d'UN SEUL VARn (ex. `"VAR2": "plugin.parameters.APP_SECRET|plugin.parameters.SID"`) s'est révélé **non fiable à l'usage** : parfois 2 des 4 tags substitués, parfois 0 sur 2, de façon apparemment imprévisible (peut-être lié à la présence d'une valeur vide dans le lot, ou une limite du nombre de tags par champ — pas identifié avec certitude). **Ne JAMAIS concaténer plusieurs tags dans un seul VARn.** Un seul tag par VAR, toujours.

Solution retenue pour ce plugin (seulement 3 valeurs "runtime" réellement nécessaires : APP_ID, APP_SECRET, SID) :
```json
"VAR1": "plugin.parameters.APP_ID",
"VAR2": "plugin.parameters.APP_SECRET",
"VAR3": "plugin.parameters.SID",
"RAW_URL": "http://localhost/script/?exec=aps_solar.php&p1=[VAR1]&p2=[VAR2]&p3=[VAR3]"
```

Pour les valeurs supplémentaires qui ne rentrent pas dans les 3 VAR (ici : ECU_ID, optionnel et potentiellement vide ; BASE_URL) :
- **BASE_URL** : codé en dur dans le script (cas d'usage trop rare pour justifier un slot).
- **ECU_ID** : mémorisé une fois via `saveVariable()`/`loadVariable()`, avec un appel MANUEL unique de la forme :
  `http://box/script/?exec=aps_solar.php&p1=...&p2=...&p3=...&ecu=XXXX&eedomus_controller_module_id=CODE_API`
  Le script détecte le paramètre `ecu` optionnel ; s'il est présent, il le sauvegarde (`saveVariable`) ; sinon il le recharge (`loadVariable`). La clé de variable est préfixée par `eedomus_controller_module_id` (voir piège n°7) pour supporter plusieurs instances du plugin sans collision.

**Attention** : `loadVariable`/`saveVariable` sont scopées **par nom de fichier de script** — deux scripts différents ne partagent jamais une variable de même nom, même sur le même compte. Il faut donc faire le save/load **dans le même fichier**.

## Piège n°7 — eedomus_controller_module_id gratuit

Chaque appel automatique d'un capteur HTTP vers son script ajoute **automatiquement** `&eedomus_controller_module_id=XXXXX` à l'URL (le code API du périphérique appelant). Récupérable via `getArg('eedomus_controller_module_id', false, '0')`, **sans consommer un des 3 VAR**. Très utile pour scoper des données persistantes (saveVariable) par instance de plugin.

## Piège n°8 — Canaux (parent_id) et RAW_XPATH partagé

Doc officielle (https://doc.eedomus.com/view/Capteurs_HTTP) : des périphériques liés par `parent_id` ("canaux") **partagent automatiquement** `[VAR1-3]`, la fréquence de polling, et "Ignorer les erreurs" — **une seule requête HTTP par cycle**, chaque canal lisant SA valeur via SON PROPRE `RAW_XPATH` dans la MÊME réponse. Architecture utilisée ici : le script renvoie un seul document XML (`sdk_header('text/xml')` + `<root><today>X</today><month>Y</month>...</root>`), et chaque canal (today/month/year/lifetime/power) a son propre `RAW_XPATH` (`//today`, `//month`, etc.).

**MAIS** en pratique, il s'est avéré risqué de ne déclarer `VAR1/2/3` + `RAW_URL` + `POLLING` + `ignore_errors` QUE sur le périphérique "maître" (today) en laissant les canaux enfants avec seulement leur `RAW_XPATH` — cela a provoqué un bug où **tous les VAR affichaient vide** à l'édition du périphérique après création (probablement écrasés par la création des canaux suivants qui ne les redéclaraient pas). **Fix appliqué : redéclarer explicitement les mêmes VAR1/VAR2/VAR3/RAW_URL/POLLING/ignore_errors, identiques, sur CHAQUE canal**, seul `RAW_XPATH` changeant. Redondant mais fiable.

Cette architecture (XML multi-tags + canaux à RAW_XPATH partagé) évite d'avoir à faire transiter les codes API des périphériques enfants via une VAR (ce qu'on avait tenté avant, sans succès fiable), et élimine le besoin de `setValue()` dans le script.

## Algorithme de signature APsystems (fonctionnel, ne pas retoucher)

```php
function sdk_aps_build_headers($app_id, $app_secret, $request_path) {
    $timestamp = time() . '000'; // pseudo-millisecondes
    $nonce = md5(uniqid(rand(), true));
    $string_to_sign = $timestamp . '/' . $nonce . '/' . $app_id . '/' . $request_path . '/GET/HmacSHA256';
    $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $app_secret, true));
    $headers = array();
    $headers[] = 'X-CA-AppId: ' . $app_id;
    $headers[] = 'X-CA-Timestamp: ' . $timestamp;
    $headers[] = 'X-CA-Nonce: ' . $nonce;
    $headers[] = 'X-CA-Signature-Method: HmacSHA256';
    $headers[] = 'X-CA-Signature: ' . $signature;
    return $headers;
}
```
`$request_path` = **dernier segment de l'URL uniquement** (ex. pour `/user/api/v2/systems/summary/{sid}`, c'est `{sid}` ; pour `/user/api/v2/systems/{sid}/devices/ecu/energy/{eid}`, c'est `{eid}`).

Endpoints utilisés :
- `GET {base_url}/user/api/v2/systems/summary/{sid}` → `data.today/month/year/lifetime` (kWh)
- `GET {base_url}/user/api/v2/systems/{sid}/devices/ecu/energy/{eid}?energy_level=minutely&date_range=YYYY-MM-DD` → `data.power[]` (dernière valeur = puissance instantanée en W)
- `GET {base_url}/user/api/v2/systems/details/{sid}` → capacité, date création, ECU(s)
- `GET {base_url}/user/api/v2/systems/inverters/{sid}` → liste ECU + onduleurs (UID, modèle)

`base_url` par défaut : `https://api.apsystemsema.com:9282`

Quota API : **1000 appels/mois**. Le polling eedomus tournait historiquement 24h/24 (pas de créneau jour/nuit natif côté eedomus) → voir Piège n°9 pour la coupure de nuit ajoutée depuis, qui change ce calcul.

## Piège n°9 — Coupure de nuit et split resume/puissance (POLLING vs POLLING_POWER)

Constat : aucune API/scheduling eedomus natif pour "ne pas interroger la nuit". En revanche la box eedomus expose un périphérique système **"Soleil Extérieur"** (confirmé présent sur une box réelle), valeurs numériques `0=Couché, 20=Se Couche, 80=Se lève, 100=Levé`. Récupérable gratuitement depuis un script via `getPeriphList()` (cherche `name` contenant "soleil") puis `getValue($periph_id)` (champ `['value']`). On skippe les appels APsystems quand la valeur vaut 0 (nuit franche) ; les 3 autres valeurs comptent comme "jour" (on ne coupe pas les transitions lever/coucher). **Fail-open** : périphérique introuvable ou illisible → on ne coupe jamais, comportement identique à avant.

Second constat : les 5 canaux partageaient un seul intervalle `POLLING` (device "today"), empêchant de rafraîchir la puissance instantanée plus souvent que le résumé (ou l'inverse) sans gaspiller le quota. Fix : `power` n'est plus un canal (`parent_id: "today"`) mais un **périphérique maître indépendant**, avec son propre paramètre `POLLING_POWER`. Les deux maîtres (`today` et `power`) appellent le même script avec un `p4` littéral (`summary` ou `power`, pas une VAR — ça ne consomme donc pas un des 3 slots VAR) pour ne déclencher que l'appel API pertinent à ce cycle. Les valeurs non rafraîchies à ce cycle sont resservies depuis un cache (`saveVariable`/`loadVariable`).

**Piège en cascade** : comme `power` a maintenant son propre `periph_id`, `eedomus_controller_module_id` (piège n°7) n'est plus commun entre `today` et `power` — donc **il ne peut plus servir à indexer le cache** (valeurs, à l'époque aussi l'ECU_ID) partagé entre les deux, sous peine que `power` ne retrouve jamais ce qui a été enregistré via un appel référençant `today`. Toutes les clés `saveVariable` (valeurs cache, périph "Soleil") ont donc été ré-indexées sur **`$sid`** (VAR3, identique sur les deux maîtres pour une même instance du plugin) plutôt que sur `eedomus_controller_module_id`. Ce dernier n'est donc plus utilisé du tout dans `aps_solar.php`.

*(Mise à jour : le mécanisme d'activation manuelle de l'ECU_ID décrit ci-dessus — appel URL avec `&ecu=...`, mémorisation via `saveVariable` — a depuis été entièrement remplacé, voir piège n°11.)*

Conséquence pratique du split en deux périphériques maîtres : comme pour tout changement de `devices`/`parameters` (piège n°3), il faut supprimer et recréer tous les périphériques du plugin après cette mise à jour.

`getPeriphList()`/`getValue()` ne figuraient pas dans la liste de fonctions confirmées du piège n°5 (établie avant ce changement) — **confirmé fonctionnel sur une box réelle** (voir historique de session : `getPeriphList()` est indexé par `device_id`, avec un champ `full_name` — PAS `name`/`periph_id` comme le laissait supposer la doc HTTP API `periph.list` — et `getValue($device_id)` renvoie bien un champ `value`).

## Piège n°11 — ECU_ID combiné dans VAR3 (`SID|ECU_ID`), plus d'activation manuelle

L'activation manuelle de l'ECU_ID (piège n°9, appel URL avec `&ecu=...`) fonctionnait mais restait une étape UX peu naturelle. Remplacée par une astuce plus simple : l'utilisateur saisit directement `SID|ECU_ID` (au lieu de juste `SID`) dans le champ `SID` du formulaire — `ECU_ID` restant facultatif (juste `SID` fonctionne toujours). Le script fait `explode('|', $p3)` pour séparer les deux.

**Pourquoi ça ne retombe PAS dans le piège n°6** (qui interdit de concaténer plusieurs tags `plugin.parameters.X` dans un seul VAR) : ici il n'y a toujours qu'**un seul tag** `plugin.parameters.SID` à substituer dans `VAR3` — eedomus fait une substitution simple et fiable, exactement comme pour `APP_ID`/`APP_SECRET`/`SID` individuellement. La valeur combinée `SID|ECU_ID` est composée par l'UTILISATEUR en tapant dans le champ, pas par le moteur de templating d'eedomus qui essaierait de fusionner deux tags — c'est cette dernière opération (côté eedomus) qui était non fiable, pas le fait qu'un VAR porte une chaîne composite.

Conséquences :
- Suppression du paramètre `ECU_ID` du manifeste (n'existe plus, plus de champ dédié).
- Suppression de tout le mécanisme `saveVariable`/`loadVariable` pour l'ECU_ID dans `aps_solar.php` (plus besoin : transmis à chaque requête comme APP_ID/APP_SECRET/SID).
- Suppression de la section "Activer la puissance instantanée" du README (plus d'étape manuelle).
- Comme pour tout changement de `parameters` (piège n°3), nécessite de supprimer/recréer les périphériques après mise à jour.

Confirmé sur box réelle (log `http_sensor`) : `p3=SID|ECU_ID` arrive bien intact et non tronqué dans l'URL (le `|` n'est pas encodé mais eedomus/PHP le transmettent tel quel sans souci).

## Piège n°12 — `loadVariable()` sur une variable jamais sauvegardée : ne pas tester `=== false`

Constat (log réel, device "power", quota API déjà dépassé donc l'appel échoue) : eedomus loggait `Valeur lue vide []` pour `//power`, alors que le code prévoyait un fallback `-1` en cas d'échec. Cause probable : `$power_value = loadVariable($power_cache_name); if ($power_value === false) { $power_value = -1; }` — la comparaison **stricte** suppose que `loadVariable()` renvoie exactement le booléen `false` quand la variable n'a jamais été sauvegardée. Si en pratique elle renvoie autre chose (`''`, `NULL`...) dans ce cas précis, le fallback ne se déclenche jamais et une chaîne vide se retrouve dans le XML (`<power></power>`), qu'eedomus lit comme "valeur vide" plutôt que comme un nombre.

Le bug était invisible pour `today`/`month`/`year`/`lifetime` car leur maître (`today`, toujours en mode `summary`) écrase explicitement la valeur par un code d'erreur négatif en cas d'échec API (`$error_code = 0 - $summary_json['code']`), donc le résultat de `loadVariable()` n'est jamais exposé tel quel dans ce cas. `power` n'a pas cette branche `else` et le quota déjà dépassé a exposé le problème.

**Fix** : remplacer `===` par `==` (comparaison large) dans tous les checks de fallback de cache. `false == ''` et `false == NULL` sont vrais en PHP, donc ça absorbe l'incertitude sur la valeur exacte renvoyée par `loadVariable()` pour une variable jamais définie, quelle qu'elle soit, sans risque : une vraie valeur de production/puissance n'est jamais `false`/`''`/`NULL`.

## État actuel du projet

- Plugin en production sur une box réelle depuis plusieurs jours (pas encore publié publiquement sur le Store, juste en mode Privé).
- Manifeste `"scripts"` (pluriel), architecture canaux + XML partagé : confirmés fonctionnels.
- Coupure de nuit (`sdk_is_night()`) : **confirmée fonctionnelle sur une box réelle** — trouve bien le périphérique "Soleil Extérieur" (`device_id` variable selon la box), lit sa `value`, et met en cache le `device_id` trouvé pour éviter de rescanner `getPeriphList()` à chaque appel.
- Split `today`/`power` en deux périphériques maîtres indépendants avec `POLLING`/`POLLING_POWER` séparés : déployé.
- ECU_ID combiné dans le champ `SID` (`SID|ECU_ID`, Piège n°11) : implémenté, cohérent avec le fonctionnement déjà confirmé des autres VAR à tag unique, mais **pas encore explicitement revérifié sur une box réelle** avec un vrai ECU_ID (contrairement à la coupure de nuit, qui l'a été via des scripts de debug dédiés).
- `aps_discover.php` retiré du zip distribué (conservé dans le repo pour référence).
- Repo publié sur GitHub (`henryju/apsystems-eedomus`, public), avec LICENSE (GPL-3.0), CI de build, et doc utilisateur (`readme_fr.md`/`readme_en.md`) affichée dans l'aide en ligne d'eedomus.

## Prochaine étape suggérée

Confirmer sur une box réelle que `SID|ECU_ID` fonctionne bien de bout en bout (le champ `power` remonte une vraie valeur, pas -1). Suivre la consommation réelle du quota API sur un mois complet avec les nouveaux défauts (`POLLING=180`, `POLLING_POWER=30`, coupure de nuit) pour vérifier qu'elle correspond à l'estimation documentée dans les readmes (~840 appels/mois).
