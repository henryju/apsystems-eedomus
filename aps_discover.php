<?php
/*
  aps_discover.php
  Script utilitaire (a lancer manuellement) pour retrouver l'identifiant
  de votre/vos ECU et de vos onduleurs, a partir de votre SID.

  Utilisation : depuis un navigateur ou la page de test de script d'eedomus,
  executer avec les arguments : app_id, app_secret, sid, (et base_url, lang
  si besoin).

  Exemple :
  http://[ip_de_votre_box]/script/?exec=aps_discover.php&app_id=XXX&app_secret=YYY&sid=ZZZ&lang=fr
*/

$app_id     = getArg('app_id');
$app_secret = getArg('app_secret');
$sid        = getArg('sid');
$base_url   = getArg('base_url', false, 'https://api.apsystemsema.com:9282');
$lang       = getArg('lang', false, 'fr');

if (substr($base_url, -1) == '/') {
    $base_url = substr($base_url, 0, strlen($base_url) - 1);
}

/* ---- Traductions ---- */

$i18n = array();

$i18n['fr'] = array(
    'page_title'      => 'Decouverte APsystems',
    'system_title'    => 'Systeme',
    'field_sid'       => 'SID',
    'field_capacity'  => 'Puissance installee',
    'unit_kw'         => 'kW',
    'field_created'   => 'Date de creation EMA',
    'field_status'    => 'Etat',
    'status_1'        => 'Vert (OK)',
    'status_2'        => 'Jaune (avertissement)',
    'status_3'        => 'Rouge (erreur)',
    'status_4'        => 'Gris (inconnu / hors ligne)',
    'status_x'        => 'Non communique',
    'field_ecu_id'    => 'ECU_ID a utiliser dans le plugin',
    'copy_hint'       => 'Copiez cette valeur dans le parametre ECU_ID du plugin eedomus.',
    'inverters_title' => 'Onduleurs',
    'ecu_label'       => 'ECU',
    'ecu_type_0'      => 'ECU seul',
    'ecu_type_1'      => 'ECU + compteur (meter)',
    'ecu_type_2'      => 'ECU + stockage (storage)',
    'ecu_type_x'      => 'Type inconnu',
    'th_uid'          => 'UID onduleur',
    'th_model'        => 'Modele',
    'error_details'   => 'Erreur lors de la recuperation des details du systeme (code %s).',
    'error_inverters' => 'Erreur lors de la recuperation des onduleurs (code %s).',
    'error_unknown'   => 'inconnu',
    'no_data'         => 'Aucune donnee retournee.',
    'footer_hint'     => 'Ce script peut etre supprime une fois la configuration terminee.'
);

$i18n['en'] = array(
    'page_title'      => 'APsystems discovery',
    'system_title'    => 'System',
    'field_sid'       => 'SID',
    'field_capacity'  => 'Installed capacity',
    'unit_kw'         => 'kW',
    'field_created'   => 'EMA creation date',
    'field_status'    => 'Status',
    'status_1'        => 'Green (OK)',
    'status_2'        => 'Yellow (warning)',
    'status_3'        => 'Red (error)',
    'status_4'        => 'Gray (unknown / offline)',
    'status_x'        => 'Not reported',
    'field_ecu_id'    => 'ECU_ID to use in the plugin',
    'copy_hint'       => 'Copy this value into the ECU_ID parameter of the eedomus plugin.',
    'inverters_title' => 'Inverters',
    'ecu_label'       => 'ECU',
    'ecu_type_0'      => 'ECU only',
    'ecu_type_1'      => 'ECU + meter',
    'ecu_type_2'      => 'ECU + storage',
    'ecu_type_x'      => 'Unknown type',
    'th_uid'          => 'Inverter UID',
    'th_model'        => 'Model',
    'error_details'   => 'Error fetching system details (code %s).',
    'error_inverters' => 'Error fetching inverters (code %s).',
    'error_unknown'   => 'unknown',
    'no_data'         => 'No data returned.',
    'footer_hint'     => 'You can delete this script once setup is complete.'
);

if (!isset($i18n[$lang])) {
    $lang = 'fr';
}
$t = $i18n[$lang];

/*
  Calcule les en-tetes de signature APsystems.
  $request_path doit correspondre au DERNIER segment de l'URL appelee.
*/
function sdk_aps_build_headers($app_id, $app_secret, $request_path) {
    $timestamp = time() . '000';
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

/* ---- Petit helper d'echappement HTML ---- */
function sdk_aps_h($value) {
    return htmlspecialchars($value);
}

/* ---- CSS ---- */
$css = '';
$css .= 'body{font-family:Arial,Helvetica,sans-serif;background:#f4f5f7;padding:20px;color:#222;}';
$css .= '.card{background:#ffffff;border-radius:8px;padding:16px 24px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,0.15);max-width:700px;}';
$css .= 'h1{font-size:20px;margin-bottom:4px;}';
$css .= 'h2{font-size:16px;color:#2c7a4b;margin-top:0;border-bottom:1px solid #eee;padding-bottom:6px;}';
$css .= 'table{border-collapse:collapse;width:100%;margin-top:8px;}';
$css .= 'td,th{padding:6px 10px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}';
$css .= 'th{color:#555;font-weight:600;background:#fafafa;}';
$css .= '.highlight{background:#eaf7ef;font-weight:bold;font-family:monospace;font-size:15px;}';
$css .= '.hint{font-size:12px;color:#777;margin-top:4px;}';
$css .= '.error{color:#b00020;font-weight:bold;padding:8px 0;}';
$css .= '.footer{font-size:12px;color:#999;margin-top:10px;}';

$output = '<html><head><meta charset="ISO-8859-1"><style>' . $css . '</style></head><body>';
$output .= '<h1>' . sdk_aps_h($t['page_title']) . '</h1>';

/* ---- 1) Details du systeme ---- */
$details_headers = sdk_aps_build_headers($app_id, $app_secret, $sid);
$details_url = $base_url . '/user/api/v2/systems/details/' . $sid;
$details_raw = httpQuery($details_url, 'GET', NULL, NULL, $details_headers);
$details_json = sdk_json_decode($details_raw);

$output .= '<div class="card">';
$output .= '<h2>' . sdk_aps_h($t['system_title']) . '</h2>';

if (isset($details_json['code']) && $details_json['code'] == 0 && isset($details_json['data'])) {

    $d = $details_json['data'];

    $output .= '<table>';
    $output .= '<tr><th>' . sdk_aps_h($t['field_sid']) . '</th><td>' . sdk_aps_h($sid) . '</td></tr>';

    if (isset($d['capacity'])) {
        $output .= '<tr><th>' . sdk_aps_h($t['field_capacity']) . '</th><td>' . sdk_aps_h($d['capacity']) . ' ' . sdk_aps_h($t['unit_kw']) . '</td></tr>';
    }
    if (isset($d['create_date'])) {
        $output .= '<tr><th>' . sdk_aps_h($t['field_created']) . '</th><td>' . sdk_aps_h($d['create_date']) . '</td></tr>';
    }
    if (isset($d['light'])) {
        $status_key = 'status_x';
        if ($d['light'] == 1) { $status_key = 'status_1'; }
        if ($d['light'] == 2) { $status_key = 'status_2'; }
        if ($d['light'] == 3) { $status_key = 'status_3'; }
        if ($d['light'] == 4) { $status_key = 'status_4'; }
        $output .= '<tr><th>' . sdk_aps_h($t['field_status']) . '</th><td>' . sdk_aps_h($t[$status_key]) . '</td></tr>';
    }

    if (isset($d['ecu']) && gettype($d['ecu']) == 'array') {
        $ecu_count = count($d['ecu']);
        $ecu_list = '';
        for ($k = 0; $k < $ecu_count; $k++) {
            if ($k > 0) {
                $ecu_list = $ecu_list . ', ';
            }
            $ecu_list = $ecu_list . $d['ecu'][$k];
        }
        $output .= '<tr class="highlight"><th>' . sdk_aps_h($t['field_ecu_id']) . '</th><td>' . sdk_aps_h($ecu_list) . '</td></tr>';
    }

    $output .= '</table>';
    $output .= '<div class="hint">' . sdk_aps_h($t['copy_hint']) . '</div>';

} else {
    $error_code = $t['error_unknown'];
    if (isset($details_json['code'])) {
        $error_code = $details_json['code'];
    }
    $output .= '<div class="error">' . sdk_aps_h(sprintf($t['error_details'], $error_code)) . '</div>';
}

$output .= '</div>';

/* ---- 2) Onduleurs par ECU ---- */
$inv_headers = sdk_aps_build_headers($app_id, $app_secret, $sid);
$inv_url = $base_url . '/user/api/v2/systems/inverters/' . $sid;
$inv_raw = httpQuery($inv_url, 'GET', NULL, NULL, $inv_headers);
$inv_json = sdk_json_decode($inv_raw);

$output .= '<div class="card">';
$output .= '<h2>' . sdk_aps_h($t['inverters_title']) . '</h2>';

if (isset($inv_json['code']) && $inv_json['code'] == 0 && isset($inv_json['data']) && gettype($inv_json['data']) == 'array') {

    $ecus = $inv_json['data'];
    $ecu_count = count($ecus);

    if ($ecu_count == 0) {
        $output .= '<div class="hint">' . sdk_aps_h($t['no_data']) . '</div>';
    }

    for ($i = 0; $i < $ecu_count; $i++) {

        $ecu_item = $ecus[$i];

        $ecu_id = '?';
        if (isset($ecu_item['eid'])) {
            $ecu_id = $ecu_item['eid'];
        }

        $ecu_type_key = 'ecu_type_x';
        if (isset($ecu_item['type'])) {
            if ($ecu_item['type'] == 0) { $ecu_type_key = 'ecu_type_0'; }
            if ($ecu_item['type'] == 1) { $ecu_type_key = 'ecu_type_1'; }
            if ($ecu_item['type'] == 2) { $ecu_type_key = 'ecu_type_2'; }
        }

        $output .= '<h3>' . sdk_aps_h($t['ecu_label']) . ' ' . sdk_aps_h($ecu_id) . ' &mdash; ' . sdk_aps_h($t[$ecu_type_key]) . '</h3>';

        if (isset($ecu_item['inverter']) && gettype($ecu_item['inverter']) == 'array') {

            $inverters = $ecu_item['inverter'];
            $inv_count = count($inverters);

            $output .= '<table>';
            $output .= '<tr><th>' . sdk_aps_h($t['th_uid']) . '</th><th>' . sdk_aps_h($t['th_model']) . '</th></tr>';

            for ($j = 0; $j < $inv_count; $j++) {
                $uid = '?';
                if (isset($inverters[$j]['uid'])) {
                    $uid = $inverters[$j]['uid'];
                }
                $model = '?';
                if (isset($inverters[$j]['type'])) {
                    $model = $inverters[$j]['type'];
                }
                $output .= '<tr><td>' . sdk_aps_h($uid) . '</td><td>' . sdk_aps_h($model) . '</td></tr>';
            }

            $output .= '</table>';
        }
    }

} else {
    $error_code = $t['error_unknown'];
    if (isset($inv_json['code'])) {
        $error_code = $inv_json['code'];
    }
    $output .= '<div class="error">' . sdk_aps_h(sprintf($t['error_inverters'], $error_code)) . '</div>';
}

$output .= '</div>';

$output .= '<div class="footer">' . sdk_aps_h($t['footer_hint']) . '</div>';
$output .= '</body></html>';

echo $output;
?>
