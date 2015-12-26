<?php
/**
 * DokuWiki Plugin ExtList (Syntax component)
 *
 * This plugin extends DokuWiki's list markup syntax to allow descriptionn lists
 * and list items with multiple paragraphs. The complete syntax is as follows:
 *
 *
 *   - ordered list item            [<ol><li>]  <!-- as standard syntax -->
 *   * unordered list item          [<ul><li>]  <!-- as standard syntax -->
 *   ? description list term        [<dl><dt>]
 *   : description list item        [<dl><dd>]
 *
 *   -- ordered list item w/ multiple paragraphs
 *   ** unordered list item w/ multiple paragraphs
 *   :: description list item w/multiple paragraphs
 *   .. new paragraph in ether --, **, or ::
 *
 *
 * Lists can be nested within lists, just as in the standard DokuWiki syntax.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Ben Slusky <sluskyb@paranoiacs.org>
 *
 */
if (!defined('DOKU_INC')) die();

class syntax_plugin_yalist extends DokuWiki_Syntax_Plugin {

    protected $entry_pattern = '(?:--?|\*\*?|\?|::?) *';
    protected $match_pattern = '(?:--?|\*\*?|\?|::?|\.\.) *';
    protected $exit_pattern  = '\n(?=\n)';

    protected $pluginMode;
    protected $stack = array();
    protected $markup = array();
    protected $tags_map = array();

    protected $use_div = true;

    public function __construct() {
        $this->pluginMode = substr(get_class($this), 7); // drop 'syntax_' from class name

        // markup for list items
        $this->markup = array(
            '-'  => 'ol',   // <ol> <li><div>   ...    </div></li>
            '--' => 'olp',  // <ol> <li><div><p>...</p></div></li>
            '*'  => 'ul',   // <ul> <li><div>   ...    </div></li>
            '**' => 'ulp',  // <ul> <li><div><p>...</p></div></li>
            '?'  => 'dt',   // <dl> <dt><div>   ...    </div></dt>
            ':'  => 'dd',   // <dl> <dd><div>   ...    </div></dd>
            '::' => 'ddp',  // <dl> <dd><div><p>...</p></div></dd>
            '..' => 'p',    //               <p>...</p>
        );

        // prefix and surffix of html tags
        /* HTML RULE: <ul>タグや<ol>タグの中には<li>タグ以外は入れてはいけない */
        $this->tags_map = array(
            'ol' => array("\n","\n"),  '/ol' => array("","\n"),
            'ul' => array("\n","\n"),  '/ul' => array("","\n"),
            'dl' => array("\n","\n"),  '/dl' => array("","\n"),
            'li' => array("  ",""),    '/li' => array("","\n"),
            'dt' => array("  ",""),    '/dt' => array("","\n"),
            'dd' => array("  ","\n"),  '/dd' => array("","\n"),
            'p'  => array("\n",""),    '/p'  => array("","\n"),
        );
    }

    function getType() { return 'container'; }
    function getSort() { return 9; } // just before listblock (10)
    function getPType() { return 'block'; }
    function getAllowedTypes() {
        return array('substition', 'protected', 'disabled', 'formatting');
    }

    function connectTo($mode) {
        $this->Lexer->addEntryPattern('\n {2,}'.$this->entry_pattern, $mode, $this->pluginMode);
        $this->Lexer->addEntryPattern('\n\t{1,}'.$this->entry_pattern, $mode, $this->pluginMode);
        $this->Lexer->addPattern('\n {2,}'.$this->match_pattern, $this->pluginMode);
        $this->Lexer->addPattern('\n\t{1,}'.$this->match_pattern, $this->pluginMode);
    }
    function postConnect() {
        $this->Lexer->addExitPattern($this->exit_pattern, $this->pluginMode);
    }


    protected function _interpret($match) {
        $type = $this->markup[trim($match)];
        $depth = substr_count(str_replace("\t", '  ', $match),'  ');
        return array($depth, $type);
    }


    private function _listTag($type) {
        return $type[0].'l'; // = [ul|ol|dl]
    }
    private function _itemTag($type) {
        return ($type[0] == 'd') ? substr($type,0,2) : 'li';
    }
    private function _isParagraph($type) {
        return (substr($type, -1) == 'p');
    }
    private function _isListTypeChanged($type0, $type1) {
        return (strncmp($type0, $type1, 1) !== 0);
    }

    /**
     * helper function to simplify writing plugin calls to the instruction list
     * first three arguments are passed to function render as $data
     * Note: this function was used in the DW exttab3 plugin.
     */
    protected function _writeCall($tag, $attr, $state, $pos, $match, $handler) {
        $handler->addPluginCall($this->getPluginName(),
            array($state, $tag, $attr), $state, $pos, $match);
    }


    function handle($match, $state, $pos, Doku_Handler $handler) {

        switch ($state) {
        case DOKU_LEXER_ENTER:
            list ($depth, $type) = $this->_interpret($match);
            $n = 1;  // order of the current list item
            $data = array($depth, $type, $n);
            array_push($this->stack, $data);

            // open list tag [ul|ol|dl]
            $this->_writeCall($this->_listTag($type),'',DOKU_LEXER_ENTER, $pos,$match,$handler);
            // open item tag [li|dt|dd]
            $attr = (substr($type,1,2) == 'l') ? 'class="level'.$depth.'"' : '';
            $this->_writeCall($this->_itemTag($type),$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
            // open div
            if ($this->use_div) {
                $this->_writeCall('div','',DOKU_LEXER_ENTER, $pos,$match,$handler);
            }
            // open p if necessary
            if ($this->_isParagraph($type)) {
                $this->_writeCall('p','',DOKU_LEXER_ENTER, $pos,$match,$handler);
            }
            break;

        case DOKU_LEXER_UNMATCHED:
            $handler->base($match, $state, $pos);    // cdata --- use base() as _writeCall() is prefixed for private/protected
            break;

        case DOKU_LEXER_EXIT:
            // list ($depth, $type) = $this->_interpret($match);
            // $depth = 0
            error_log('yalist: EXIT ');

        case DOKU_LEXER_MATCHED:
            list ($prev_depth, $prev_type, $prev_n) = array_pop($this->stack); // スタック取り崩し
            list ($depth, $type) = $this->_interpret($match);

            error_log('yalist: prev0='.$prev_depth.' '.$prev_type.' '.$prev_n);

            // 該当する場合 li_div 内の p を閉じる(/p発行)
            if ($this->_isParagraph($prev_type)) {
                $this->_writeCall('p','',DOKU_LEXER_EXIT, $pos,$match,$handler);
            }
            // リスト深さが浅くなる場合 深いリストは閉じてしまう
            $close_div = true;
            while ( $prev_depth > $depth ) {
                if ($close_div && $this->use_div && !empty($prev_type)) {
                    // close div
                    $this->_writeCall('div','',DOKU_LEXER_EXIT, $pos,$match,$handler);
                    error_log('yalist close div1 : curr='.$depth.' '.$type.' '.$n);
                }
                // close item tag [li|dt|dd]
                $this->_writeCall($this->_itemTag($prev_type),'',DOKU_LEXER_EXIT, $pos,$match,$handler);
                // close list tag [ul|ol|dl]
                $this->_writeCall($this->_listTag($prev_type),'',DOKU_LEXER_EXIT, $pos,$match,$handler);

                list ($prev_depth, $prev_type, $prev_n) = array_pop($this->stack);
                error_log('yalist: prev='.$prev_depth.' '.$prev_type.' '.$prev_n);
                $close_div = false;
            }
            if ($state == DOKU_LEXER_EXIT) {
                error_log('yalist: EXIT NOW: prev_depth='.$prev_depth );
            }
            if ($state == DOKU_LEXER_EXIT) break;
            //この段階で 直前と現在の深さは同じになる。

            error_log('yalist: curr='.$depth.' '.$type.' '.$n);

            // p でリストを浅くすることはありうるが、深くすることはできない
            // 直前のアイテムタイプが p付きでない場合は p付きだったことにする
            if ($type == 'p') {
                $this->_writeCall('p','',DOKU_LEXER_ENTER, $pos,$match,$handler);
                $depth = $prev_depth;
                $n     = $prev_n;
                if ($this->_isParagraph($prev_type)){
                    $type = $prev_type;
                } else {
                    $type = $prev_type.'p';
                }
                // 最初に取り崩したスタックを元に戻す
                $data = array($depth, $type, $n); // スタックの末端を更新
                array_push($this->stack, $data);
                break;
            }

            // item の div を閉じる必要があるかを判断
            if ($prev_depth < $depth) {
                // close div
                if ($this->use_div) {
                    $this->_writeCall('div','',DOKU_LEXER_EXIT, $pos,$match,$handler);
                    error_log('yalist close div : curr='.$depth.' '.$type.' '.$n);
                }
                // close item tag [li|dt|dd]
                // リストが深くなる場合、ここで /li を発行してはならない。
                //$this->_writeCall($this->_itemTag($prev_type),'',DOKU_LEXER_EXIT, $pos,$match,$handler);

                // リストが深くなる場合は 直前のリストアイテムを閉じないため 
                // 最初に取り崩したスタックを元に戻す
                $data = array($prev_depth, $prev_type, $prev_n);
                array_push($this->stack, $data);
            }

            // list を閉じる必要があるかを判断
            if ($prev_depth == $depth) {
                // リスト深さが同じ場合は /li を発行する
                $this->_writeCall($this->_itemTag($prev_type),'',DOKU_LEXER_EXIT, $pos,$match,$handler);

                if ($this->_isListTypeChanged($prev_type, $type)) {
                    // close list tag [ul|ol|dl]
                    $this->_writeCall($this->_listTag($prev_type),'',DOKU_LEXER_EXIT, $pos,$match,$handler);
                    $prev_n = 0; // リスト種類が変わるため、リセット
                }
            }

            // open list if necessary
            // 必要な場合 リストの開始
            if (($prev_depth < $depth) || ($prev_n == 0)) {
                // リストが深くなるか、異なる種類のリストを開始する場合
                $n = 1;
                // open list tag [ul|ol|dl]
                $this->_writeCall($this->_listTag($type),'',DOKU_LEXER_ENTER, $pos,$match,$handler);
            } else {
                // リスト深さが同じ、リストの種類も同じ場合
                $n = $prev_n + 1; // リストアイテムが増える
            }

            // open item tag [li|dt|dd]
            $attr = (substr($type,1,2) == 'l') ? 'class="level'.$depth.'"' : '';
            $this->_writeCall($this->_itemTag($type),$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
            // open div
            if ($this->use_div) {
                $this->_writeCall('div','',DOKU_LEXER_ENTER, $pos,$match,$handler);
            }
            // open p if necessary
            if ($this->_isParagraph($type)) {
                $this->_writeCall('p','',DOKU_LEXER_ENTER, $pos,$match,$handler);
            }

            $data = array($depth, $type, $n); // スタックの末端を更新
            array_push($this->stack, $data);

        } // end of switch
        return true;
    }


    /*
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        switch ($format) {
            case 'xhtml':
                return $this->render_xhtml($renderer, $data);
            //case 'latex':
            //    $latex = $this->loadHelper('yalist_latex');
            //    return $latex->render($renderer, $data);
            //case 'odt':
            //    $odt = $this->loadHelper('yalist_odt');
            //    return $odt->render($renderer, $data);
            default:
                return false;
        }
    }

    protected function render_xhtml(Doku_Renderer $renderer, $data) {

        list($state, $tag, $attr) = $data;
        switch ($state) {
            case DOKU_LEXER_ENTER:   // open tag
                $renderer->doc.= $this->_open($tag, $attr);
                break;
            case DOKU_LEXER_MATCHED: // defensive, shouldn't occur
            case DOKU_LEXER_UNMATCHED:
                $renderer->cdata($tag);
                break;
            case DOKU_LEXER_EXIT:    // close tag
                $renderer->doc.= $this->_close($tag);
                break;
        }
    }

    protected function _open($tag, $attr=NULL) {
        if (!is_null($attr)) $attr = ' '.$attr;
        $before = $this->tags_map[$tag][0];
        $after  = $this->tags_map[$tag][1];
        return $before.'<'.$tag.$attr.'>'.$after;
    }

    protected function _close($tag) {
        $before = $this->tags_map['/'.$tag][0];
        $after  = $this->tags_map['/'.$tag][1];
        return $before.'</'.$tag.'>'.$after;
    }


}
