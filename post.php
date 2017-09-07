<?php
require_once('./include.php'); // provides $db, $translations
require_once('./include/functions.php');

header('Content-Type: text/plain; charset=UTF-8');

if(!isset($_POST['form-token'])) {
    error('Missing token');
} else {
    save_results();
}
header('Location: '.$_SERVER['HTTP_REFERER']);

?>
