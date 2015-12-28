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

    protected $entry_pattern = '(?:--?\d*|\*\*?|\?|::?) *';
    protected $match_pattern = '(?:--?\d*|\*\*?|\?|::?|\.\.) *';
    protected $exit_pattern  = '\n(?=\n)';

    protected $pluginMode;
    protected $stack = array();
    protected $markup = array();
    protected $tags_map = array();

    protected $use_div = true;

    public function __construct() {
        $this->pluginMode = substr(get_class($this), 7); // drop 'syntax_' from class name

        // prefix and surffix of html tags
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
        $depth = substr_count(str_replace("\t", '  ', $match),'  ');
        preg_match('/([-*;?:.]{1,2})(\d*)/', $match, $matches);
        $mk = $matches[1];
        $n = (count($matches) >1) ? $matches[2] : NULL;
        return array($depth, $mk, $n);
    }


    private function listLevel($match) {
        return substr_count(str_replace("\t", '  ', $match),'  ');
    }
    private function listTag($mk) {
        $c = substr($mk, 0, 1);
        switch ($c) {
            case '?' : return 'dl';
            case ':' : return 'dl';
            case '-' : return 'ol';
            case '*' : return 'ul';
            default  : return false;
        }
    }
    private function itemTag($mk) {
        $c = substr($mk, 0, 1);
        switch ($c) {
            case '?' : return 'dt';
            case ':' : return 'dd';
            case '-' : return 'li';
            case '*' : return 'li';
            default  : return false;
        }
    }
    private function isParagraph($mk) {
        return (strlen($mk) >1);
    }
    private function isListTypeChanged($mk0, $mk1) {
        return (strncmp($this->listTag($mk0), $this->listTag($mk1), 1) !== 0);
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

    // write call to open or close a list [ul|ol|dl]
    private function _openList($mk, $start, $pos, $match, $handler) {
        $tag = $this->listTag($mk);
        if (($tag == 'ol') && is_numeric($start)) {
            $attr = 'start='.$start;
        } else $attr = '';
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }
    private function _closeList($mk, $pos, $match, $handler) {
        $tag = $this->listTag($mk);
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    // write call to open or close a list item [li|dt|dd]
    private function _openItem($mk, $num, $pos, $match, $handler) {
        $list = $this->listTag($mk);
        $tag = $this->itemTag($mk);
        if (($list == 'ol') && is_numeric($num)) {
            $attr = ' value='.$num;
        } else $attr = '';
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }
    private function _closeItem($mk, $pos, $match, $handler) {
        $tag = $this->itemTag($mk);
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    // write call to open or close content (div tag)
    private function _openDiv($mk, $pos, $match, $handler) {
        $tag = 'div';
        $item = $this->itemTag($mk);
        if ($item == 'li') {
            $attr = 'class="li"';
        } else $attr ='';
        $this->_writeCall($tag,$attr,DOKU_LEXER_ENTER, $pos,$match,$handler);
    }
    private function _closeDiv($pos, $match, $handler) {
        $tag = 'div';
        $this->_writeCall($tag,'',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    // write call to open or close paragraph (p tag)
    private function _openParagraph($pos, $match, $handler) {
        $this->_writeCall('p','',DOKU_LEXER_ENTER, $pos,$match,$handler);
    }
    private function _closeParagraph($pos, $match, $handler) {
        $this->_writeCall('p','',DOKU_LEXER_EXIT, $pos,$match,$handler);
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {

        switch ($state) {
        case DOKU_LEXER_ENTER:
            list ($depth1, $mk1, $n1) = $this->_interpret($match);
            error_log('yalist: enter='.$depth1.' '.$mk1.' '.$n1);

            // open list tag [ul|ol|dl]
            $this->_openList($mk1, $n1, $pos,$match,$handler);
            // open item tag [li|dt|dd]
            $this->_openItem($mk1, $n1, $pos, $match, $handler);
            // open div
            if ($this->use_div) $this->_openDiv($mk1, $pos,$match,$handler);
            // open p if necessary
            if ($this->isParagraph($mk1)) $this->_openParagraph($pos,$match,$handler);

            $data = array($depth1, $mk1, $n1);
            array_push($this->stack, $data);
            break;

        case DOKU_LEXER_UNMATCHED:
            // cdata --- use base() as _writeCall() is prefixed for private/protected
            $handler->base($match, $state, $pos);
            break;

        case DOKU_LEXER_EXIT:
            // list ($depth, $mk, $n) = $this->_interpret($match);
            // $depth = 0

        case DOKU_LEXER_MATCHED:
            list ($depth0, $mk0, $n0) = array_pop($this->stack); // shorten the stack
            list ($depth1, $mk1, $n1) = $this->_interpret($match);

            error_log('yalist: prev0='.$depth0.' '.$mk0.' '.$n0);

            // close p if necessary
            if ($this->isParagraph($mk0)) $this->_closeParagraph($pos,$match,$handler);
            
            // list become shollower: close deeper list
            // リスト深さが浅くなる場合 深いリストは閉じてしまう
            $close_div = true;
            while ( $depth0 > $depth1 ) {
                if ($close_div && $this->use_div && !empty($mk0)) {
                    // close div
                    $this->_closeDiv($pos,$match,$handler);
                    error_log('yalist close div1 : curr='.$depth1.' '.$mk1.' '.$n1);
                }
                // close item tag [li|dt|dd]
                $this->_closeItem($mk0, $pos,$match,$handler);
                // close list tag [ul|ol|dl]
                $this->_closeList($mk0, $pos,$match,$handler);

                list ($depth0, $mk0, $n0) = array_pop($this->stack);
                error_log('yalist: prev='.$depth0.' '.$mk0.' '.$n0);
                $close_div = false;
            }
            if ($state == DOKU_LEXER_EXIT) {
                error_log('yalist: EXIT NOW: prev_depth='.$depth0 );
                break;
            }
            //この段階で 直前と現在の深さは同じになる。

            error_log('yalist: curr='.$depth1.' '.$mk1.' '.$n1);

            // p でリストを浅くすることはありうるが、深くすることはできない
            // 直前のアイテムタイプが p付きでない場合は p付きだったことにする
            if ($mk1 == '..') {
                $this->_openParagraph($pos,$match,$handler);
                $depth1 = $depth0;
                $n1     = $n0;
                $mk1 = ($this->isParagraph($mk0)) ? $mk0 : $mk0.$mk0;
                // 最初に取り崩したスタックを元に戻す
                $data = array($depth1, $mk1, $n1); // スタックの末端を更新
                array_push($this->stack, $data);
                break;
            }

            // list を閉じる必要があるかを判断
            /* HTML RULE: <ul>タグや<ol>タグの中には<li>タグ以外は入れてはいけない */
            if ($depth0 < $depth1) {
                // リストが深くなる場合、ここで /li を発行してはならない。
                // ただし、div は閉じる必要がある
                // close div
                if ($this->use_div) {
                    $this->_closeDiv($pos,$match,$handler);
                    error_log('yalist close div : curr='.$depth1.' '.$mk1.' '.$n1);
                }
                // リストが深くなる場合は 最初に取り崩したスタックを元に戻す
                $data = array($depth0, $mk0, $n0);
                array_push($this->stack, $data);

            } else if ($depth0 == $depth1) {
                // close item tag [li|dt|dd]
                // リスト深さが同じ場合は /li を発行する
                $this->_closeItem($mk0, $pos,$match,$handler);
                // close list tag [ul|ol|dl]
                if ($this->isListTypeChanged($mk0, $mk1)) {
                    $this->_closeList($mk0, $pos,$match,$handler);
                    $n0 = 0; // リスト種類が変わるため、リセット
                }
            }

            // open list tag [ul|ol|dl] if necessary
            if (($depth0 < $depth1) || ($n0 == 0)) {
                // リストが深くなるか、異なる種類のリストを開始する場合
                // open list tag [ul|ol|dl]
                if (!is_numeric($n1)) $n1 = 1;
                $this->_openList($mk1, $n1, $pos,$match,$handler);
            } else {
                // リスト深さが同じ、リストの種類も同じ場合
                if (!is_numeric($n1)) $n1 = $n0  +1;
            }

            // open item tag [li|dt|dd]
            $this->_openItem($mk1, $n1, $pos,$match,$handler);
            // open div
            if ($this->use_div) $this->_openDiv($mk1, $pos,$match,$handler);
            // open p if necessary
            if ($this->isParagraph($mk1)) $this->_openParagraph($pos,$match,$handler);

            $data = array($depth1, $mk1, $n1); // スタックの末端を更新
            array_push($this->stack, $data);

        } // end of switch
        return true;
    }


    /**
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


    /**
     * Create xhtml output
     */
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

    /**
     * open a tag, a utility for render_xhtml()
     */
    protected function _open($tag, $attr=NULL) {
        if (!empty($attr)) $attr = ' '.$attr;
        $before = $this->tags_map[$tag][0];
        $after  = $this->tags_map[$tag][1];
        return $before.'<'.$tag.$attr.'>'.$after;
    }

    /**
     * close a tag, a utility for render_xhtml()
     */
    protected function _close($tag) {
        $before = $this->tags_map['/'.$tag][0];
        $after  = $this->tags_map['/'.$tag][1];
        return $before.'</'.$tag.'>'.$after;
    }


}
