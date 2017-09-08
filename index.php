<?php
require_once('./include.php'); // provides $db, $translations
require_once('./include/functions.php');

header('Content-Type: text/html; charset=UTF-8');

$base = replace($translations['sv'], file_get_contents('./template.html'));

$content = '';
if(!isset($_GET['id'])) {
    $content = build_index();
} else {
    $form = $_GET['id'];
    $content = build_form(parse($form), $form);
}

print replace(array(
    '¤pagetitle' => "Formulär",
    '¤contents'  => $content,
), $base);

?>
