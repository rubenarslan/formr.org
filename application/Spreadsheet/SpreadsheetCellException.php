<?php

/**
 * Thrown by SpreadsheetReader when it has located a mistake in a specific
 * cell of the survey or choices sheet. Its message already names the cell
 * and carries every row-level error collected up to that point, so the
 * reader's catch block reports it verbatim instead of wrapping it in the
 * generic "error reading your spreadsheet" text reserved for exceptions
 * that originate inside PhpSpreadsheet itself (#385).
 */
class SpreadsheetCellException extends \PhpOffice\PhpSpreadsheet\Exception
{
}
