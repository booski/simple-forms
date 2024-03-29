<?php

$fragments = get_fragments('./include/forms.html');
$styles    = get_fragments('./include/styles.html');
$results   = get_fragments('./include/answer.html');

$save_result = prepare('insert into `result`(`form`, `token`, `date`) values (?, ?, ?)');
$get_results = prepare('select * from `result` where `date`>? and `form` like ?');
$save_answer = prepare('insert into `data`(`token`, `question`, `answer`) values (?, ?, ?)');
$find_token  = prepare('select `token` from `result` where `token`=?');

$glue  = "\x1E"; //ascii record separator
$space = "\x1F"; //ascii unit separator
$question_replacements = array(
    $glue  => ' -> ',
    $space => ' '
);
$answer_replacements = array(
    $glue  => '; ',
    $space => ' ',
    "\n" => ' ',
    "\r" => '',
);

function authenticate($admins) {
    $user = $_SERVER['REMOTE_USER'];
    $callback = urlencode($_SERVER['SCRIPT_URI'].'?'.$_SERVER['QUERY_STRING']);
    if(!$user) {
        header('Location: /Shibboleth.sso/Login/SU?target='.$callback);
    }
    if($admins && !in_array($user, $admins)) {
        die('Unauthorized.');
    }
}

function build_index() {
    $patterns = array('%\./templates/%', '/\.form$/');
    $link = '<h3><a href="?id=¤id">¤title</a></h3>';

    $out = '<a href="?result" id="resultlink">Hämta resultat</a>';
    $out .= "<h1>Välj ett formulär</h1>";

    $forms = glob('./templates/*.form');
    natsort($forms);
    foreach($forms as $form) {

        $formid = preg_replace($patterns, '', $form);
        $out .= replace(array(
            '¤id'    => $formid,
            '¤title' => get_title($form)
        ), $link);
    }

    return $out;
}

function get_title($formfile) {
    $preg = '/^\s*#+\s*(.+)$/';
    foreach(file($formfile) as $line) {
        $result = array();
        preg_match($preg, $line, $result);
        if(array_key_exists(1, $result)) {
            return $result[1];
        }
    }
    return NULL;
}

function build_form($tree, $formid) {
    global $fragments;

    $childresult = '';
    foreach($tree->children as $child) {
        $childresult .= build_form_parts($child, '', $tree->childtype);
    }

    $replacements = array(
        '¤children' => $childresult,
        '¤formid'   => $formid,
        '¤token'    => gen_token(),
        '¤type'     => 'hidden',
        '¤message'  => ''
    );

    if(isset($_COOKIE['result'])) {
        $replacements['¤type'] = $_COOKIE['result'];
        $replacements['¤message'] = $_COOKIE['message'];
        setcookie('result', '', time() - 3600);
        setcookie('message', '', time() - 3600);
    }

    return replace($replacements, $fragments['form']);
}

function build_form_parts($tree, $group, $template, $parent_extras = array()) {
    global $fragments, $glue, $space;

    $nodestring  = $tree->text;
    $childtype   = $tree->childtype;
    $html_string = style($nodestring, $tree->style);
    $html_name   = str_replace(' ', $space, $nodestring);
    $html_id     = $group .$glue. $html_name;

    # childless question types
    switch($childtype) {
        case "text":
        case "longtext":
            $leaf_name = $group .$glue. $html_name;
            $replacements = array(
                '¤text'  => $html_string,
                '¤name'  => $leaf_name,
                '¤id'    => $leaf_name,
                '¤extra' => parse_extras($tree->extra_arr),
            );
            $html_string = replace($replacements, $fragments[$childtype]);
            break;
        case "range":
            $leaf_name = $group .$glue. $html_name;
            $replacements = array(
                '¤text'      => $html_string,
                '¤name'      => $leaf_name,
                '¤id'        => $leaf_name,
                '¤min'       => $tree->extra_arr['min'],
                '¤max'       => $tree->extra_arr['max'],
                '¤label_min' => $tree->extra_arr['minlabel'],
                '¤label_max' => $tree->extra_arr['maxlabel'],
            );
            $html_string = replace($replacements, $fragments[$childtype]);
    }

    $node_html = replace(array(
        '¤text'  => $html_string,
        '¤name'  => $group,
        '¤id'    => $html_id,
        '¤value' => $html_name,
        '¤extra' => parse_extras($parent_extras),
    ), $fragments[$template]);

    if($tree->hasChild()) {
        $childgroup = $html_name;
        if($group) {
            $childgroup = implode($glue, array($group, $html_name));
        }

        # build each answer option
        $childresult = '';
        foreach($tree->children as $child) {
            $childresult .= build_form_parts($child,
                                             $childgroup,
                                             $childtype,
                                             $tree->extra_arr);
        }

        # add "no answer" option last in optional radio button lists
        if($childtype == 'radio' && !in_array('required', $tree->extra_arr)) {
            $defaultstring = 'Inget svar';
            $html_string = style($defaultstring, $tree->style);
            $html_name = str_replace(' ', $space, $defaultstring);
            $html_id = $childgroup .$glue. $html_name;

            $childresult .= replace(array(
                '¤id'    => $html_id,
                '¤name'  => $childgroup,
                '¤value' => $html_name,
                '¤text'  => $html_string
            ), $fragments['radio_default']);
        }

        # wrap the results in a fieldset
        $node_html = replace(array(
            '¤text'     => $node_html,
            '¤children' => $childresult
        ), $fragments['parent']);

    }
    return $node_html;
}

function gen_token() {
    global $find_token;
    $token = bin2hex(openssl_random_pseudo_bytes(16));
    $find_token->bind_param('s', $token);
    execute($find_token);

    if(count(result($find_token)) != 0) {
        return gen_token();
    } else {
        return $token;
    }
}

function parse($infile) {
    $lines = file('./templates/'.$infile.'.form');
    $tree = new Node('ROOT');
    $builder = new Builder($tree);
    $indent_size = 2;

    $result = array();
    $preg = '/^( +)?([#!]+)?([^[]+)(\[([^:]+)(: ?([^]]+))?\])?$/';
    foreach($lines as $line) {
        preg_match($preg, rtrim($line), $result);
        if(count($result) === 0) {
            continue;
        }
        $depth = strlen($result[1])/$indent_size + 1;
        $style = $result[2];
        $text = trim($result[3]);

        $childtype = 'plain';
        if(array_key_exists(5, $result)) {
            $childtype = $result[5];
        }
        $extra_arr = array();
        if(array_key_exists(7, $result)) {
            $temp_arr = preg_split('/ *, */', $result[7]);
            $extra_arr = array();
            foreach($temp_arr as $item) {
                if(strpos($item, '=') !== false) {
                    [$key, $value] = explode("=", $item);
                    $extra_arr[$key] = $value;
                } else {
                    $extra_arr[$item] = $item;
                }
            }
        }
        $builder->add($depth, $text, $style, $childtype, $extra_arr);
    }
    return $tree;
}

function style($string, $style) {
    global $styles;

    if(!$style) {
        return $string;
    }
    return replace(array(
        '¤text' => $string
    ), $styles[$style]);
}

function parse_extras($extra_arr) {
    $out = '';
    foreach($extra_arr as $key => $value) {
        if($value === '') {
            $out.= $key .' ';
        } else {
            $out .= $key.'="'.$value.'" ';
        }
    }
    return $out;
}

function save_results() {
    global $glue, $space, $find_token, $save_result, $save_answer;
    $form = $_POST['form-id'];
    $token = $_POST['form-token'];
    $date = time();

    $find_token->bind_param('s', $token);
    execute($find_token);

    if(count(result($find_token)) != 0) {
        return error('De här svaren har redan sparats.');
    }

    begin_trans();
    $save_result->bind_param('ssi', $form, $token, $date);
    if(!$save_result->execute()) {
        revert_trans();
        error_log($save_result->error);
        return error("Inlämningen med token $token kunde inte sparas.");
    }

    $ignore = array('form-token', 'form-id');
    foreach($_POST as $question => $answer) {
        if(in_array($question, $ignore, TRUE)) {
            continue;
        }
        if(is_array($answer)) {
            $answer = implode($glue, $answer);
            error_log($answer);
        }
        $save_answer->bind_param('sss', $token, $question, $answer);
        if(!$save_answer->execute()) {
            revert_trans();
            error_log($save_answer->error);
            return error('Kunde inte spara ett av svaren.');
        }
    }
    commit_trans();
    return success('Dina svar har sparats.');
}

function build_resultpage() {
    global $results;

    $replacements = array(
        '¤type'    => 'hidden',
        '¤message' => ''
    );

    if(isset($_COOKIE['result'])) {
        $replacements['¤type'] = $_COOKIE['result'];
        $replacements['¤message'] = $_COOKIE['message'];
        setcookie('result', '', time() - 3600);
        setcookie('message', '', time() - 3600);
    }

    return replace($replacements, $results['base']);
}

function build_results($cutoff_date, $patient_id, $form_id) {
    if($form_id !== '') {
        return build_form_results($cutoff_date, $patient_id, $form_id);
    }

    $get_all_form_ids = prepare('select distinct `form` from `result`');
    execute($get_all_form_ids);
    $form_id_result = result($get_all_form_ids);

    $results = array();
    foreach($form_id_result as $row) {
        $form_id = $row['form'];
        $results[] = build_form_results($cutoff_date, $patient_id, $form_id);
    }
    return join("\n\n", $results);
}

function build_form_results($cutoff_date, $patient_id, $form_id) {
    global $question_replacements;

    if($form_id === '') {
        echo "Form must be specified.";
        die;
    }

    $get_questions = prepare('select distinct `question` from `data`
                              where `token` in (
                                select `token` from `result` where `form`=?
                              )');
    $get_questions->bind_param('s', $form_id);
    execute($get_questions);
    $question_rows = result($get_questions);

    $all_questions = array();
    foreach($question_rows as $row) {
        $all_questions[] = $row['question'];
    }

    $updated_questions_map = map_updated_questions($form_id, $all_questions);

    $resultrows = null;
    if($patient_id) {
        $get_patient_results = prepare("select * from `result`
                                        where `date`>?
                                            and `form` like ?
                                            and `token` in (
                                        select `token` from `data`
                                        where lower(`question`)
                                            like '%löpnummer%'
                                            and `answer`=?
                                        )");
        $get_patient_results->bind_param('iss',
                                         $cutoff_date,
                                         $form_id,
                                         $patient_id);
        execute($get_patient_results);
        $resultrows = result($get_patient_results);
    } else {
        $get_all_results = prepare('select * from `result`
                                    where `date`>?
                                        and `form` like ?');
        $get_all_results->bind_param('is', $cutoff_date, $form_id);
        execute($get_all_results);
        $resultrows = result($get_all_results);
    }

    $all_results = array();
    foreach($resultrows as $row) {
        $resultset = new Resultset($row['form'],
                                   $row['date'],
                                   $row['token'],
                                   $all_questions,
                                   $updated_questions_map);
        $all_results[] = $resultset;
    }

    if(!$all_results) {
        $error_message = "Det finns inga svar som passar dina kriterier.\n";
        if($form_id !== '%') {
            $error_message .= "Formulär: $form_id\n";
        }
        if($patient_id) {
            $error_message .= "Patient: $patient_id\n";
        }
        if($cutoff_date) {
            $cutoff_date = date('Y-m-d', $cutoff_date);
            $error_message .= "Tidigaste svarsdatum: $cutoff_date\n";
        }
        return $error_message;
    }

    // Making sure only the latest version of each question will be included
    $latest_questions = array();
    foreach($all_questions as $question) {
        $updated_question = $updated_questions_map[$question];
        if(!in_array($updated_question, $latest_questions)) {
            $latest_questions[] = $updated_question;
        }
    }

    // Sorting the questions so they get listed in a predictable order
    usort($latest_questions, 'compare_questions');

    $column_titles_row = "Formulär\tDatum\t";
    $column_titles_row .= replace($question_replacements,
                                  join("\t", $latest_questions));

    $alldata_strings = array();
    foreach($all_results as $result) {
        $results_form = $result->get_form_id();
        $results_date = $result->get_formatted_date();
        $results_string = $result->get_formatted_results($latest_questions);

        $alldata_strings[] = "$results_form\t$results_date\t$results_string";
    }

    return $column_titles_row . "\n" . join("\n", $alldata_strings);
}

function compare_questions($q1, $q2) {
    global $space, $glue;

    $q1 = preg_replace("/^[$space$glue]+(.*)/", '$1', $q1);
    $q2 = preg_replace("/^[$space$glue]+(.*)/", '$1', $q2);

    $q1_starts_with_number = preg_match('/^[0-9]/', $q1);
    $q2_starts_with_number = preg_match('/^[0-9]/', $q2);

    if($q1_starts_with_number and !$q2_starts_with_number) {
        return 1;
    }
    if(!$q1_starts_with_number and $q2_starts_with_number) {
        return -1;
    }
    return strnatcasecmp($q1, $q2);
}

function map_updated_questions($form, $all_questions) {
    global $glue;

    $updates = read_question_updates($form);

    $question_map = array();
    foreach($all_questions as $question) {
        /*
           Questions coming from the database are fully qualified in the
           document structure. Questions in the changes file are not, so we
           break the stored questions up and examine each part.

           This approach WILL BREAK if one of two identical questions in
           different parts of a form is updated. In that case, change files
           will need to start using fully qualified questions.
        */
        $question_parts = explode($glue, $question);
        $updated_parts = array();
        foreach($question_parts as $part) {
            if(array_key_exists($part, $updates)) {
                $updated_parts[] = $updates[$part];
            } else {
                $updated_parts[] = $part;
            }
        }
        $question_map[$question] = join($glue, $updated_parts);
    }
    return $question_map;
}

function read_question_updates($form) {
    global $space;

    $updates_file = './templates/'.$form.'.changes';
    if(!file_exists($updates_file)) {
        return array();
    }

    $updates = array();
    $latest_version = '';
    foreach(file($updates_file) as $line) {
        $line = trim($line);
        if(!$line) {
            $latest_version = '';
            continue;
        }

        // conform the incoming lines to the format that
        // will be read from the database
        $line = replace(array(' ' => $space,
                              '.' => '_'),
                        $line);

        if(!$latest_version) {
            $latest_version = $line;
            continue;
        }
        $updates[$line] = $latest_version;
    }
    return $updates;
}

class Resultset {
    private $form;
    private $date;
    private $token;

    function __construct($form,
                         $date,
                         $token,
                         $questions,
                         $updated_questions_map) {
        $this->form = $form;
        $this->date = $date;
        $this->token = $token;
        $this->results = array();
        $this->updated_questions_map = $updated_questions_map;
        foreach($questions as $question) {
            $this->results[$question] = '';
        }
        $this->store_answers();
    }

    function store_answers() {
        $get_answers = prepare('select * from `data` where `token`=?');
        $get_answers->bind_param('s', $this->token);
        if(!$get_answers->execute()) {
            error_log($get_answers->error);
            echo "couldn't get result";
            die;
        }

        $data_rows = result($get_answers);
        foreach($data_rows as $data) {
            $this->store_answer($data['question'], $data['answer']);
        }
    }

    function store_answer($question, $answer) {
        if(array_key_exists($question, $this->updated_questions_map)) {
            $question = $this->updated_questions_map[$question];
        }
        $this->results[$question] = $answer;
    }

    function get_form_id() {
        return $this->form;
    }

    function get_formatted_date() {
        return date('Y-m-d H:i', $this->date);
    }

    function get_formatted_results($sorted_questions) {
        // This method takes the list of questions as an argument
        // in order to keep a consistent sorting across Resultset objects

        global $answer_replacements;
        $out = '';
        foreach($sorted_questions as $question) {
            $answer = replace($answer_replacements,
                              trim($this->results[$question]));

            # This strips descriptive text from 'ranking' answers
            if(preg_match('%^([[:digit:]]+) - .+$%', $answer)) {
                $answer = preg_replace('%^([[:digit:]]+) - .+$%',
                                       '$1',
                                       $answer);
            }

            $out .= "$answer\t";
        }
        return $out;
    }
}

class Node {
    public $text = '';
    public $style = '';
    public $childtype = '';
    public $children = array();
    public $parent = NULL;
    public $extra_arr = array();

    function __construct($text,
                         $style = '',
                         $childtype = 'plain',
                         $extra_arr = NULL) {
        $this->text = $text;
        $this->style = $style;
        $this->childtype = $childtype;
        if($extra_arr !== NULL) {
            $this->extra_arr = $extra_arr;
        }
    }

    function __toString() {
        return implode("\n", $this->toStrings());
    }

    function toStrings() {
        $out = array();
        $temp = 'text: '.$this->text.' ['.$this->childtype;
        if($extra_arr) {
            $temp .= ': '.parse_extras($this->extra_arr);
        }
        $temp .= ']';
        $out[] = $temp;
        if(!empty($this->children)) {
            $out[] = 'children: (';
            foreach($this->children as $child) {
                foreach($child->toStrings() as $line) {
                    $out[] = "\t".$line;
                }
            }
            $out[] = ')';
        }
        return $out;
    }

    function child($child) {
        $this->children[] = $child;
        $child->parent = $this;
    }

    function hasChild() {
        return !empty($this->children);
    }
}

class Builder {
    public $node = NULL;
    public $depth = 0;

    function __construct($node, $depth = 0) {
        $this->node = $node;
        $this->depth = $depth;
    }

    function add($depth, $text, $style, $childtype, $extra_arr) {
        $node = new Node($text, $style, $childtype, $extra_arr);

        if($depth > $this->depth) {
            $this->node->child($node);
            $this->depth = $depth;
        } elseif($depth == $this->depth) {
            $this->node->parent->child($node);
        } else {
            $parent = $this->node->parent;
            foreach(range($depth, $this->depth - 1) as $i) {
                $parent = $parent->parent;
            }
            $parent->child($node);
            $this->depth = $depth;
        }
        $this->node = $node;
    }
}

function success($message) {
    setcookie('result', 'success');
    setcookie('message', $message);
    return true;
}

function error($message) {
    setcookie('result', 'error');
    setcookie('message', $message);
    return false;
}

function prepare($statement) {
    global $db;

    if(!($s = $db->prepare($statement))) {
        print 'Failed to prepare the following statement: '.$statement;
        print '<br/>';
        print $db->errno.': '.$db->error;
        exit(1);
    }

    return $s;
}

function execute($statement) {
    if(!$statement->execute()) {
        return error('Databasfel: '.$statement->error);
    }
    return true;
}

function result($statement) {
    return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

function begin_trans() {
    global $db;

    $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
}

function commit_trans() {
    global $db;

    $db->commit();
    return true;
}

function revert_trans() {
    global $db;

    $db->rollback();
    return false;
}

?>
