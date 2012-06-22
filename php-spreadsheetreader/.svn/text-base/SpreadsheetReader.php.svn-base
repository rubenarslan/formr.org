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

class SpreadsheetReader {
    const READ_NUM = 0;
    const READ_ARRAY = 0;
    const READ_ASSOC = 1;
    const READ_HASH = 1;
    const READ_XMLSTRING = 3;

    private static function columnIndexKey(&$args) {
        extract($args, EXTR_REFS);
        return ($returnType == self::READ_ASSOC
            ? $fieldNameSet[$indexOfCol]
            : $indexOfCol
        );
    }

    private static function columnValue(&$col) {
        return trim((string)$col);
    }

    private static function paddingEmptyColumn(&$args, &$row) {
        extract($args, EXTR_REFS);
        $fnCount = count($fieldNameSet);
        if ($returnType == self::READ_ASSOC
            and $indexOfCol < $fnCount)
        {
            for ($paddingCount = $fnCount - $indexOfCol; $paddingCount; --$paddingCount) {
                $row[$fieldNameSet[$indexOfCol++]] = '';
            }
        }
    }

    //MS Excel2k: <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    protected static $excel2kNameSpace = 'urn:schemas-microsoft-com:office:spreadsheet';

    protected function &_excel2kXmlToArray(&$args) {
        extract($args, EXTR_REFS);

        foreach ($xml->Worksheet as $worksheet) {
            $sheet = $worksheet->Table;
            $results[$indexOfSheet] = array();
            $fieldNameSet = false;
            $indexOfRow = 0;
            foreach ($sheet->Row as $row) {
                $results[$indexOfSheet][$indexOfRow] = array();
                if ($returnType == self::READ_ASSOC and !$fieldNameSet) {
                    $fieldNameSet = array();
                    foreach ($row->Cell as $cell) {
                        $fieldNameSet[] = self::columnValue($cell->Data);
                    }
                    continue;
                }

                $rsRow =& $results[$indexOfSheet][$indexOfRow];
                $indexOfCol = 0;
                foreach ($row->Cell as $cell) {
                    $col = $cell->Data;
                    $cellAttrSet = $cell->attributes(self::$excel2kNameSpace);

                    if (isset($cellAttrSet['Index'])) {
                        $number = (int)$cellAttrSet['Index'] - 1;
                        while ($number > $indexOfCol) {
                            $rsRow[self::columnIndexKey($args)] = '';
                            ++$indexOfCol;
                        }
                        // attribute['Index'] is the column number of cell.
                        // For save space, it might ignore empty cells.
                        // example: values of column 2nd and 3rd are empty.
                        //   <Cell><Data>1</Data></Cell>
                        //   <Cell ss:Index="4"><Data>4</Data></Cell>
                        // Therefore we need put those empty cells back according to attribute['Index'].
                    }
                    $rsRow[self::columnIndexKey($args)] = self::columnValue($col);
                    ++$indexOfCol;
                }
                self::paddingEmptyColumn($args, $rsRow);
                ++$indexOfRow;
            }
            ++$indexOfSheet;
        }
        return $results;
    }

    protected function &_jxlXmlToArray(&$args) {
        extract($args, EXTR_REFS);

        foreach ($xml->sheet as $sheet) {
            $results[$indexOfSheet] = array();
            $fieldNameSet = false;
            $indexOfRow = 0;
            foreach ($sheet->row as $row) {
                $results[$indexOfSheet][$indexOfRow] = array();
                if ($returnType == self::READ_ASSOC and !$fieldNameSet) {
                    $fieldNameSet = array(); //reset
                    foreach ($row->col as $col) {
                        $fieldNameSet[] = self::columnValue($col);
                    }
                    continue;
                }

                $rsRow =& $results[$indexOfSheet][$indexOfRow];
                $indexOfCol = 0;
                foreach ($row->col as $col) {
                    if (isset($col['number'])) {
                        $number = (int)$col['number'];
                        while ($number > $indexOfCol) {
                            $rsRow[self::columnIndexKey($args)] = '';
                            ++$indexOfCol;
                        }
                        // attribute['number'] is the column number of cell.
                        // For save space, it might ignore empty cells.
                        // example: values of column 2nd and 3rd are empty.
                        //   <col number="0">4</col>
                        //   <col number="3">Dman</col>
                        // Therefore we need put those empty cells back according to attribute['number'].
                    }
                    $rsRow[self::columnIndexKey($args)] = self::columnValue($col);
                    ++$indexOfCol;
                }
                self::paddingEmptyColumn($args, $rsRow);
                ++$indexOfRow;
            }
            ++$indexOfSheet;
        }
        return $results;
    }

    protected function &_toArray(&$xmlString, $returnType = self::READ_ARRAY) {
        if (FALSE === ($xml = simplexml_load_string($xmlString))) {
            return $ReturnFalse; //FALSE
        }

        $nameSpaces = $xml->getDocNamespaces();
        if (isset($nameSpaces[''])
            and $nameSpaces[''] == self::$excel2kNameSpace)
        {
            //XML of Excel 2K/XP
            $toArray = '_excel2kXmlToArray';
        }
        else {
            $toArray = '_jxlXmlToArray';
        }

        $args = array(
            'xml' => &$xml,
            'results' => array(),
            'fieldNameSet' => false,
            'indexOfSheet' => 0,
            'indexOfRow' => 0,
            'indexOfCol' => 0,
            'returnType' => $returnType
        );
        return $this->$toArray($args);
    }

    /**
     * read an spreadsheet file.
     *
     * @param  $filePath    file path of spreadsheet.
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
    public function &read($filePath, $returnType = self::READ_ARRAY) {
        $returnFalse = FALSE;
        if (!is_readable($filePath)) {
            return $returnFalse;
        }
        $xmlString = file_get_contents($filePath);
        if ($returnType == self::READ_XMLSTRING or $returnType === 'string') {
            return $xmlString;
        }
        return $this->_toArray($xmlString, $returnType);
    }

    private static function convCellData(&$convArgs, &$data) {
        //extract($convArgs, EXTR_REFS);
        return ($convArgs['iconv']
            ? iconv($convArgs['sourceCharset'], 'utf-8//IGNORE', $data)
            : $data
        );
    }

    /**
     * make $sheets as Xml string (Excel XML format).
     *
     * @param  $sheets      An array of sheets.
     * @param  [$sourceCharset]
     *      It will always make an XML string encoded by UTF-8.
     *      If your source is not encoded by UTF-8, you need tell it
     *      for convert to UTF-8.
     *
     * @return  A XML string.
     */
    public function asXml(&$sheets, $sourceCharset = 'utf-8') {
        $doc = new SimpleXMLElement(
'<?xml version="1.0" encoding="utf-8"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40"></Workbook>'
);

        
        $convArgs = array(
            'sourceCharset' => &$sourceCharset,
            'iconv' => ($sourceCharset == 'utf-8' ? false : true)
        );

        $indexOfSheet = 0;
        foreach ($sheets as $sheet) :
            //<Worksheet ss:Name="Sheet1">
            //<Table>
            $worksheetNode = $doc->addChild('Worksheet');
            //$worksheetNode->addAttribute('ss:Name', 'sheet1');//BUG?
            $worksheetNode['ss:Name'] = 'sheet' . (++$indexOfSheet);

            $worksheetNode->Table = '';//add a child with value '' by setter
            //$tableNode = $worksheetNode->addChild('Table');/add a child by addChild()

            if ( !array_key_exists(0, $sheet[0]) ) {
                //an associative array, write header fields.
                $rowNode = $worksheetNode->Table->addChild('Row');
                foreach(array_keys($sheet[0]) as $fieldName) {
                    $cellNode = $rowNode->addChild('Cell');
                    $cellNode->Data = self::convCellData($convArgs, $fieldName);
                    $cellNode->Data['ss:Type'] = 'String';
                }
            }

            foreach ($sheet as $row) :
                //<Row>
                $rowNode = $worksheetNode->Table->addChild('Row');
                foreach ($row as $col) :
                    //<Cell><Data ss:Type="Number">1</Data></Cell>
                    $cellNode = $rowNode->addChild('Cell');
                    $cellNode->Data = self::convCellData($convArgs, $col);
                    $cellNode->Data['ss:Type'] = (
                        (!is_string($col) or (is_numeric($col) and $col[0] != '0'))
                        ? 'Number'
                        : 'String'
                    );
                endforeach;//$row as $col
            endforeach;//$sheet as $row
        endforeach;//$sheets as $sheet
        return $doc->asXML();
    }
}

//$reader = new SpreadsheetReader;
//$sheets = $reader->read('Excel/jxl_test.xml');
?>
