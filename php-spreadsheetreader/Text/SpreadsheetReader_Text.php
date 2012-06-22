<?php
/**
* todo
*
* @category   Spreadsheet
* @package    SpreadsheetReader
* @author     Shih Yuncheng <shirock@educities.edu.tw>
* @license    GNU Lesser General Public License (LGPL) version 2.1 or later
* @link       http://code.google.com/p/php-spreadsheetreader/
*/

if (!class_exists('SpreadsheetReader'))
    require_once dirname(__FILE__) . '/../SpreadsheetReader.php';
class SpreadsheetReader_Text extends SpreadsheetReader {
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
    }
    
    /**
     * Pattern of text line.
     * @access protected
     */
    protected $rowPattern;
    
    /**
     * Set/Get pattern of text line.
     *
     * @access  public
     * @param   $p      If ignore this argument, it will be a getter and
     *                  return current pattern.
     *                  If pass FALSE or an empty string, it means to
     *                  disable pattern.
     * @return  pattern.
     */
    final public function pattern($p = null) {
        // You don't need to extend this method.
        // If you extend this class, you should set pattern
        // of text line in the constructor of subclass directly.
        if ($p !== null) {
            $this->rowPattern = $p;
        }
        return $this->rowPattern;
    }

    /**
     * $sheets = read('~/example.txt');
     * $sheet = 0;
     * $row = 0;
     * $column = 0;
     * echo $sheets[$sheet][$row][$column];
     *
     * @todo    return results as XML String.
     *          how to detect char encoding, or to convert to utf-8?
     *
     * @param $txtFilePath  File path of Text Sheet file.
     * @param $returnType   Type of return value.
     *                      'array':  Array. This is default.
     *                      'string': XML string.
     * @return FALSE or an array contains sheets.
     */
    public function &read($txtFilePath, $returnType = 'array') {
        $ReturnFalse = FALSE;

        if (!is_readable($txtFilePath)) {
            return $ReturnFalse;
        }
        $fp = fopen($txtFilePath, 'r');

        $sheets[0] = array();  //there is only one sheet in float text.
        $indexOfRow = 0;
        $usePattern = !empty($this->rowPattern);
        while ($rowString = fgets($fp)) {
            if ($usePattern and
                preg_match($this->rowPattern, $rowString, $matches) > 0)
            {
                // User might use any PCRE syntax as patterns,
                // including named subpattern.
                // But a named subpattern will obtain two items
                // in the array with matches. We just need one.
                // So, here we use numeric index to fetch matches.
                for ($indexOfMatches = 1, $countOfMatches = count($matches);
                    $indexOfMatches < $countOfMatches;
                    ++$indexOfMatches)
                {
                    if (!isset($matches[$indexOfMatches]))
                        break;
                    $sheets[0][$indexOfRow][] = $matches[$indexOfMatches];
                }
            }
            else {
                $sheets[0][] = explode("\t", $rowString);
            }
            ++$indexOfRow;
        }
        fclose($fp);

        if ($returnType == 'string') {
            throw new Exception('not implemented!');
            return $xmlString;
        }
        return $sheets;
    }
}
?>
