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
class SpreadsheetReader_CSV extends SpreadsheetReader {
    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
    }
    
    /**
     * $sheets = read('~/example.csv');
     * $sheet = 0;
     * $row = 0;
     * $column = 0;
     * echo $sheets[$sheet][$row][$column];
     *
     * @todo    return results as XML String.
     *          how to detect char encoding, or to convert to utf-8?
     *
     * @param $csvFilePath  File path of Open Document Sheet file.
     * @param $returnType   Type of return value.
     *                      'array':  Array. This is default.
     *                      'string': XML string.
     * @return FALSE or an array contains sheets.
     */
    public function &read($csvFilePath, $returnType = self::READ_ARRAY) {
        $ReturnFalse = FALSE;

        //strcmp(pathinfo($csvFilePath, PATHINFO_EXTENSION), 'csv')
        if (!is_readable($csvFilePath)) {
            return $ReturnFalse;
        }
        $fp = fopen($csvFilePath, 'r');

        $sheets[0] = array();  //there is only one sheet in csv.
        while ($row = fgetcsv($fp, 16384)) {
            $sheets[0][] = $row;
        }
        fclose($fp);

        if ($returnType == self::READ_XMLSTRING or $returnType === 'string') {
            throw new Exception('not implemented!');
            return $xmlString;
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
        return $sheets;
    }
}
?>
