<?php
/*
  aps_solar.php
  Recupere les donnees de production solaire depuis l'API APsystems OpenAPI
  et les restitue en XML, une balise par valeur. Les peripheriques "month",
  "year" et "lifetime" sont des CANAUX rattaches a "today" : ils partagent
  automatiquement la meme requete HTTP (une seule requete "resume" par
  cycle) et n'utilisent que leur propre RAW_XPATH pour lire leur valeur
  dans CETTE MEME reponse. "power" est desormais un peripherique MAITRE
  independant, avec son propre intervalle d'interrogation (POLLING_POWER),
  afin de pouvoir rafraichir la puissance instantanee plus souvent que le
  resume (aujourd'hui/mois/annee/vie), sans consommer inutilement le quota
  API pour ce dernier.

  Important : la concatenation de plusieurs tags "plugin.parameters.X"
  dans un seul champ VARn s'est averee non fiable a l'usage (0 ou quelques
  substitutions sur plusieurs tentatives). VAR1/VAR2/VAR3 ne portent donc
  chacun qu'UN SEUL tag, toujours non vide (APP_ID, APP_SECRET, SID).

  Le parametre p4 (texte fixe dans le RAW_URL, PAS une VAR) indique quel(s)
  appel(s) faire : "summary" (aujourd'hui/mois/annee/vie), "power"
  (puissance instantanee) ou "both" (les deux, comportement historique,
  utilise par defaut pour un appel manuel sans p4).

  L'ECU_ID (facultatif) n'a pas de VAR dediee (seuls VAR1/VAR2/VAR3
  existent). Il se glisse dans VAR3 a la suite du SID, separes par un
  "|" : le champ "SID" du formulaire accepte donc soit "MON_SID" seul,
  soit "MON_SID|MON_ECU_ID". C'est fiable car eedomus n'a qu'UN SEUL
  tag "plugin.parameters.SID" a substituer dans ce VAR (contrairement
  au piege des VAR portant plusieurs tags "plugin.parameters.X", voir
  SESSION_NOTES.md) ; c'est ce script qui fait le split sur "|", pas
  eedomus. Plus besoin d'activation manuelle : l'ECU_ID est transmis a
  chaque requete, comme APP_ID/APP_SECRET/SID.

  Coupure de nuit : le script verifie l'etat du peripherique systeme
  "Soleil Exterieur" (present sur la box eedomus, valeurs 0=Couche,
  20=Se Couche, 80=Se leve, 100=Leve). Quand ce peripherique vaut 0
  (nuit), aucun appel n'est fait a l'API APsystems : les dernieres
  valeurs connues (memorisees via saveVariable) sont simplement
  renvoyees telles quelles. Si ce peripherique est introuvable ou sa
  valeur illisible, le script se comporte comme en plein jour (ne coupe
  rien), pour ne jamais casser une installation qui ne l'aurait pas.
*/

$p1 = getArg('p1');
$p2 = getArg('p2');
$p3 = getArg('p3');
$p4 = getArg('p4', false, 'both');

$app_id     = $p1;
$app_secret = $p2;
$mode       = $p4;

$base_url = 'https://api.apsystemsema.com:9282';

/* ---- SID et ECU_ID (facultatif), combines dans VAR3 sous la forme
   "SID" ou "SID|ECU_ID" (voir commentaire d'en-tete) ---- */

$sid_ecu = explode('|', $p3);

$sid = $sid_ecu[0];

$ecu = '';
if (count($sid_ecu) > 1) {
    $ecu = $sid_ecu[1];
}

/*
  Calcule les en-tetes de signature APsystems pour un chemin d'API donne.
  $request_path doit correspondre au DERNIER segment de l'URL appelee
  (c'est ainsi que l'algorithme de signature APsystems est defini).
*/
function sdk_aps_build_headers($app_id, $app_secret, $request_path) {
    $timestamp = time() . '000'; /* pseudo-millisecondes */
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

/*
  Recherche le peripherique systeme "Soleil Exterieur" (unique sur la
  box, partage par toutes les instances du plugin) et retourne true si
  sa valeur courante est 0 (nuit, "Couche"). Retourne false (jour) si
  le peripherique est introuvable, illisible, ou si sa valeur n'est pas
  0 (transition ou plein jour) : on ne coupe QUE la nuit franche.
*/
function sdk_is_night() {

    $sun_var_name = 'APS_SUN_PERIPH_ID';

    $sun_periph_id = loadVariable($sun_var_name);
    if ($sun_periph_id == false) {
        $sun_periph_id = '';
    }

    if ($sun_periph_id == '') {
        $periphs = getPeriphList();
        if (gettype($periphs) == 'array') {
            foreach ($periphs as $device_id => $p) {
                if (isset($p['full_name'])) {
                    $lower_name = strtolower($p['full_name']);
                    if (strpos($lower_name, 'soleil') !== false) {
                        $sun_periph_id = $device_id;
                    }
                }
            }
        }
        if ($sun_periph_id != '') {
            saveVariable($sun_var_name, $sun_periph_id);
        }
    }

    if ($sun_periph_id == '') {
        return false;
    }

    $sun_value = getValue($sun_periph_id);
    if (gettype($sun_value) != 'array' || !isset($sun_value['value'])) {
        return false;
    }

    if ($sun_value['value'] == 0) {
        return true;
    }
    return false;
}

/* ---- Valeurs par defaut : dernieres valeurs connues (cache), sinon -1
   (une production ou une puissance reelle ne peut pas etre negative) ---- */

$today_cache_name    = 'APS_TODAY_' . $sid;
$month_cache_name    = 'APS_MONTH_' . $sid;
$year_cache_name     = 'APS_YEAR_' . $sid;
$lifetime_cache_name = 'APS_LIFETIME_' . $sid;
$power_cache_name    = 'APS_POWER_' . $sid;

$today_value    = loadVariable($today_cache_name);
$month_value    = loadVariable($month_cache_name);
$year_value     = loadVariable($year_cache_name);
$lifetime_value = loadVariable($lifetime_cache_name);
$power_value    = loadVariable($power_cache_name);

if ($today_value == false) {
    $today_value = -1;
}
if ($month_value == false) {
    $month_value = -1;
}
if ($year_value == false) {
    $year_value = -1;
}
if ($lifetime_value == false) {
    $lifetime_value = -1;
}
if ($power_value == false) {
    $power_value = -1;
}

$do_summary = ($mode == 'summary' || $mode == 'both');
$do_power   = ($mode == 'power' || $mode == 'both');

if ($app_id != '' && $app_secret != '' && $sid != '' && !sdk_is_night()) {

    /* ---- 1) Appel de l'API "summary" (today / month / year / lifetime) ---- */

    if ($do_summary) {

        $summary_path = $sid; /* dernier segment de /user/api/v2/systems/summary/{sid} */
        $summary_headers = sdk_aps_build_headers($app_id, $app_secret, $summary_path);
        $summary_url = $base_url . '/user/api/v2/systems/summary/' . $sid;

        $summary_raw = httpQuery($summary_url, 'GET', NULL, NULL, $summary_headers);
        $summary_json = sdk_json_decode($summary_raw);

        if (isset($summary_json['code']) && $summary_json['code'] == 0 && isset($summary_json['data'])) {

            $data = $summary_json['data'];

            if (isset($data['today'])) {
                $today_value = $data['today'];
                saveVariable($today_cache_name, $today_value);
            }
            if (isset($data['month'])) {
                $month_value = $data['month'];
                saveVariable($month_cache_name, $month_value);
            }
            if (isset($data['year'])) {
                $year_value = $data['year'];
                saveVariable($year_cache_name, $year_value);
            }
            if (isset($data['lifetime'])) {
                $lifetime_value = $data['lifetime'];
                saveVariable($lifetime_cache_name, $lifetime_value);
            }
        } else {
            if (isset($summary_json['code'])) {
                $error_code = 0 - $summary_json['code'];
                $today_value    = $error_code;
                $month_value    = $error_code;
                $year_value     = $error_code;
                $lifetime_value = $error_code;
            }
        }
    }

    /* ---- 2) Puissance instantanee (facultatif, necessite l'ECU) ---- */

    if ($do_power && $ecu != '') {

        $power_path = $ecu; /* dernier segment de /user/api/v2/systems/{sid}/devices/ecu/energy/{eid} */
        $power_headers = sdk_aps_build_headers($app_id, $app_secret, $power_path);

        $today_date = date('Y-m-d');
        $power_url = $base_url . '/user/api/v2/systems/' . $sid . '/devices/ecu/energy/' . $ecu
                   . '?energy_level=minutely&date_range=' . $today_date;

        $power_raw = httpQuery($power_url, 'GET', NULL, NULL, $power_headers);
        $power_json = sdk_json_decode($power_raw);

        if (isset($power_json['code']) && $power_json['code'] == 0
            && isset($power_json['data']) && isset($power_json['data']['power'])) {

            $powers = $power_json['data']['power'];
            $count = count($powers);

            if ($count > 0) {
                $power_value = $powers[$count - 1];
                saveVariable($power_cache_name, $power_value);
            }
        }
    }
}

/* ---- Sortie XML, une balise par canal ----
   today -> //today, month -> //month, year -> //year,
   lifetime -> //lifetime, power -> //power
   Chaque peripherique maitre (resume "today", ou "power") ne declenche
   que l'appel API qui le concerne (voir p4/mode ci-dessus) ; les valeurs
   non rafraichies a ce cycle sont les dernieres connues (cache). */
sdk_header('text/xml');
echo '<root>';
echo '<today>' . $today_value . '</today>';
echo '<month>' . $month_value . '</month>';
echo '<year>' . $year_value . '</year>';
echo '<lifetime>' . $lifetime_value . '</lifetime>';
echo '<power>' . $power_value . '</power>';
echo '</root>';
?>
