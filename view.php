<?php

require_once('./include.php'); // provides $db, $translations
require_once('./include/functions.php');

header('Content-Type: text/plain; charset=UTF-8');

execute($get_results);
$resultrows = result($get_results);

foreach($resultrows as $row) {
    $date = $row['date'];
    $token = $row['token'];
    $form = $row['form'];

    echo "$form - $token - $date\n";
    $get_answers->bind_param('s', $token);
    if(!$get_answers->execute()) {
        echo "couldn't get result";
        die;
    }
    $data_rows = result($get_answers);
    foreach($data_rows as $data) {
        $question = replace(array(
            $glue  => '; ',
            $space => ' '
        ), $data['question']);

        $answer = replace(array(
            $glue  => '; ',
            $space => ' '
        ), $data['answer']);

        echo "\t$question: $answer\n";
    }
    echo "\n";
}
