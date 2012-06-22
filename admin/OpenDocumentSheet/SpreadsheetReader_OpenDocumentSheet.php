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
class SpreadsheetReader_OpenDocumentSheet extends SpreadsheetReader {
    protected $_odsXml;
    protected $_xsl;
    protected $_processor;
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        $this->_odsXml = new DOMDocument;
        $this->_xsl = new DOMDocument;
        $this->_xsl->load(dirname(__FILE__) . '/extract_tables_simplexml.xslt');

        // Configure the transformer
        $this->_processor = new XSLTProcessor;
        $this->_processor->importStyleSheet($this->_xsl); // attach the xsl rules
    }
    
    /**
     * read an spreadsheet file.
     *
     * $sheets = read('~/example.ods');
     * $sheet = 0;
     * $row = 0;
     * $column = 0;
     * echo $sheets[$sheet][$row][$column];
     *
     * @param  $odsFilePath  File path of Open Document Sheet file.
     * @param  [$returnType]  how to store read data?
     *      READ_ARRAY  - Default. Return an numeric index array.
     *      READ_NUM    - Same as READ_ARRAY
     *      READ_ASSOC  - Return an associative array.
     *                    It will use values of first row to be field name.
     *                    Though the count of rows will less one than numeric index array.
     *      READ_HASH   - Same as READ_ASSOC
     *      READ_XMLSTRING - Return an XML String.
     *
     * @return  FALSE or array or string.
     */
    public function &read($odsFilePath, $returnType = self::READ_ARRAY) {
        $ReturnFalse = FALSE;

        if ( !is_readable($odsFilePath) ) {
            return $ReturnFalse;
        }

if (strncmp(PHP_VERSION, '4', 1)) :
        $zip = new ZipArchive; // PHP5 or later.
        if ($zip->open($odsFilePath) !== TRUE) {
            return $ReturnFalse;
        }
        $fp = $zip->getStream('content.xml');
        //fpassthru($fp);
        $xmlString = '';
        while($s = fgets($fp)) {
            $xmlString .= $s;
        }
        fclose($fp);
        $zip->close();
else :
        $zip = zip_open($odsFilePath); // PHP4
        if (!is_resource($zip)) {
            return $ReturnFalse;
        }
        while($entry = zip_read($zip)) {
            if (zip_entry_name($entry) == 'content.xml')
                break;
        }
        $xmlString = '';
        while($s = zip_entry_read($entry)) {
            $xmlString .= $s;
        }
        zip_entry_close($entry);
        zip_close($zip);
endif;

        $this->_odsXml->loadXML($xmlString);
        $xmlString = $this->_processor->transformToXML($this->_odsXml);

        if ($returnType == self::READ_XMLSTRING or $returnType === 'string') {
            return $xmlString;
        }
        
        return $this->_toArray($xmlString, $returnType);
    }
}
?>
