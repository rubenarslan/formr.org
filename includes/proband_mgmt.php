<?php



function getvpncode() {
  global $currentUser;
  if(isset($currentUser) and isset($currentUser->vpncode)) {
    $vpncode = mysql_real_escape_string ($currentUser->vpncode);
    post_debug("<strong>getvpncode:</strong> got ".$vpncode." through currentUser");
  } else if (isset($_POST['vpncode']) and $_POST['vpncode']!="") {
    // vpncode was handed over through post
    $vpncode = mysql_real_escape_string ($_REQUEST['vpncode']);
    post_debug("<strong>getvpncode:</strong> got ".$vpncode." through post data");

  } elseif(isset($_REQUEST['vpncode']) and $_REQUEST['vpncode']!="") {
    // in session
    $vpncode = mysql_real_escape_string($_REQUEST['vpncode']);
    post_debug("<strong>getvpncode:</strong> got ".$vpncode." through request data");

    if( !vpn_exists($vpncode) ) {
      $goto=basename($_SERVER["SCRIPT_NAME"]);
      redirect_to("login.php?goto=$goto");
      // header("Location: login.php?goto=$goto");
      // exit();
    }
  } elseif (USERPOOL == "open") {
    // Prüfe, ob wir ihn in Stücken bekommen haben.
    if( isset($_POST['idbox1']) AND isset($_POST['idbox2']) AND isset($_POST['idbox3']) AND isset($_POST['idbox4']) ) {
      // Prüfe bitte mal, ob nicht einer leer geblieben ist...
      if(($_POST['idbox1']=="") OR ($_POST['idbox2']=="") OR ($_POST['idbox3']=="") OR ($_POST['idbox4']=="")) {
        // echo "Bitte alle Felder ausfüllen";
        $goto=basename($_SERVER["SCRIPT_NAME"]);
        redirect_to("login.php?goto=$goto");
        // header("Location: login.php?goto=$goto");
        // exit();
      }
      // (korrigiere und) übernimm ihn
      if (strlen(trim($_POST['idbox3'])) == 1) {
        $vp_idbox3 = "0" . $_POST['idbox3'];
      } else {
        $vp_idbox3 = $_POST['idbox3'];
      }
      $vpncode = mysql_real_escape_string(strtolower($_POST['idbox1'] . $_POST['idbox2'] . $vp_idbox3 . $_POST['idbox4']));
      post_debug("<strong>getvpncode:</strong> constructed ".$vpncode." for open pool");

    } elseif (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
      // Wenn er auch nicht gestückelt übergeben wurde, dann ab zum Login
      // Wenn du nicht schon dort bist
      $goto=basename($_SERVER["SCRIPT_NAME"]);
      redirect_to("login.php?goto=$goto");
      // header("Location: login.php?goto=$goto");
      // exit();
    }
  } elseif (USERPOOL == "limited" AND basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
    // Zum Login
    $goto=basename($_SERVER["SCRIPT_NAME"]);
    redirect_to("login.php?goto=$goto");
    // header("Location: login.php?goto=$goto");
    // exit();
  } elseif(isset($_SESSION['vpncode']) and $_SESSION['vpncode']!="") {
    // Am ehesten kommt er über SESSION, und dann können wir ihn einfach nehmen
    $vpncode=mysql_real_escape_string($_SESSION['vpncode']);
    post_debug("<strong>getvpncode:</strong> got ".$vpncode." through session data");
  } elseif (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
    // keinen Code über post, get oder Session bekommen
    // keinen gestückelten bekommen (wird nur geprüft, wenn OPEN)
    // Nicht auf Loginpage
    // Dann müssen wir da jetzt hin!
    $goto=basename($_SERVER["SCRIPT_NAME"]);
    redirect_to("login.php?goto=$goto");
    // header("Location: login.php?goto=$goto");
    // exit();
  }

  // FALLS Userpool limited ist, prüfe, ob der vpncode auch gültig ist
  if (USERPOOL == "limited") {
    if  (!table_exists(VPNDATATABLE)) {
      die("VPNDATATABLE does not exist: please check your setup");
    }
    if (!vpn_exists($vpncode)) {
      // Wenn der vpncode nicht gültig ist
      if (basename($_SERVER["SCRIPT_NAME"])!=LOGINPAGE) {
        // Wenn du nicht schon dort bist
        $goto=basename($_SERVER["SCRIPT_NAME"]);
        redirect_to("login.php?goto=$goto");
        // header("Location: login.php?goto=$goto");
        // exit();
      }
    }
  }

  // Du hast jetzt einen gültigen vpncode. Lege mir noch kurz einen Eintrag in results an, wenn es noch keinen gibt
  if  (!table_exists(RESULTSTABLE) OR !table_exists(VPNDATATABLE)) {
    die("RESULTSTABLE does not exist: please check your setup");
  }

  // create entry for vpn in VPNDATATABLE
  if ( !vpn_exists($vpncode) ) {
    // doesn't yet exist, so create it
    /* $study = get_study_by_id(1); //todo: there should only be one study anyway */
    /* add_vpn($vpncode,NULL,$study->name,1); */
    add_vpn($vpncode,NULL,NULL,1);
  }

  // put the code back into the session
  $_SESSION["vpncode"]=$vpncode;
  // and return for functions waiting for it
  post_debug("<strong>getvpncode:</strong> " . $vpncode );
  return $vpncode;
}

function vpn_exists($vpncode) {
    $query="SELECT * FROM ".VPNDATATABLE." WHERE (vpncode ='$vpncode')";
    $res = mysql_query($query) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in vpn_exists" ));
    $exists=mysql_numrows($res);
    if( $exists != 0 ) {
      post_debug("<strong>vpn_exists:</strong> TRUE");
      return true;
    } else {
      post_debug("<strong>vpn_exists:</strong> FALSE");
      return false;
    }
}

/* only tell me what I need to know;
 * function to check, wether endtries exist for the given study and vpncode
 * only select id for perfomances' sake */
function has_entries_for_study($vpncode) {
    $query_string = "SELECT id FROM ".RESULTSTABLE." WHERE vpncode='".$vpncode."' ;";
    $results = mysql_query( $query_string) or die(exception_handler(mysql_error() . "<br/>" . $query_string . "<br/> in has_entries_for_study" ));
    if( mysql_num_rows($results) > 0) {
		post_debug("<strong>has_entries_for_study:</strong> TRUE");
        return true;
    } else {
		post_debug("<strong>has_entries_for_study:</strong> FALSE");
        return false;
    }
}