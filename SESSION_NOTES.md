# Notes de session — Plugin eedomus APsystems (apsolar)

Ce document résume tout ce qui a été appris en développant ce plugin, pour éviter de refaire les mêmes erreurs. À donner en contexte à Claude Code en début de session.

## Contexte du projet

Plugin eedomus (box domotique française, https://doc.eedomus.com) qui récupère la production de panneaux solaires APsystems via leur API OpenAPI (doc PDF fournie par APsystems, HMAC-SHA256 signée), inspiré de https://github.com/emlynmac/apsystems-openapi.

Fichiers du projet :
- `eedomus_plugin.json` — manifeste du plugin (périphériques, paramètres)
- `aps_solar.php` — script principal, appelé en continu par le capteur HTTP "today"
- `aps_discover.php` — script utilitaire, exécution manuelle unique, pour retrouver ECU_ID et UID des onduleurs
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

Quota API : **1000 appels/mois**. Le polling eedomus tourne 24h/24 (pas de créneau jour/nuit natif) → calculer sur une base 24h, pas sur les heures d'ensoleillement.

## État actuel du projet (dernière version fonctionnelle testée)

- Manifeste corrigé avec `"scripts"` (pluriel, objets `{name:...}`) — **pas encore retesté après ce dernier fix** au moment de la rédaction de ce document.
- Architecture canaux + XML partagé fonctionnelle (confirmé par les logs : `p1/p2/p3` bien renseignés, seul le "Script introuvable" bloquait).
- ECU_ID à activer manuellement une fois via l'URL documentée dans le README.

## Prochaine étape suggérée

Une fois le fix "scripts" (pluriel) validé par un test réel sur la box, envisager de packager tout ça proprement (repo git, versionnement du zip) pour faciliter les futures itérations sans dépendre uniquement du chat.
