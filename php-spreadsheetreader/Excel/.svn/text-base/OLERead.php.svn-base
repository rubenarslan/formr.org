<?php
/**
* A class for reading Microsoft Excel Spreadsheets.
*
* Originally developed by Vadim Tkachenko under the name PHPExcelReader.
* (http://sourceforge.net/projects/phpexcelreader)
* Based on the Java version by Andy Khan (http://www.andykhan.com)
* and the bug-fixed version by David Sanders.
* Reads only Biff 7 and Biff 8 formats.
*
*
* This edition is maintained by Shih Yuncheng. It merges oleread.inc
* and read.php into one class OLERead.
*
*
* PHP versions 5
*
* @category   Spreadsheet
* @package    SpreadsheetReader
* @author     Vadim Tkachenko <vt@apachephp.com>
* @author     Shih Yuncheng <shirock@educities.edu.tw>
* @license    GNU Lesser General Public License (LGPL) version 2.1 or later
* @link       http://sourceforge.net/projects/phpexcelreader/
* @see        OLE, Spreadsheet_Excel_Writer
*/

class OLERead {

    const NUM_BIG_BLOCK_DEPOT_BLOCKS_POS = 0x2c;
    const SMALL_BLOCK_DEPOT_BLOCK_POS = 0x3c;
    const ROOT_START_BLOCK_POS = 0x30;
    const BIG_BLOCK_SIZE = 0x200;
    const SMALL_BLOCK_SIZE = 0x40;
    const EXTENSION_BLOCK_POS = 0x44;
    const NUM_EXTENSION_BLOCK_POS = 0x48;
    const PROPERTY_STORAGE_BLOCK_SIZE = 0x80;
    const BIG_BLOCK_DEPOT_BLOCKS_POS = 0x4c;
    const SMALL_BLOCK_THRESHOLD = 0x1000;
    // property storage offsets
    const SIZE_OF_NAME_POS = 0x40;
    const TYPE_POS = 0x42;
    const START_BLOCK_POS = 0x74;
    const SIZE_POS = 0x78;
    const IDENTIFIER_OLE = "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1";


    const Spreadsheet_Excel_Reader_BIFF8 = 0x600;
    const Spreadsheet_Excel_Reader_BIFF7 = 0x500;
    const Spreadsheet_Excel_Reader_WorkbookGlobals = 0x5;
    const Spreadsheet_Excel_Reader_Worksheet = 0x10;

    const Spreadsheet_Excel_Reader_Type_BOF = 0x809;
    const Spreadsheet_Excel_Reader_Type_EOF = 0x0a;
    const Spreadsheet_Excel_Reader_Type_BOUNDSHEET = 0x85;
    const Spreadsheet_Excel_Reader_Type_DIMENSION = 0x200;
    const Spreadsheet_Excel_Reader_Type_ROW = 0x208;
    const Spreadsheet_Excel_Reader_Type_DBCELL = 0xd7;
    const Spreadsheet_Excel_Reader_Type_FILEPASS = 0x2f;
    const Spreadsheet_Excel_Reader_Type_NOTE = 0x1c;
    const Spreadsheet_Excel_Reader_Type_TXO = 0x1b6;
    const Spreadsheet_Excel_Reader_Type_RK = 0x7e;
    const Spreadsheet_Excel_Reader_Type_RK2 = 0x27e;
    const Spreadsheet_Excel_Reader_Type_MULRK = 0xbd;
    const Spreadsheet_Excel_Reader_Type_MULBLANK = 0xbe;
    const Spreadsheet_Excel_Reader_Type_INDEX = 0x20b;
    const Spreadsheet_Excel_Reader_Type_SST = 0xfc;
    const Spreadsheet_Excel_Reader_Type_EXTSST = 0xff;
    const Spreadsheet_Excel_Reader_Type_CONTINUE = 0x3c;
    const Spreadsheet_Excel_Reader_Type_LABEL = 0x204;
    const Spreadsheet_Excel_Reader_Type_LABELSST = 0xfd;
    const Spreadsheet_Excel_Reader_Type_NUMBER = 0x203;
    const Spreadsheet_Excel_Reader_Type_NAME = 0x18;
    const Spreadsheet_Excel_Reader_Type_ARRAY = 0x221;
    const Spreadsheet_Excel_Reader_Type_STRING = 0x207;
    const Spreadsheet_Excel_Reader_Type_FORMULA = 0x406;
    const Spreadsheet_Excel_Reader_Type_FORMULA2 = 0x6;
    const Spreadsheet_Excel_Reader_Type_FORMAT = 0x41e;
    const Spreadsheet_Excel_Reader_Type_XF = 0xe0;
    const Spreadsheet_Excel_Reader_Type_BOOLERR = 0x205;
    const Spreadsheet_Excel_Reader_Type_UNKNOWN = 0xffff;
    const Spreadsheet_Excel_Reader_Type_NINETEENFOUR = 0x22;
    const Spreadsheet_Excel_Reader_Type_MERGEDCELLS = 0xE5;

    const Spreadsheet_Excel_Reader_utcOffsetDays = 25569;
    const Spreadsheet_Excel_Reader_utcOffsetDays1904 = 24107;
    const Spreadsheet_Excel_Reader_msInADay = 86400/*24 * 60 * 60*/;

    //define('Spreadsheet_Excel_Reader_DEF_NUM_FORMAT', "%.2f");
    const Spreadsheet_Excel_Reader_DEF_NUM_FORMAT = "%s";

    /**
     * The data returned by OLE
     *
     * @var string
     * @access public
     */
    var $data = '';

    /**
     * Array of worksheets found
     *
     * @var array
     * @access public
     */
    var $boundsheets = array();

    /**
     * Array of format records found
     *
     * @var array
     * @access public
     */
    var $formatRecords = array();

    var $sst = array();

    /**
     * Array of worksheets
     *
     * The data is stored in 'cells' and the meta-data is stored in an array
     * called 'cellsInfo'
     *
     * Example:
     *
     * $sheets  -->  'cells'  -->  row --> column --> Interpreted value
     *          -->  'cellsInfo' --> row --> column --> 'type' - Can be 'date', 'number', or 'unknown'
     *                                            --> 'raw' - The raw data that Excel stores for that data cell
     *
     * @var array
     * @access public
     */
    var $sheets = array();

    /**
     * Default encoding
     *
     * @var string
     * @access private
     */
    private $_defaultEncoding = 'utf-8';

    /**
     * Default number format
     *
     * @var integer
     * @access private
     */
    private $_defaultFormat = self::Spreadsheet_Excel_Reader_DEF_NUM_FORMAT;

    /**
     * List of formats to use for each column
     *
     * @var array
     * @access private
     */
    private $_columnsFormat = array();

    private $_rowoffset = 0;
    private $_coloffset = 0;

    /**
     * List of default date formats used by Excel
     *
     * @var array
     * @access public
     */
    var $dateFormats = array (
        0xe => "d/m/Y",
        0xf => "d-M-Y",
        0x10 => "d-M",
        0x11 => "M-Y",
        0x12 => "h:i a",
        0x13 => "h:i:s a",
        0x14 => "H:i",
        0x15 => "H:i:s",
        0x16 => "d/m/Y H:i",
        0x2d => "i:s",
        0x2e => "H:i:s",
        0x2f => "i:s.S");

    /**
     * Default number formats used by Excel
     *
     * @var array
     * @access public
     */
    var $numberFormats = array(
        0x1 => "%1.0f", // "0"
        0x2 => "%1.2f", // "0.00",
        0x3 => "%1.0f", //"#,##0",
        0x4 => "%1.2f", //"#,##0.00",
        0x5 => "%1.0f", /*"$#,##0;($#,##0)",*/
        0x6 => '$%1.0f', /*"$#,##0;($#,##0)",*/
        0x7 => '$%1.2f', //"$#,##0.00;($#,##0.00)",
        0x8 => '$%1.2f', //"$#,##0.00;($#,##0.00)",
        0x9 => '%1.0f%%', // "0%"
        0xa => '%1.2f%%', // "0.00%"
        0xb => '%1.2f', // 0.00E00",
        0x25 => '%1.0f', // "#,##0;(#,##0)",
        0x26 => '%1.0f', //"#,##0;(#,##0)",
        0x27 => '%1.2f', //"#,##0.00;(#,##0.00)",
        0x28 => '%1.2f', //"#,##0.00;(#,##0.00)",
        0x29 => '%1.0f', //"#,##0;(#,##0)",
        0x2a => '$%1.0f', //"$#,##0;($#,##0)",
        0x2b => '%1.2f', //"#,##0.00;(#,##0.00)",
        0x2c => '$%1.2f', //"$#,##0.00;($#,##0.00)",
        0x30 => '%1.0f'); //"##0.0E0";

    
    public function __construct() {
        //$this->_ole =& new OLERead();
        $this->setUTFEncoder('iconv');
    }

    /**
     * Set the encoding method
     *
     * @param string Encoding to use
     * @access public
     */
    public function setOutputEncoding($Encoding){
        $this->_defaultEncoding = $Encoding;
    }

    /**
     * OLE read
     *
     * @param string file to read
     * @access protected
     */
    protected function _read($sFileName) {
    	// check if file exist and is readable (Darko Miljanovic)
    	if(!is_readable($sFileName)) {
    		$this->error = 1;
    		return false;
    	}
    	$this->data = @file_get_contents($sFileName);
    	if (!$this->data) {
    		$this->error = 1; 
    		return false; 
   		}
   		//echo IDENTIFIER_OLE;
   		//echo 'start';
   		if (substr($this->data, 0, 8) != self::IDENTIFIER_OLE) {
    		$this->error = 1; 
    		return false; 
   		}

        $this->numBigBlockDepotBlocks = $this->_GetInt4d($this->data, self::NUM_BIG_BLOCK_DEPOT_BLOCKS_POS);
        $this->sbdStartBlock = $this->_GetInt4d($this->data, self::SMALL_BLOCK_DEPOT_BLOCK_POS);
        $this->rootStartBlock = $this->_GetInt4d($this->data, self::ROOT_START_BLOCK_POS);
        $this->extensionBlock = $this->_GetInt4d($this->data, self::EXTENSION_BLOCK_POS);
        $this->numExtensionBlocks = $this->_GetInt4d($this->data, self::NUM_EXTENSION_BLOCK_POS);
        
    	/*
        echo $this->numBigBlockDepotBlocks." ";
        echo $this->sbdStartBlock." ";
        echo $this->rootStartBlock." ";
        echo $this->extensionBlock." ";
        echo $this->numExtensionBlocks." ";
        */
        //echo "sbdStartBlock = $this->sbdStartBlock\n";
        $bigBlockDepotBlocks = array();
        $pos = self::BIG_BLOCK_DEPOT_BLOCKS_POS;
        // echo "pos = $pos";
    	$bbdBlocks = $this->numBigBlockDepotBlocks;
        
            if ($this->numExtensionBlocks != 0) {
                $bbdBlocks = (self::BIG_BLOCK_SIZE - self::BIG_BLOCK_DEPOT_BLOCKS_POS)/4;
            }
        
        for ($i = 0; $i < $bbdBlocks; $i++) {
              $bigBlockDepotBlocks[$i] = $this->_GetInt4d($this->data, $pos);
              $pos += 4;
        }
        
        
        for ($j = 0; $j < $this->numExtensionBlocks; $j++) {
            $pos = ($this->extensionBlock + 1) * self::BIG_BLOCK_SIZE;
            $blocksToRead = min($this->numBigBlockDepotBlocks - $bbdBlocks, self::BIG_BLOCK_SIZE / 4 - 1);

            for ($i = $bbdBlocks; $i < $bbdBlocks + $blocksToRead; $i++) {
                $bigBlockDepotBlocks[$i] = $this->_GetInt4d($this->data, $pos);
                $pos += 4;
            }   

            $bbdBlocks += $blocksToRead;
            if ($bbdBlocks < $this->numBigBlockDepotBlocks) {
                $this->extensionBlock = $this->_GetInt4d($this->data, $pos);
            }
        }

       // var_dump($bigBlockDepotBlocks);
        
        // readBigBlockDepot
        $pos = 0;
        $index = 0;
        $this->bigBlockChain = array();
        
        for ($i = 0; $i < $this->numBigBlockDepotBlocks; $i++) {
            $pos = ($bigBlockDepotBlocks[$i] + 1) * self::BIG_BLOCK_SIZE;
            //echo "pos = $pos";	
            for ($j = 0 ; $j < self::BIG_BLOCK_SIZE / 4; $j++) {
                $this->bigBlockChain[$index] = $this->_GetInt4d($this->data, $pos);
                $pos += 4 ;
                $index++;
            }
        }

	//var_dump($this->bigBlockChain);
        //echo '=====2';
        // readSmallBlockDepot();
        $pos = 0;
	    $index = 0;
	    $sbdBlock = $this->sbdStartBlock;
	    $this->smallBlockChain = array();
	
	    while ($sbdBlock != -2) {
	
	      $pos = ($sbdBlock + 1) * self::BIG_BLOCK_SIZE;
	
	      for ($j = 0; $j < self::BIG_BLOCK_SIZE / 4; $j++) {
	        $this->smallBlockChain[$index] = $this->_GetInt4d($this->data, $pos);
	        $pos += 4;
	        $index++;
	      }
	
	      $sbdBlock = $this->bigBlockChain[$sbdBlock];
	    }

        
        // readData(rootStartBlock)
        $block = $this->rootStartBlock;
        $pos = 0;
        $this->entry =& $this->__readData($block);
        
        /*
        while ($block != -2)  {
            $pos = ($block + 1) * BIG_BLOCK_SIZE;
            $this->entry = $this->entry.substr($this->data, $pos, BIG_BLOCK_SIZE);
            $block = $this->bigBlockChain[$block];
        }
        */
        //echo '==='.$this->entry."===";
        $this->__readPropertySets();

    }
    
    protected function _GetInt4d($data, $pos)
    {
    	$value = ord($data[$pos]) | (ord($data[$pos+1])	<< 8) | (ord($data[$pos+2]) << 16) | (ord($data[$pos+3]) << 24);
    	if ($value>=4294967294)
    	{
    		$value=-2;
    	}
    	return $value;
    }

    protected function &__readData($bl) {
        $block = $bl;
        $pos = 0;
        $data = '';
        
        while ($block != -2)  {
            $pos = ($block + 1) * self::BIG_BLOCK_SIZE;
            $data .= substr($this->data, $pos, self::BIG_BLOCK_SIZE);
            //echo "pos = $pos data=$data\n";	
	       $block = $this->bigBlockChain[$block];
        }
		return $data;
    }
        
    protected function __readPropertySets(){
        $offset = 0;
        //var_dump($this->entry);
        while ($offset < strlen($this->entry)) {
            $d = substr($this->entry, $offset, self::PROPERTY_STORAGE_BLOCK_SIZE);
            
            $nameSize = ord($d[self::SIZE_OF_NAME_POS]) | (ord($d[self::SIZE_OF_NAME_POS+1]) << 8);
              
            $type = ord($d[self::TYPE_POS]);
            //$maxBlock = strlen($d) / BIG_BLOCK_SIZE - 1;
        
            $startBlock = $this->_GetInt4d($d, self::START_BLOCK_POS);
            $size = $this->_GetInt4d($d, self::SIZE_POS);
        
            $name = '';
            for ($i = 0; $i < $nameSize ; $i++) {
                $name .= $d[$i];
            }
            
            $name = str_replace("\x00", "", $name);
            
            $this->props[] = array (
                'name' => $name, 
                'type' => $type,
                'startBlock' => $startBlock,
                'size' => $size);

            if (($name == "Workbook") || ($name == "Book")) {
                $this->wrkbook = count($this->props) - 1;
            }

            if ($name == "Root Entry") {
                $this->rootentry = count($this->props) - 1;
            }
            
            //echo "name ==$name=\n";

            
            $offset += self::PROPERTY_STORAGE_BLOCK_SIZE;
        }   
        
    }
    
    
    protected function &getWorkBook(){
    	if ($this->props[$this->wrkbook]['size'] < self::SMALL_BLOCK_THRESHOLD){
//    	  getSmallBlockStream(PropertyStorage ps)

			$rootdata =& $this->__readData($this->props[$this->rootentry]['startBlock']);
	        
			$streamData = '';
	        $block = $this->props[$this->wrkbook]['startBlock'];
	        //$count = 0;
	        $pos = 0;
		    while ($block != -2) {
      	          $pos = $block * self::SMALL_BLOCK_SIZE;
		          $streamData .= substr($rootdata, $pos, self::SMALL_BLOCK_SIZE);

			      $block = $this->smallBlockChain[$block];
		    }
			
		    return $streamData;
    		

    	}else{
    	
	        $numBlocks = $this->props[$this->wrkbook]['size'] / self::BIG_BLOCK_SIZE;
	        if ($this->props[$this->wrkbook]['size'] % self::BIG_BLOCK_SIZE != 0) {
	            $numBlocks++;
	        }
	        
	        if ($numBlocks == 0) {
                $streamData = '';
                return $streamData;
            }
	        
	        //echo "numBlocks = $numBlocks\n";
	        //byte[] streamData = new byte[numBlocks * BIG_BLOCK_SIZE];
	        //print_r($this->wrkbook);
	        $streamData = '';
	        $block = $this->props[$this->wrkbook]['startBlock'];
	        //$count = 0;
	        $pos = 0;
	        //echo "block = $block";
	        while ($block != -2) {
	          $pos = ($block + 1) * self::BIG_BLOCK_SIZE;
	          $streamData .= substr($this->data, $pos, self::BIG_BLOCK_SIZE);
	          $block = $this->bigBlockChain[$block];
	        }   
	        //echo 'stream'.$streamData;
	        return $streamData;
    	}
    }


    /**
     *  $encoder = 'iconv' or 'mb'
     *  set iconv if you would like use 'iconv' for encode UTF-16LE to your encoding
     *  set mb if you would like use 'mb_convert_encoding' for encode UTF-16LE to your encoding
     *
     * @access public
     * @param string Encoding type to use.  Either 'iconv' or 'mb'
     */
    public function setUTFEncoder($encoder = 'iconv'){
    	$this->_encoderFunction = '';
    	if ($encoder == 'iconv'){
        	$this->_encoderFunction = function_exists('iconv') ? 'iconv' : '';
        }elseif ($encoder == 'mb') {
        	$this->_encoderFunction = function_exists('mb_convert_encoding') ? 'mb_convert_encoding' : '';
    	}
    }

    /**
     * todo
     *
     * @access public
     * @param offset
     */
    public function setRowColOffset($iOffset){
        $this->_rowoffset = $iOffset;
		$this->_coloffset = $iOffset;
    }

    /**
     * Set the default number format
     *
     * @access public
     * @param Default format
     */
    public function setDefaultFormat($sFormat){
        $this->_defaultFormat = $sFormat;
    }

    /**
     * Force a column to use a certain format
     *
     * @access public
     * @param integer Column number
     * @param string Format
     */
    public function setColumnFormat($column, $sFormat){
        $this->_columnsFormat[$column] = $sFormat;
    }

    /**
     * Read the spreadsheet file using OLE, then parse
     *
     * @access public
     * @param filename
     * @return array of sheets or false
     */
    public function &read($sFileName) {
        $res = $this->_read($sFileName);

        // oops, something goes wrong (Darko Miljanovic)
        if($res === false) {
        	// check error code
        	if($this->error == 1) {
        	// bad file
        		//die('The filename ' . $sFileName . ' is not readable');
        		return $res; //false
        	}
        	// check other error codes here (eg bad fileformat, etc...)
        }

        $this->data =& $this->getWorkBook();

        $this->_parse();
        return $this->sheets;
    }

    /**
     * Parse a workbook
     *
     * @access private
     * @return bool
     */
    protected function _parse(){
        $pos = 0;

        $code = ord($this->data[$pos]) | ord($this->data[$pos+1])<<8;
        $length = ord($this->data[$pos+2]) | ord($this->data[$pos+3])<<8;

        $version = ord($this->data[$pos + 4]) | ord($this->data[$pos + 5])<<8;
        $substreamType = ord($this->data[$pos + 6]) | ord($this->data[$pos + 7])<<8;
        //echo "Start parse code=".base_convert($code,10,16)." version=".base_convert($version,10,16)." substreamType=".base_convert($substreamType,10,16).""."\n";

        if (($version != self::Spreadsheet_Excel_Reader_BIFF8) &&
            ($version != self::Spreadsheet_Excel_Reader_BIFF7))
        {
            return false;
        }

        if ($substreamType != self::Spreadsheet_Excel_Reader_WorkbookGlobals){
            return false;
        }

        //print_r($rec);
        $pos += $length + 4;

        $code = ord($this->data[$pos]) | ord($this->data[$pos+1])<<8;
        $length = ord($this->data[$pos+2]) | ord($this->data[$pos+3])<<8;

        while ($code != self::Spreadsheet_Excel_Reader_Type_EOF){
            switch ($code) {
                case self::Spreadsheet_Excel_Reader_Type_SST:
                    //echo "Type_SST\n";
                     $spos = $pos + 4;
                     $limitpos = $spos + $length;
                     $uniqueStrings = $this->_GetInt4d($this->data, $spos+4);
                                                $spos += 8;
                                       for ($i = 0; $i < $uniqueStrings; $i++) {
        // Read in the number of characters
                                                if ($spos == $limitpos) {
                                                $opcode = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                                                $conlength = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                                                        if ($opcode != 0x3c) {
                                                                return -1;
                                                        }
                                                $spos += 4;
                                                $limitpos = $spos + $conlength;
                                                }
                                                $numChars = ord($this->data[$spos]) | (ord($this->data[$spos+1]) << 8);
                                                //echo "i = $i pos = $pos numChars = $numChars ";
                                                $spos += 2;
                                                $optionFlags = ord($this->data[$spos]);
                                                $spos++;
                                                $asciiEncoding = (($optionFlags & 0x01) == 0) ;
                                                $extendedString = ( ($optionFlags & 0x04) != 0);

                                                // See if string contains formatting information
                                                $richString = ( ($optionFlags & 0x08) != 0);

                                                if ($richString) {
                                        // Read in the crun
                                                        $formattingRuns = ord($this->data[$spos]) | (ord($this->data[$spos+1]) << 8);
                                                        $spos += 2;
                                                }

                                                if ($extendedString) {
                                                  // Read in cchExtRst
                                                  $extendedRunLength = $this->_GetInt4d($this->data, $spos);
                                                  $spos += 4;
                                                }

                                                $len = ($asciiEncoding)? $numChars : $numChars*2;
                                                if ($spos + $len < $limitpos) {
                                                                $retstr = substr($this->data, $spos, $len);
                                                                $spos += $len;
                                                }else{
                                                        // found countinue
                                                        $retstr = substr($this->data, $spos, $limitpos - $spos);
                                                        $bytesRead = $limitpos - $spos;
                                                        $charsLeft = $numChars - (($asciiEncoding) ? $bytesRead : ($bytesRead / 2));
                                                        $spos = $limitpos;

                                                         while ($charsLeft > 0){
                                                                $opcode = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                                                                $conlength = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                                                                        if ($opcode != 0x3c) {
                                                                                return -1;
                                                                        }
                                                                $spos += 4;
                                                                $limitpos = $spos + $conlength;
                                                                $option = ord($this->data[$spos]);
                                                                $spos += 1;
                                                                  if ($asciiEncoding && ($option == 0)) {
                                                                                $len = min($charsLeft, $limitpos - $spos); // min($charsLeft, $conlength);
                                                                    $retstr .= substr($this->data, $spos, $len);
                                                                    $charsLeft -= $len;
                                                                    $asciiEncoding = true;
                                                                  }elseif (!$asciiEncoding && ($option != 0)){
                                                                                $len = min($charsLeft * 2, $limitpos - $spos); // min($charsLeft, $conlength);
                                                                    $retstr .= substr($this->data, $spos, $len);
                                                                    $charsLeft -= $len/2;
                                                                    $asciiEncoding = false;
                                                                  }elseif (!$asciiEncoding && ($option == 0)) {
                                                                // Bummer - the string starts off as Unicode, but after the
                                                                // continuation it is in straightforward ASCII encoding
                                                                                $len = min($charsLeft, $limitpos - $spos); // min($charsLeft, $conlength);
                                                                        for ($j = 0; $j < $len; $j++) {
                                                                 $retstr .= $this->data[$spos + $j].chr(0);
                                                                }
                                                            $charsLeft -= $len;
                                                                $asciiEncoding = false;
                                                                  }else{
                                                            $newstr = '';
                                                                    for ($j = 0; $j < strlen($retstr); $j++) {
                                                                      $newstr = $retstr[$j].chr(0);
                                                                    }
                                                                    $retstr = $newstr;
                                                                                $len = min($charsLeft * 2, $limitpos - $spos); // min($charsLeft, $conlength);
                                                                    $retstr .= substr($this->data, $spos, $len);
                                                                    $charsLeft -= $len/2;
                                                                    $asciiEncoding = false;
                                                                        //echo "Izavrat\n";
                                                                  }
                                                          $spos += $len;

                                                         }
                                                }
                                                $retstr = ($asciiEncoding) ? $retstr : $this->_encodeUTF16($retstr);
//                                              echo "Str $i = $retstr\n";
                                        if ($richString){
                                                  $spos += 4 * $formattingRuns;
                                                }

                                                // For extended strings, skip over the extended string data
                                                if ($extendedString) {
                                                  $spos += $extendedRunLength;
                                                }
                                                        //if ($retstr == 'Derby'){
                                                        //      echo "bb\n";
                                                        //}
                                                $this->sst[]=$retstr;
                                       }
                    /*$continueRecords = array();
                    while ($this->getNextCode() == Type_CONTINUE) {
                        $continueRecords[] = &$this->nextRecord();
                    }
                    //echo " 1 Type_SST\n";
                    $this->shareStrings = new SSTRecord($r, $continueRecords);
                    //print_r($this->shareStrings->strings);
                     */
                     // echo 'SST read: '.($time_end-$time_start)."\n";
                    break;

                case self::Spreadsheet_Excel_Reader_Type_FILEPASS:
                    return false;
                    break;
                case self::Spreadsheet_Excel_Reader_Type_NAME:
                    //echo "Type_NAME\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_FORMAT:
                        $indexCode = ord($this->data[$pos+4]) | ord($this->data[$pos+5]) << 8;

                        if ($version == self::Spreadsheet_Excel_Reader_BIFF8) {
                            $numchars = ord($this->data[$pos+6]) | ord($this->data[$pos+7]) << 8;
                            if (ord($this->data[$pos+8]) == 0){
                                $formatString = substr($this->data, $pos+9, $numchars);
                            } else {
                                $formatString = substr($this->data, $pos+9, $numchars*2);
                            }
                        } else {
                            $numchars = ord($this->data[$pos+6]);
                            $formatString = substr($this->data, $pos+7, $numchars*2);
                        }

                    $this->formatRecords[$indexCode] = $formatString;
                   // echo "Type.FORMAT\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_XF:
                        //global $dateFormats, $numberFormats;
                        $indexCode = ord($this->data[$pos+6]) | ord($this->data[$pos+7]) << 8;
                        //echo "\nType.XF ".count($this->formatRecords['xfrecords'])." $indexCode ";
                        if (array_key_exists($indexCode, $this->dateFormats)) {
                            //echo "isdate ".$dateFormats[$indexCode];
                            $this->formatRecords['xfrecords'][] = array(
                                    'type' => 'date',
                                    'format' => $this->dateFormats[$indexCode]
                                    );
                        }elseif (array_key_exists($indexCode, $this->numberFormats)) {
                        //echo "isnumber ".$this->numberFormats[$indexCode];
                            $this->formatRecords['xfrecords'][] = array(
                                    'type' => 'number',
                                    'format' => $this->numberFormats[$indexCode]
                                    );
                        }else{
                            $isdate = FALSE;
                            if ($indexCode > 0){
                            	if (isset($this->formatRecords[$indexCode]))
                                	$formatstr = $this->formatRecords[$indexCode];
                                //echo '.other.';
                                //echo "\ndate-time=$formatstr=\n";
                                if ($formatstr)
                                if (preg_match("/[^hmsday\/\-:\s]/i", $formatstr) == 0) { // found day and time format
                                    $isdate = TRUE;
                                    $formatstr = str_replace('mm', 'i', $formatstr);
                                    $formatstr = str_replace('h', 'H', $formatstr);
                                    //echo "\ndate-time $formatstr \n";
                                }
                            }

                            if ($isdate){
                                $this->formatRecords['xfrecords'][] = array(
                                        'type' => 'date',
                                        'format' => $formatstr,
                                        );
                            }else{
                                $this->formatRecords['xfrecords'][] = array(
                                        'type' => 'other',
                                        'format' => '',
                                        'code' => $indexCode
                                        );
                            }
                        }
                        //echo "\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_NINETEENFOUR:
                    //echo "Type.NINETEENFOUR\n";
                    $this->nineteenFour = (ord($this->data[$pos+4]) == 1);
                    break;
                case self::Spreadsheet_Excel_Reader_Type_BOUNDSHEET:
                    //echo "Type.BOUNDSHEET\n";
                        $rec_offset = $this->_GetInt4d($this->data, $pos+4);
                        $rec_typeFlag = ord($this->data[$pos+8]);
                        $rec_visibilityFlag = ord($this->data[$pos+9]);
                        $rec_length = ord($this->data[$pos+10]);

                        if ($version == self::Spreadsheet_Excel_Reader_BIFF8){
                            $chartype =  ord($this->data[$pos+11]);
                            if ($chartype == 0){
                                $rec_name    = substr($this->data, $pos+12, $rec_length);
                            } else {
                                $rec_name    =& $this->_encodeUTF16(substr($this->data, $pos+12, $rec_length*2));
                            }
                        }elseif ($version == self::Spreadsheet_Excel_Reader_BIFF7){
                                $rec_name    = substr($this->data, $pos+11, $rec_length);
                        }
                    $this->boundsheets[] = array('name'=>$rec_name,
                                                 'offset'=>$rec_offset);

                    break;

            }

            //echo "Code = ".base_convert($r['code'],10,16)."\n";
            $pos += $length + 4;
            $code = ord($this->data[$pos]) | ord($this->data[$pos+1])<<8;
            $length = ord($this->data[$pos+2]) | ord($this->data[$pos+3])<<8;

            //$r = &$this->nextRecord();
            //echo "1 Code = ".base_convert($r['code'],10,16)."\n";
        }

        foreach ($this->boundsheets as $key=>$val){
            $this->sn = $key;
            $this->_parsesheet($val['offset']);
        }
        return true;

    }

    protected function _parsesheet($spos){
        $cont = true;
        // read BOF
        $code = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
        $length = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;

        $version = ord($this->data[$spos + 4]) | ord($this->data[$spos + 5])<<8;
        $substreamType = ord($this->data[$spos + 6]) | ord($this->data[$spos + 7])<<8;

        if (($version != self::Spreadsheet_Excel_Reader_BIFF8) && ($version != self::Spreadsheet_Excel_Reader_BIFF7)) {
            return -1;
        }

        if ($substreamType != self::Spreadsheet_Excel_Reader_Worksheet){
            return -2;
        }
        //echo "Start parse code=".base_convert($code,10,16)." version=".base_convert($version,10,16)." substreamType=".base_convert($substreamType,10,16).""."\n";
        $spos += $length + 4;
        //var_dump($this->formatRecords);
	//echo "code $code $length";
        while($cont) {
            //echo "mem= ".memory_get_usage()."\n";
//            $r = &$this->file->nextRecord();
            $lowcode = ord($this->data[$spos]);
            if ($lowcode == self::Spreadsheet_Excel_Reader_Type_EOF) break;
            $code = $lowcode | ord($this->data[$spos+1])<<8;
            $length = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
            $spos += 4;
            $this->sheets[$this->sn]['maxrow'] = $this->_rowoffset - 1;
            $this->sheets[$this->sn]['maxcol'] = $this->_coloffset - 1;
            //echo "Code=".base_convert($code,10,16)." $code\n";
            unset($this->rectype);
            $this->multiplier = 1; // need for format with %
            switch ($code) {
                case self::Spreadsheet_Excel_Reader_Type_DIMENSION:
                    //echo 'Type_DIMENSION ';
                    if (!isset($this->numRows)) {
                        if (($length == 10) ||  ($version == self::Spreadsheet_Excel_Reader_BIFF7)){
                            $this->sheets[$this->sn]['numRows'] = ord($this->data[$spos+2]) | ord($this->data[$spos+3]) << 8;
                            $this->sheets[$this->sn]['numCols'] = ord($this->data[$spos+6]) | ord($this->data[$spos+7]) << 8;
                        } else {
                            $this->sheets[$this->sn]['numRows'] = ord($this->data[$spos+4]) | ord($this->data[$spos+5]) << 8;
                            $this->sheets[$this->sn]['numCols'] = ord($this->data[$spos+10]) | ord($this->data[$spos+11]) << 8;
                        }
                    }
                    //echo 'numRows '.$this->numRows.' '.$this->numCols."\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_MERGEDCELLS:
                    $cellRanges = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    for ($i = 0; $i < $cellRanges; $i++) {
                        $fr =  ord($this->data[$spos + 8*$i + 2]) | ord($this->data[$spos + 8*$i + 3])<<8;
                        $lr =  ord($this->data[$spos + 8*$i + 4]) | ord($this->data[$spos + 8*$i + 5])<<8;
                        $fc =  ord($this->data[$spos + 8*$i + 6]) | ord($this->data[$spos + 8*$i + 7])<<8;
                        $lc =  ord($this->data[$spos + 8*$i + 8]) | ord($this->data[$spos + 8*$i + 9])<<8;
                        //$this->sheets[$this->sn]['mergedCells'][] = array($fr + 1, $fc + 1, $lr + 1, $lc + 1);
                        if ($lr - $fr > 0) {
                            $this->sheets[$this->sn]['cellsInfo'][$fr+1][$fc+1]['rowspan'] = $lr - $fr + 1;
                        }
                        if ($lc - $fc > 0) {
                            $this->sheets[$this->sn]['cellsInfo'][$fr+1][$fc+1]['colspan'] = $lc - $fc + 1;
                        }
                    }
                    //echo "Merged Cells $cellRanges $lr $fr $lc $fc\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_RK:
                case self::Spreadsheet_Excel_Reader_Type_RK2:
                    //echo 'Spreadsheet_Excel_Reader_Type_RK'."\n";
                    $row = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $column = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                    $rknum = $this->_GetInt4d($this->data, $spos + 6);
                    $numValue = $this->_GetIEEE754($rknum);
                    //echo $numValue." ";
                    if ($this->isDate($spos)) {
                        list($string, $raw) = $this->createDate($numValue);
                    }else{
                        $raw = $numValue;
                        if (isset($this->_columnsFormat[$column + 1])){
                                $this->curformat = $this->_columnsFormat[$column + 1];
                        }
                        $string = sprintf($this->curformat, $numValue * $this->multiplier);
                        //$this->addcell(RKRecord($r));
                    }
                    $this->addcell($row, $column, $string, $raw);
                    //echo "Type_RK $row $column $string $raw {$this->curformat}\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_LABELSST:
                        $row        = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                        $column     = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                        $xfindex    = ord($this->data[$spos+4]) | ord($this->data[$spos+5])<<8;
                        $index  = $this->_GetInt4d($this->data, $spos + 6);
			//var_dump($this->sst);
                        $this->addcell($row, $column, $this->sst[$index]);
                        //echo "LabelSST $row $column $string\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_MULRK:
                    $row        = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $colFirst   = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                    $colLast    = ord($this->data[$spos + $length - 2]) | ord($this->data[$spos + $length - 1])<<8;
                    $columns    = $colLast - $colFirst + 1;
                    $tmppos = $spos+4;
                    for ($i = 0; $i < $columns; $i++) {
                        $numValue = $this->_GetIEEE754($this->_GetInt4d($this->data, $tmppos + 2));
                        if ($this->isDate($tmppos-4)) {
                            list($string, $raw) = $this->createDate($numValue);
                        }else{
                            $raw = $numValue;
                            if (isset($this->_columnsFormat[$colFirst + $i + 1])){
                                        $this->curformat = $this->_columnsFormat[$colFirst + $i + 1];
                                }
                            $string = sprintf($this->curformat, $numValue * $this->multiplier);
                        }
                      //$rec['rknumbers'][$i]['xfindex'] = ord($rec['data'][$pos]) | ord($rec['data'][$pos+1]) << 8;
                      $tmppos += 6;
                      $this->addcell($row, $colFirst + $i, $string, $raw);
                      //echo "MULRK $row ".($colFirst + $i)." $string\n";
                    }
                     //MulRKRecord($r);
                    // Get the individual cell records from the multiple record
                     //$num = ;

                    break;
                case self::Spreadsheet_Excel_Reader_Type_NUMBER:
                    $row    = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $column = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                    $tmp = unpack("ddouble", substr($this->data, $spos + 6, 8)); // It machine machine dependent
                    if ($this->isDate($spos)) {
                        list($string, $raw) = $this->createDate($tmp['double']);
                     //   $this->addcell(DateRecord($r, 1));
                    }else{
                        //$raw = $tmp[''];
                        if (isset($this->_columnsFormat[$column + 1])){
                                $this->curformat = $this->_columnsFormat[$column + 1];
                        }
                        $raw = $this->createNumber($spos);
                        $string = sprintf($this->curformat, $raw * $this->multiplier);

                     //   $this->addcell(NumberRecord($r));
                    }
                    $this->addcell($row, $column, $string, $raw);
                    //echo "Number $row $column $string\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_FORMULA:
                case self::Spreadsheet_Excel_Reader_Type_FORMULA2:
                    $row    = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $column = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
					if ((ord($this->data[$spos+6])==0) && (ord($this->data[$spos+12])==255) && (ord($this->data[$spos+13])==255)) {
						//String formula. Result follows in a STRING record
					    //echo "FORMULA $row $column Formula with a string<br>\n";
					} elseif ((ord($this->data[$spos+6])==1) && (ord($this->data[$spos+12])==255) && (ord($this->data[$spos+13])==255)) {
						//Boolean formula. Result is in +2; 0=false,1=true
					} elseif ((ord($this->data[$spos+6])==2) && (ord($this->data[$spos+12])==255) && (ord($this->data[$spos+13])==255)) {
						//Error formula. Error code is in +2;
					} elseif ((ord($this->data[$spos+6])==3) && (ord($this->data[$spos+12])==255) && (ord($this->data[$spos+13])==255)) {
						//Formula result is a null string.
					} else {
						// result is a number, so first 14 bytes are just like a _NUMBER record
	                    $tmp = unpack("ddouble", substr($this->data, $spos + 6, 8)); // It machine machine dependent
	                    if ($this->isDate($spos)) {
	                        list($string, $raw) = $this->createDate($tmp['double']);
	                     //   $this->addcell(DateRecord($r, 1));
	                    }else{
	                        //$raw = $tmp[''];
	                        if (isset($this->_columnsFormat[$column + 1])){
	                                $this->curformat = $this->_columnsFormat[$column + 1];
	                        }
	                        $raw = $this->createNumber($spos);
							$string = sprintf($this->curformat, $raw * $this->multiplier);

	                     //   $this->addcell(NumberRecord($r));
	                    }
	                    $this->addcell($row, $column, $string, $raw);
	                    //echo "Number $row $column $string\n";
					}
					break;
                case self::Spreadsheet_Excel_Reader_Type_BOOLERR:
                    $row    = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $column = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                    $string = ord($this->data[$spos+6]);
                    $this->addcell($row, $column, $string);
                    //echo 'Type_BOOLERR '."\n";
                    break;
                case self::Spreadsheet_Excel_Reader_Type_ROW:
                case self::Spreadsheet_Excel_Reader_Type_DBCELL:
                case self::Spreadsheet_Excel_Reader_Type_MULBLANK:
                    break;
                case self::Spreadsheet_Excel_Reader_Type_LABEL:
                    $row    = ord($this->data[$spos]) | ord($this->data[$spos+1])<<8;
                    $column = ord($this->data[$spos+2]) | ord($this->data[$spos+3])<<8;
                    $this->addcell($row, $column, substr($this->data, $spos + 8, ord($this->data[$spos + 6]) | ord($this->data[$spos + 7])<<8));

                   // $this->addcell(LabelRecord($r));
                    break;

                case self::Spreadsheet_Excel_Reader_Type_EOF:
                    $cont = false;
                    break;
                default:
                    //echo ' unknown :'.base_convert($r['code'],10,16)."\n";
                    break;

            }
            $spos += $length;
        }

        if (!isset($this->sheets[$this->sn]['numRows']))
        	 $this->sheets[$this->sn]['numRows'] = $this->sheets[$this->sn]['maxrow'];
        if (!isset($this->sheets[$this->sn]['numCols']))
        	 $this->sheets[$this->sn]['numCols'] = $this->sheets[$this->sn]['maxcol'];

    }

    /**
     * Check whether the current record read is a date
     *
     * @param todo
     * @return boolean True if date, false otherwise
     */
    protected function isDate($spos){
        //$xfindex = GetInt2d(, 4);
        $xfindex = ord($this->data[$spos+4]) | ord($this->data[$spos+5]) << 8;
        //echo 'check is date '.$xfindex.' '.$this->formatRecords['xfrecords'][$xfindex]['type']."\n";
        //var_dump($this->formatRecords['xfrecords'][$xfindex]);
        if ($this->formatRecords['xfrecords'][$xfindex]['type'] == 'date') {
            $this->curformat = $this->formatRecords['xfrecords'][$xfindex]['format'];
            $this->rectype = 'date';
            return true;
        } else {
            if ($this->formatRecords['xfrecords'][$xfindex]['type'] == 'number') {
                $this->curformat = $this->formatRecords['xfrecords'][$xfindex]['format'];
                $this->rectype = 'number';
                if (($xfindex == 0x9) || ($xfindex == 0xa)){
                    $this->multiplier = 100;
                }
            }else{
                $this->curformat = $this->_defaultFormat;
                $this->rectype = 'unknown';
            }
            return false;
        }
    }

    /**
     * Convert the raw Excel date into a human readable format
     *
     * Dates in Excel are stored as number of seconds from an epoch.  On
     * Windows, the epoch is 30/12/1899 and on Mac it's 01/01/1904
     *
     * @access private
     * @param integer The raw Excel value to convert
     * @return array First element is the converted date, the second element is number a unix timestamp
     */
    protected function createDate($numValue){
        if ($numValue > 1){
            $utcDays = $numValue - ($this->nineteenFour ? self::Spreadsheet_Excel_Reader_utcOffsetDays1904 : self::Spreadsheet_Excel_Reader_utcOffsetDays);
            $utcValue = round(($utcDays+1) * self::Spreadsheet_Excel_Reader_msInADay);
            $string = date ($this->curformat, $utcValue);
            $raw = $utcValue;
        }else{
            $raw = $numValue;
            $hours = floor($numValue * 24);
            $mins = floor($numValue * 24 * 60) - $hours * 60;
            $secs = floor($numValue * self::Spreadsheet_Excel_Reader_msInADay) - $hours * 60 * 60 - $mins * 60;
            $string = date ($this->curformat, mktime($hours, $mins, $secs));
        }
        return array($string, $raw);
    }

    protected function createNumber($spos){
		$rknumhigh = $this->_GetInt4d($this->data, $spos + 10);
		$rknumlow = $this->_GetInt4d($this->data, $spos + 6);
		//for ($i=0; $i<8; $i++) { echo ord($this->data[$i+$spos+6]) . " "; } echo "<br>";
		$sign = ($rknumhigh & 0x80000000) >> 31;
		$exp =  ($rknumhigh & 0x7ff00000) >> 20;
		$mantissa = (0x100000 | ($rknumhigh & 0x000fffff));
		$mantissalow1 = ($rknumlow & 0x80000000) >> 31;
		$mantissalow2 = ($rknumlow & 0x7fffffff);
		$value = $mantissa / pow( 2 , (20- ($exp - 1023)));
		if ($mantissalow1 != 0) $value += 1 / pow (2 , (21 - ($exp - 1023)));
		$value += $mantissalow2 / pow (2 , (52 - ($exp - 1023)));
		//echo "Sign = $sign, Exp = $exp, mantissahighx = $mantissa, mantissalow1 = $mantissalow1, mantissalow2 = $mantissalow2<br>\n";
		if ($sign) {$value = -1 * $value;}
		return  $value;
    }

    protected function addcell($row, $col, &$string, &$raw = ''){
        //echo "ADD cel $row-$col $string\n";
        $this->sheets[$this->sn]['maxrow'] = max($this->sheets[$this->sn]['maxrow'], $row + $this->_rowoffset);
        $this->sheets[$this->sn]['maxcol'] = max($this->sheets[$this->sn]['maxcol'], $col + $this->_coloffset);
        $this->sheets[$this->sn]['cells'][$row + $this->_rowoffset][$col + $this->_coloffset] = $string;
        if ($raw)
            $this->sheets[$this->sn]['cellsInfo'][$row + $this->_rowoffset][$col + $this->_coloffset]['raw'] = $raw;
        if (isset($this->rectype))
            $this->sheets[$this->sn]['cellsInfo'][$row + $this->_rowoffset][$col + $this->_coloffset]['type'] = $this->rectype;

    }

    protected function &_encodeUTF16(&$string){
        if ($this->_defaultEncoding){
        	switch ($this->_encoderFunction){
        		case 'iconv' :
                    $result = iconv('UTF-16LE', $this->_defaultEncoding, $string);
        			break;
        		case 'mb_convert_encoding' :
                    $result = mb_convert_encoding($string, $this->_defaultEncoding, 'UTF-16LE' );
        			break;
                default:
                    $result = $string;
                    break;
        	}
        }
        return $result;
    }

    protected function _GetIEEE754($rknum){
        if (($rknum & 0x02) != 0) {
                $value = $rknum >> 2;
        } else {
//mmp
// first comment out the previously existing 7 lines of code here
//                $tmp = unpack("d", pack("VV", 0, ($rknum & 0xfffffffc)));
//                //$value = $tmp[''];
//                if (array_key_exists(1, $tmp)) {
//                    $value = $tmp[1];
//                } else {
//                    $value = $tmp[''];
//                }
// I got my info on IEEE754 encoding from
// http://research.microsoft.com/~hollasch/cgindex/coding/ieeefloat.html
// The RK format calls for using only the most significant 30 bits of the
// 64 bit floating point value. The other 34 bits are assumed to be 0
// So, we use the upper 30 bits of $rknum as follows...
 		$sign = ($rknum & 0x80000000) >> 31;
		$exp = ($rknum & 0x7ff00000) >> 20;
		$mantissa = (0x100000 | ($rknum & 0x000ffffc));
		$value = $mantissa / pow( 2 , (20- ($exp - 1023)));
		if ($sign) {$value = -1 * $value;}
//end of changes by mmp

        }

        if (($rknum & 0x01) != 0) {
            $value /= 100;
        }
        return $value;
    }

}
?>
