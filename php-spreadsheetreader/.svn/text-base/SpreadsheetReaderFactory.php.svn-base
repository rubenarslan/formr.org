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

require_once 'SpreadsheetReader.php';
class SpreadsheetReaderFactory {
    private function __construct() {
        throw new Exception('Could not allocate an instance of ' . __CLASS__);
    }

    private static $classNameMap = array(
        'ods' => array(
            'name' => 'SpreadsheetReader_OpenDocumentSheet',
            'path' => 'OpenDocumentSheet/SpreadsheetReader_OpenDocumentSheet'
        ),
        'csv' => array(
            'name' => 'SpreadsheetReader_CSV',
            'path' => 'CSV/SpreadsheetReader_CSV'
        ),
        'xls' => array(
            'name' => 'SpreadsheetReader_Excel',
            'path' => 'Excel/SpreadsheetReader_Excel'
        ),
        'xml' => array(
            'name' => 'SpreadsheetReader',
            'path' => 'SpreadsheetReader'
        ),
        'txt' => array(
            'name' => 'SpreadsheetReader_Text',
            'path' => 'Text/SpreadsheetReader_Text'
        )
    );

    /**
     *
     * @param   $filePath, $filePath of spreadsheet file, or $extName of spreadsheet.
     *          example: 'test.xls', or just 'xls'.
     *
     * @return  an instance of reader.
     * @access  public
     * @static
     */
    public static function &reader($filePath) {
        $returnFalse = FALSE;

        if (is_readable($filePath))
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        else if (!isset(self::$classNameMap[$ext = strtolower($filePath)]))
            return $returnFalse;

        if (isset(self::$classNameMap[$ext]['name'])) {
            $className = self::$classNameMap[$ext]['name'];
            require_once dirname(__FILE__) . '/' . self::$classNameMap[$ext]['path'] . '.php';
        }
        else {
            $className = 'SpreadsheetReader';
        }
        $sheetReader = new $className;
        return $sheetReader;
    }
}
?>
