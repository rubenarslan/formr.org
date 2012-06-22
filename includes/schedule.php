<?php
/* scheduler for instant message dispatch: replaced by web-cron based script
 */

// $curtime = time();

// array for completed IDs to be deleted later on
// $message_id = array();

// select everything that may be sent right now
// $query = "SELECT ".MESSAGEQUEUE.".id,".EMAILSTABLE.".subject,".EMAILSTABLE.".body,".MESSAGEQUEUE.".email 
//     FROM ".EMAILSTABLE." 
//     JOIN ".MESSAGEQUEUE." ON ".MESSAGEQUEUE.".email_id = ".EMAILSTABLE.".id 
//     WHERE ".MESSAGEQUEUE.".send_time < $curtime LIMIT ".MAXSENDEMAILS.";";

// $query_resc = mysql_query($query) or die( mysql_error() );

// do the mailing
// while( $email = mysql_fetch_assoc( $query_resc ) ) {
//     $mail = mail( $email['email'], $email['subject'], $email['body'], EMAILHEADER );
//     if( $mail ) {
//         array_push($message_id,$email['id']);
//     }
// }

// collect our garbage
// if( !empty($message_id) ) {
//     $query = "DELETE FROM ".MESSAGEQUEUE." WHERE ".MESSAGEQUEUE.".id IN (" . implode(",",$message_id) . ")";
//     mysql_query( $query ) or die( mysql_error() );
// }

?>
