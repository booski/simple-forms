<?php
require_once('./include.php'); // provides $db, $translations
require_once('./include/functions.php');

header('Content-Type: text/plain; charset=UTF-8');

if(!isset($_POST['form-token'])) {
    die;
}

$form = $_POST['form-id'];
$token = $_POST['form-token'];
$date = time();

$find_token->bind_param('s', $token);
execute($find_token);

if(count(result($find_token)) != 0) {
    echo "this result has already been saved";
    die;
}

begin_trans();
$save_result->bind_param('ssi', $form, $token, $date);
if(!$save_result->execute()) {
    revert_trans();
    echo "failed to save result: $form - $token - $date\n";
    echo "$save_result->errno: $save_result->error";
    die;
}

$ignore = array('form-token', 'form-id');
foreach($_POST as $question => $answer) {
    if(in_array($question, $ignore, TRUE)) {
        continue;
    }
    if(is_array($answer)) {
        $answer = implode($glue, $answer);
    }
    $save_answer->bind_param('sss', $token, $question, $answer);
    if(!$save_answer->execute()) {
        revert_trans();
        echo "failed to save answer: $question - $answer";
        die;
    }
}
commit_trans();
echo "results saved";

?>
