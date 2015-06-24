<?php
/*******************************************************************************
*  Title: Help Desk Software HESK
*  Version: 2.6.4 from 22nd June 2015
*  Author: Klemen Stirn
*  Website: http://www.hesk.com
********************************************************************************
*  COPYRIGHT AND TRADEMARK NOTICE
*  Copyright 2005-2015 Klemen Stirn. All Rights Reserved.
*  HESK is a registered trademark of Klemen Stirn.

*  The HESK may be used and modified free of charge by anyone
*  AS LONG AS COPYRIGHT NOTICES AND ALL THE COMMENTS REMAIN INTACT.
*  By using this code you agree to indemnify Klemen Stirn from any
*  liability that might arise from it's use.

*  Selling the code for this program, in part or full, without prior
*  written consent is expressly forbidden.

*  Using this code, in part or full, to create derivate work,
*  new scripts or products is expressly forbidden. Obtain permission
*  before redistributing this software over the Internet or in
*  any other medium. In all cases copyright and header must remain intact.
*  This Copyright is in full effect in any country that has International
*  Trade Agreements with the United States of America or
*  with the European Union.

*  Removing any of the copyright notices without purchasing a license
*  is expressly forbidden. To remove HESK copyright notice you must purchase
*  a license for this script. For more information on how to obtain
*  a license please visit the page below:
*  https://www.hesk.com/buy.php
*******************************************************************************/

define('IN_SCRIPT',1);
define('HESK_PATH','./');

// Get all the required files and functions
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');

// Are we in maintenance mode?
hesk_check_maintenance();

hesk_load_database_functions();
hesk_session_start();

// A security check
hesk_token_check();

// Get the tracking ID
$trackingID = hesk_cleanID() or die("$hesklang[int_error]: $hesklang[no_trackID]");

// Get new status
$status = intval( hesk_GET('s', 0) );
$oldStatus = $status;

$locked = 0;

// Connect to database
hesk_dbConnect();

// Get the close status. It'll be used later on
$statusRes = hesk_dbQuery('SELECT `ID` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'statuses` WHERE `IsClosedByClient` = 1');
$statusRow = hesk_dbFetchAssoc($statusRes);
$closedStatus = $statusRow['ID'];

if ($status == 3) // Closed
{
    // Is customer closing tickets enabled?
    if ( ! $hesk_settings['custclose'])
    {
        hesk_error($hesklang['attempt']);
    }
    $status = $closedStatus;
	$action = $hesklang['closed'];
    $revision = sprintf($hesklang['thist3'],hesk_date(),$hesklang['customer']);

    if ($hesk_settings['custopen'] != 1)
    {
    	$locked = 1;
    }

    // Mark that customer resolved the ticket
    $closedby_sql = ' , `closedat`=NOW(), `closedby`=0 ';
}
elseif ($status == 2) // Opened
{
	// Is customer reopening tickets enabled?
	if ( ! $hesk_settings['custopen'])
	{
		hesk_error($hesklang['attempt']);
	}

    //-- They want to close the ticket, so get the status that is the default for client-side closes
    $statusRes = hesk_dbQuery('SELECT `ID` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'statuses` WHERE `IsDefaultStaffReplyStatus` = 1');
    $statusRow = hesk_dbFetchAssoc($statusRes);
    $status = $statusRow['ID'];

	$action = $hesklang['opened'];
    $revision = sprintf($hesklang['thist4'],hesk_date(),$hesklang['customer']);

	// We will ask the customer why is the ticket being reopened
	$_SESSION['force_form_top'] = true;

    // Ticket is not resolved
    $closedby_sql = ' , `closedat`=NULL, `closedby`=NULL ';
}
else
{
	die("$hesklang[int_error]: $hesklang[status_not_valid].");
}

// Connect to database
hesk_dbConnect();

// Verify email address match if needed
hesk_verifyEmailMatch($trackingID);

// Lets make status assignment a bit smarter when reopening tickets
if ($oldStatus == 2)
{
	// Get number of replies and last replier (customer or staff)
	$ticket = hesk_dbFetchAssoc( hesk_dbQuery( "SELECT `staffreplies`, `lastreplier` FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `trackid`='".hesk_dbEscape($trackingID)."' LIMIT 1") );

	// If ticket has no staff replies set the status to "New"
	if ($ticket['staffreplies'] < 1)
	{
        $statusRes = hesk_dbQuery('SELECT `ID` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'statuses` WHERE `IsNewTicketStatus` = 1');
        $statusRow = hesk_dbFetchAssoc($statusRes);
        $status = $statusRow['ID'];
	}
    // If last reply was by customer set status to "Waiting reply from staff"
    elseif ($ticket['lastreplier'] == 0)
    {
        $statusRes = hesk_dbQuery('SELECT `ID` FROM `'.hesk_dbEscape($hesk_settings['db_pfix']).'statuses` WHERE `IsCustomerReplyStatus` = 1');
        $statusRow = hesk_dbFetchAssoc($statusRes);
        $status = $statusRow['ID'];
	}
	// If nothing matches: last reply was from staff, keep status "Waiting reply from customer"
}


// Modify values in the database
hesk_dbQuery("UPDATE `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` SET `status`='{$status}', `locked`='{$locked}' $closedby_sql , `history`=CONCAT(`history`,'".hesk_dbEscape($revision)."') WHERE `trackid`='".hesk_dbEscape($trackingID)."' AND `locked` != '1' LIMIT 1");

// Did we modify anything*
if (hesk_dbAffectedRows() != 1)
{
	hesk_error($hesklang['elocked']);
}

// Show success message
if ($status != $closedStatus)
{
	hesk_process_messages($hesklang['wrepo'],'ticket.php?track='.$trackingID.$hesk_settings['e_param'].'&Refresh='.rand(10000,99999),'NOTICE');
}
else
{
	hesk_process_messages($hesklang['your_ticket_been'].' '.$action,'ticket.php?track='.$trackingID.$hesk_settings['e_param'].'&Refresh='.rand(10000,99999),'SUCCESS');
}
