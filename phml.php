<?php

# TODO : need to separate the output indentation from the nesting
#
# - TODO : support if/else if/else
# - TODO : support switch with case
# - a line could have both ->nesting and ->indent
# - certain lines require not indent (like php flow control statements)
#   while still effecting nesting for parsing
#
#   - if(cond_a())
#     %p bar
#
# TODO : some elements REQUIRE nested elements, like flow control statements
# TODO : some elements self close (br, hr, link, meta, etc)
# TODO : should self closing elements allow nested content?
# TODO : only allow $variables and function() calls structures like:
#
#   = $foo_variable()
#   = foo_function()
#   = $foo_function_varaiable()
#   = foo_function_with_args($foo, $bar)
#   = foo_function_with_args(foo(), bar())
#   = foo_function_with_args($foo(), $bar())
#   = foo_function_with_args($foo($yuck), $bar())
#
# TODO : decide how to render the template w/out caching it anywhere
#

class PhmlTemplate {

  protected $path;
  
  protected $indent = 0;
  protected $stack = array();
  protected $buffer = array();
  
  public function __construct($template_path) {
    # TODO : raise an exception if the template is not readable or not found
    $this->path = $template_path;
  }

  public function render() {
    $this->parse();
    return implode("\n", $this->buffer);
  }

  protected function parse() {
    $num = 0;
    $prev_line = NULL;
    $template = fopen($this->path, 'r');
    while(!feof($template)) {
      $line = new PhmlLine(++$num, fgets($template));
      if($line->blank()) continue;
      $this->check_indentation($prev_line, $line);
      $this->manage_stack($line);
      switch(true) {
        case $line->no_content():
          $this->buffer_str($line->indent, $line->open);
          $this->stack[] = $line;
          break;
        default:
          $this->buffer_str($line->indent, $line->render());
      }
      $prev_line = $line;
    }
    fclose($template);
    while($this->stack_empty() == FALSE)
      $this->pop();
  }

  protected function check_indentation($prev_line, $line) {
    if($prev_line) {
      $max_indent = $prev_line->indent;
      $max_indent += $prev_line->no_content() ? 1 : 0;
    } else {
      $max_indent = 0;
    }
    if($line->indent > $max_indent)
      throw new Exception("invalid indentation on line {$line->num}");
  }

  protected function manage_stack($line) {
    while($this->stack_empty() == false) {
      if($line->indent <= $this->top()->indent)
        $this->pop();
      else
        break;
    }
  }

  protected function stack_empty() {
    return count($this->stack) == 0;
  }

  protected function top() {
    $count = count($this->stack);
    return $count > 0 ? $this->stack[$count - 1] : null;
  }

  protected function pop() {
    $top = array_pop($this->stack);
    $this->buffer_str($top->indent, $top->close);
  }

  protected function buffer_str($indent, $str) {
    $this->buffer[] = str_repeat('  ', $indent) . $str;
  }

}

class PhmlLine {

  const DOCTYPE = '/^!!!(\s+(.+))?$/';
  const HTML_COMMENT = '/^\/(\s+(.+))?$/';
  const HTML_ELEMENT = '/^(%[a-z]\w*)?(#[a-z]\w*)?(\.[a-z][\.\w]*)?(\(.+\))?(\s*\/|=\s*|\s+.+|)$/i';
  const PHP_FLOW = '/^- (if|foreach)(\(.+\))$/';

  const PHP_OPEN = '<?php';
  const PHP_CLOSE = '?>';

  public $num;
  public $indent;
  public $line;

  public $type;

  public $open = NULL;
  public $content = NULL;
  public $close = NULL;

  public function __construct($num, $line) {

    $this->num = $num;

    $line = rtrim($line);
    # TODO : validate the indentation
    $this->indent = strspn($line, ' ') / 2;
    $line = ltrim($line);
    $this->line = $line;
    $this->parse_line();
  }

  public function closed() {
    #return $this->content != NULL || 
  }

  public function blank() {
    return $this->type == 'ignore';
  }

  public function no_content() {
    return $this->content == NULL;
  }

  protected function parse_line() {
    $line = $this->line;
    switch(true) {

      # phml comments and blank lines 
      case $line == '':
      case $this->begins_with('-#');
        $this->type = 'ignore';
        break;

      ## doctype
      #case $line == '!!!':
      case preg_match(self::DOCTYPE, $line, $matches):
        # TODO : add other doctypes
        $this->type = 'doctype';
        $this->content = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">';
        break;

      ## html comment
      case preg_match(self::HTML_COMMENT, $line, $matches):
        $this->type = 'html_comment';
        $this->open = '<!-- ';
        $this->content = isset($matches[2]) ? $matches[2] : NULL;
        $this->close = ' -->';
        break;

      ## html element
      # meta, img, link, script, br, and hr tags are closed by default.
      case preg_match(self::HTML_ELEMENT, $line, $matches):
        $this->type = 'html_element';

        $tag = $matches[1] ? ltrim($matches[1], '%') : 'div';
        if($matches[2]) $id = ltrim($matches[2], '#');
        if($matches[3]) $class = str_replace('.', ' ', ltrim($matches[3], '.'));
        if($matches[4]) $attrs = trim($matches[4], '()');

        # TODO : support auto self closing tags
        # TODO : meta, img, link, script, br and hr tags should auto-self-close
        
        $content = ltrim($matches[5]);
        if($content == '') {

        } else if($content[0] == '/') {
          $self_closing = true;
        } else if($content[0] == '=') {
          $this->content = "<?php echo($content); %>";
        } else {
          $this->content = $content;
        }

        $this->open = $this->tag($tag, $id, $class, $attrs);
        $this->close = "</$tag>";
        break;

      # phml comment
      case $this->begins_with('-#'):
        break;

      # php flow control statements
      # TODO : add else, else if, do, while, switch, foreach, for
      case preg_match(self::PHP_FLOW, $line, $matches):
        $type = 'php_flow';
        $flow = $matches[1];
        $cond = trim($matches[2], '()');
        $this->open = $this->php_wrap("$flow($cond):");
        $this->close = $this->php_wrap("end$flow;");
        break;

      # interpreted as php
      case $line[0] == '-':
        $type = 'php';
        break;

      case preg_match('/^=\s+(.+)$/', $line, $matches):
        $type = 'php_echo';
        $this->content = $this->php_wrap("echo({$matches[1]});");
        break;

      # markup switch
      case $line[0] == ':':
        break;

      # static text
      # TODO : support $variable interpolation
      # TODO : support {$variable} interpolation
      default:
        $this->type = 'content';
        $this->content = $line;
        break;
    }
  }

  protected function php_wrap($str) {
    return self::PHP_OPEN . ' ' . $str . ' ' . self::PHP_CLOSE;
  }

  protected function tag($name, $id, $class, $attrs) {
    $attr = '';
    if($id) $attr .= " id='$id'";
    if($class) $attr .= " class='$class'";
    if($attrs) $attr .= " $attrs";
    return "<$name{$attr}>";
  }

  protected function begins_with($search) {
    return (strncmp($this->line, $search, strlen($search)) == 0);
  }

  public function render() {
    return trim("{$this->open}{$this->content}{$this->close}");
  }

}

ini_set('display_errors', '1');

$options = array('abc', 'xyz', '123');

$template = new PhmlTemplate('template.phml');
echo $template->render();
echo "\n";

#ob_start();
#eval('?' . '>' . $template->render());
#echo ob_get_clean();


