<?php

$fragments = get_fragments('./include/forms.html');
$styles = get_fragments('./include/styles.html');

$save_result = prepare('insert into `result`(`form`, `token`, `date`) values (?, ?, ?)');
$get_results = prepare('select * from `result`');
$save_answer = prepare('insert into `data`(`token`, `question`, `answer`) values (?, ?, ?)');
$get_answers = prepare('select * from `data` where `token`=?');
$find_token  = prepare('select `token` from `result` where `token`=?');

$glue          = "\x1E"; //ascii record separator
$space         = "\x1F"; //ascii unit separator
$break         = '<br/>';

function build_index() {
    $patterns = array('%\./templates/%', '/\.form$/');
    $link = '<h3><a href="?id=¤id">¤id</a></h3>';

    $out = '';
    $out .= "<h1>Välj ett formulär</h1>";
    foreach(glob('./templates/*.form') as $form) {
        
        $form = preg_replace($patterns, '', $form);
        $out .= replace(array(
            '¤id' => $form
        ), $link);
    }
    return $out;
}

function build_form($tree, $group = NULL, $template = 'plain') {
    global $form, $fragments, $glue, $space, $break;

    $nodestring    = $tree->text;
    $childtype     = $tree->childtype;
    $styled_string = style($nodestring, $tree->style);
    $html_name     = str_replace(' ', $space, $nodestring);
    $id            = $group .$glue. $html_name;

    $node_html = replace(array(
        '¤text'  => $styled_string,
        '¤name'  => $group,
        '¤id'    => $id,
        '¤value' => $html_name
    ), $fragments[$template]);
    
    if($tree->hasChild()) {
        $childresult = '';
        $childgroup = '';
        if($group && $group !== 'ROOT') {
            $childgroup = implode($glue, array($group, $html_name));
        } else {
            $childgroup = $html_name;
        }
        foreach($tree->children as $child) {
            $childresult .= build_form($child, $childgroup, $childtype);
        }
        $template = 'parent';
        $replacements = array(
            '¤text'     => $node_html,
            '¤children' => $childresult
        );
        if($nodestring === 'ROOT') {
            $template = 'form';
            $replacements['¤formid'] = $form;
            $replacements['¤token'] = gen_token();
        }
        return replace($replacements, $fragments[$template]);
    } elseif($childtype !== 'plain') {
        $name = $group .$glue. $html_name;
        $replacements = array(
            '¤text'  => $node_html,
            '¤name'  => $name,
            '¤id'    => $name,
        );
        return replace($replacements, $fragments[$childtype]) . $break;
    }
    return $node_html . $break;
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
    $preg = '/^( +)?(#+)?([^[]+)(\[([^,]+)(, ?([^]]+))?\])?$/';
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
        $datatype = 'string';
        if(array_key_exists(7, $result)) {
            $datatype = $result[7];
        }
        $builder->add($depth, $text, $style, $childtype, $datatype);
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

class Node {
    public $text = '';
    public $style = '';
    public $childtype = '';
    public $children = array();
    public $parent = NULL;
    public $datatype = NULL;

    function __construct($text, $style = '', $childtype = 'plain', $datatype = 'string') {
        $this->text = $text;
        $this->style = $style;
        $this->childtype = $childtype;
        $this->datatype = $datatype;
    }

    function __toString() {
        return implode("\n", $this->toStrings());
    }

    function toStrings() {
        $out = array();
        $temp = 'text: '.$this->text.' ['.$this->childtype;
        if($datatype) {
            $temp .= ', '.$this->datatype;
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

    function add($depth, $text, $style, $childtype, $datatype) {
        $node = new Node($text, $style, $childtype, $datatype);

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

function error($message) {
    #setcookie('error', $message);
    echo 'An error has occurred: ' . $message;
    die;
}

function prepare($statement) {
    global $db;

    if(!($s = $db->prepare($statement))) {
        print 'Failed to prepare the following statement: '.$statement;
        print '<br/>';
        print $db->errno.': '.$db->error;
        exit(1);;
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
