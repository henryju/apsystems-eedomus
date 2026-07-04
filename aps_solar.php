<?php
/*
  aps_solar.php
  Recupere les donnees de production solaire depuis l'API APsystems OpenAPI
  et les restitue en XML, une balise par valeur. Les peripheriques "month",
  "year", "lifetime" et "power" sont des CANAUX rattaches a "today" : ils
  partagent automatiquement la meme requete HTTP (une seule requete par
  cycle) et n'utilisent que leur propre RAW_XPATH pour lire leur valeur
  dans CETTE MEME reponse.

  Important : la concatenation de plusieurs tags "plugin.parameters.X"
  dans un seul champ VARn s'est averee non fiable a l'usage (0 ou quelques
  substitutions sur plusieurs tentatives). VAR1/VAR2/VAR3 ne portent donc
  chacun qu'UN SEUL tag, toujours non vide (APP_ID, APP_SECRET, SID).

  L'ECU_ID (facultatif, potentiellement vide) n'est PAS transmis via une
  VAR a chaque requete. Il est memorise une fois pour toutes avec
  saveVariable(), en l'associant au periph_id appelant (recu gratuitement
  via eedomus_controller_module_id). Pour l'activer/le changer, faites un
  appel manuel du type :

  http://VOTRE_BOX/script/?exec=aps_solar.php&p1=VOTRE_APP_ID&p2=VOTRE_APP_SECRET&p3=VOTRE_SID&ecu=VOTRE_ECU_ID&eedomus_controller_module_id=CODE_API_DU_PERIPH_TODAY

  (Le code API du peripherique "today" est visible dans ses parametres
  expert, ou vous pouvez simplement laisser eedomus_controller_module_id
  a 0 si vous n'avez qu'une seule instance du plugin.)
*/

$p1 = getArg('p1');
$p2 = getArg('p2');
$p3 = getArg('p3');

$app_id     = $p1;
$app_secret = $p2;
$sid        = $p3;

$base_url = 'https://api.apsystemsema.com:9282';

/* ---- Gestion de l'ECU_ID (persistant, facultatif) ---- */

$controller_id = getArg('eedomus_controller_module_id', false, '0');
$ecu_var_name = 'APS_ECU_' . $controller_id;

$ecu_arg = getArg('ecu', false, '');

if ($ecu_arg != '') {
    saveVariable($ecu_var_name, $ecu_arg);
    $ecu = $ecu_arg;
} else {
    $ecu = loadVariable($ecu_var_name);
}
if ($ecu === false) {
    $ecu = '';
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

/* Valeurs par defaut (utilisees en cas d'erreur, toujours negatives car
   une production ou une puissance reelle ne peut pas etre negative) */
$today_value    = -1;
$month_value    = -1;
$year_value     = -1;
$lifetime_value = -1;
$power_value    = -1;

/* ---- 1) Appel de l'API "summary" (today / month / year / lifetime) ---- */

if ($app_id != '' && $app_secret != '' && $sid != '') {

    $summary_path = $sid; /* dernier segment de /user/api/v2/systems/summary/{sid} */
    $summary_headers = sdk_aps_build_headers($app_id, $app_secret, $summary_path);
    $summary_url = $base_url . '/user/api/v2/systems/summary/' . $sid;

    $summary_raw = httpQuery($summary_url, 'GET', NULL, NULL, $summary_headers);
    $summary_json = sdk_json_decode($summary_raw);

    if (isset($summary_json['code']) && $summary_json['code'] == 0 && isset($summary_json['data'])) {

        $data = $summary_json['data'];

        if (isset($data['today'])) {
            $today_value = $data['today'];
        }
        if (isset($data['month'])) {
            $month_value = $data['month'];
        }
        if (isset($data['year'])) {
            $year_value = $data['year'];
        }
        if (isset($data['lifetime'])) {
            $lifetime_value = $data['lifetime'];
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

    /* ---- 2) Puissance instantanee (facultatif, necessite l'ECU) ---- */

    if ($ecu != '') {

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
            }
        }
    }
}

/* ---- Sortie XML, une balise par canal ----
   today -> //today, month -> //month, year -> //year,
   lifetime -> //lifetime, power -> //power
   Une seule requete API est faite par cycle : tous les canaux partagent
   cette meme reponse (mecanisme natif des "canaux" eedomus). */
sdk_header('text/xml');
echo '<root>';
echo '<today>' . $today_value . '</today>';
echo '<month>' . $month_value . '</month>';
echo '<year>' . $year_value . '</year>';
echo '<lifetime>' . $lifetime_value . '</lifetime>';
echo '<power>' . $power_value . '</power>';
echo '</root>';
?>
