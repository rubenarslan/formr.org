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

require_once 'OLERead.php';
class SpreadsheetReader_Excel extends SpreadsheetReader {
    /**
     * Sometimes, data will contain non-readable chars.
     * XML parser will occur a parse error.
     * So we need to strip those non-readable chars.
     */
    //private static $ignoreChar = false;

    /**
     * $sheets = read('~/example.xls');
     * $sheet = 0;
     * $row = 0;
     * $column = 0;
     * echo $sheets[$sheet][$row][$column];
     *
     * @param $xlsFilePath  File path of Excel sheet file.
     * @param $returnType   Type of return value.
     *      READ_ARRAY  - Default. Return an numeric index array.
     *      READ_NUM    - Same as READ_ARRAY
     *      READ_ASSOC  - Return an associative array.
     *                    It will use values of first row to be field name.
     *                    Though the count of rows will less one than numeric index array.
     *      READ_HASH   - Same as READ_ASSOC
     *      READ_XMLSTRING - Return an XML String.
     * @return FALSE or an array contains sheets.
     */
    public function &read($xlsFilePath, $returnType = self::READ_ARRAY) {
        $oleread = new OLERead;
        if ($sheets =& $oleread->read($xlsFilePath)) {
            foreach ($sheets as &$sheet) {
                $cells =& $sheet['cells'];
                foreach (array_keys($sheet) as $k) {
                    unset($sheet[$k]);
                }
                $sheet = $cells;
            }
            unset($oleread);

            if (($returnType == self::READ_XMLSTRING) or
                ($returnType === 'string')
               )
            {
                $sheets = $this->asXml($sheets);
            }
            else if ($returnType == self::READ_HASH) {
                foreach ($sheets as &$sheet) {
                    $header = array_shift($sheet);
                    $numOfHeader = count($header);
                    foreach ($sheet as &$row) {
                        if (count($row) == $numOfHeader) {
                            $row = array_combine($header, $row);
                        }
                        else {
                            for ($i = 0; $i < $numOfHeader; ++$i) {
                                $row[$header[$i]] = (isset($row[$i])
                                    ? $row[$i]
                                    : null
                                );
                                unset($row[$i]);
                            }
                        }
                    }
                    /*
                    if (count($sheet) < 1)
                        only one row.
                    */
                }
            }
        }
        return $sheets;
    }
}
?>
