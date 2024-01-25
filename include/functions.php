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
    global $glue, $space;
    $qr = array(
        $glue  => ' -> ',
        $space => ' '
    );
    $ar = array(
        $glue  => '; ',
        $space => ' ',
        "\n" => ' ',
        "\r" => ''
    );

    $out = '';

    if($form_id === '') {
        echo "Form must be specified.";
        die;
    }

    $get_all_results = prepare('select * from `result` where `date`>? and `form` like ?');
    $get_patient_results = prepare("select * from result
                                    where `date`>?
                                    and `form` like ?
                                    and `token` in (
                                      select token from `data`
                                      where lower(`question`) like '%löpnummer%'
                                      and `answer`=?
                                    )");

    $resultrows = null;
    if($patient_id) {
        $get_patient_results->bind_param('iss', $cutoff_date, $form_id, $patient_id);
        execute($get_patient_results);
        $resultrows = result($get_patient_results);
    } else {
        $get_all_results->bind_param('is', $cutoff_date, $form_id);
        execute($get_all_results);
        $resultrows = result($get_all_results);
    }

    $first = true;
    $get_answers = prepare('select * from `data` where `token`=?');

    foreach($resultrows as $row) {
        $date  = date('Y-m-d H:i', $row['date']);
        $token = $row['token'];
        $form  = $row['form'];

        $get_answers->bind_param('s', $token);
        if(!$get_answers->execute()) {
            error_log($get_answers->error);
            echo "couldn't get result";
            die;
        }
        $data_rows = result($get_answers);

        $qline = "formulär\tdatum\t";
        $aline = "$form\t$date\t";
        foreach($data_rows as $data) {
            $q = replace($qr, $data['question']);
            $a = replace($ar, trim($data['answer']));

            if(preg_match('%^([[:digit:]]+) - .+$%', $a)) {
                $a = preg_replace('%^([[:digit:]]+) - .+$%', '$1', $a);
            }

            $qline .= $q."\t";
            $aline .= $a."\t";
        }
        if($first) {
            $out .= $qline."\n";
            $first = false;
        }
        $out .= $aline."\n";
    }
    if(!$out) {
        $out = "Det finns inga svar som passar dina kriterier.\n";
        if($patient_id) {
            $out .= "Patient: $patient_id\n";
        }
        if($form_id !== '%') {
            $out .= "Formulär: $form_id\n";
        }
        if($cutoff_date) {
            $cutoff_date = date('Y-m-d', $cutoff_date);
            $out .= "Tidigaste svarsdatum: $cutoff_date\n";
        }
    }
    return $out;
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
