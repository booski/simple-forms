<?php

require_once('./include.php'); // provides $db, $translations
require_once('./include/functions.php');

$date_cutoff = '';
if(isset($_GET['datum']) && $_GET['datum']) {
    $date_cutoff = $_GET['datum'];
    $date_valid = preg_split('%[-/. ]+%', trim($date_cutoff));
    if(!checkdate($date_valid[1], $date_valid[2], $date_valid[0])) {
        error('Ogiltigt datum');
        header('Location: '.$_SERVER['HTTP_REFERER']);
        die;
    }
    $date_cutoff = strtotime($date_cutoff);
}

$patient_id = '';
if(isset($_GET['patientid'])) {
    $patient_id = $_GET['patientid'];
}

$form_id = '';
if(isset($_GET['formid'])) {
    $form_id = $_GET['formid'];
}


$result = build_results($date_cutoff, $patient_id, $form_id);
if(isset($_GET['download'])) {
    header('Content-Type: text/tsv; charset=UTF-8');
    header('Content-disposition: attachment; filename=data.tsv');
} else {
    header('Content-Type: text/plain; charset=UTF-8');
}
echo $result;
?>
