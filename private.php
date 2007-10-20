<?php
/**
 * MyBB 1.2
 * Copyright � 2006 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.net
 * License: http://www.mybboard.net/eula.html
 *
 * $Id$
 */

define("IN_MYBB", 1);

$templatelist = "private_send,private_send_buddyselect,private_read,private_tracking,private_tracking_readmessage,private_tracking_unreadmessage";
$templatelist .= ",private_folders,private_folders_folder,private_folders_folder_unremovable,private,usercp_nav_changename,usercp_nav,private_empty_folder,private_empty,posticons";
$templatelist .= "usercp_nav_messenger,usercp_nav_changename,usercp_nav_profile,usercp_nav_misc,usercp_nav_messenger,multipage_nextpage,multipage_page_current,multipage_page,multipage_start,multipage_end,multipage,private_messagebit";

require_once "./global.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;

// Load global language phrases
$lang->load("private");

if($mybb->settings['enablepms'] == "no")
{
	error($lang->pms_disabled);
}

if($mybb->user['uid'] == "/" || $mybb->user['uid'] == "0" || $mybb->usergroup['canusepms'] == "no")
{
	error_no_permission();
}
if($mybb->user['receivepms'] == "no")
{
	error($lang->error_pmsturnedoff);
}

if(!$mybb->user['pmfolders'])
{
	$mybb->user['pmfolders'] = "1**$%%$2**$%%$3**$%%$4**";

	$sql_array = array(
		 "pmfolders" => $mybb->user['pmfolders']
	);
	$db->update_query(TABLE_PREFIX."users", $sql_array, "uid = ".$mybb->user['uid']);
}

$timecut = time()-(60*60*24*7); 
$db->delete_query(TABLE_PREFIX."privatemessages", "dateline <= $timecut AND folder='4' AND uid='".$mybb->user['uid']."'"); 

// On a random occassion, recount the users pm's just to make sure everything is in sync. 
if($rand == 5 || $db->affected_rows() > 0) 
{ 
	update_pm_count(); 
} 

$folderjump = "<select name=\"jumpto\">\n";
$folderoplist = "<select name=\"fid\">\n";
$folderjump2 = "<select name=\"jumpto2\">\n";

$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
foreach($foldersexploded as $key => $folders)
{
	$folderinfo = explode("**", $folders, 2);
	if($fid == $folderinfo[0])
	{
		$sel = "selected";
	}
	else
	{
		$sel = "";
	}
	$folderinfo[1] = get_pm_folder_name($folderinfo[0], $folderinfo[1]);
	$folderjump .= "<option value=\"$folderinfo[0]\" $sel>$folderinfo[1]</option>\n";
	$folderjump2 .= "<option value=\"$folderinfo[0]\" $sel>$folderinfo[1]</option>\n";
	$folderoplist .= "<option value=\"$folderinfo[0]\" $sel>$folderinfo[1]</option>\n";
	$folderlinks .= "&#149;&nbsp;<a href=\"private.php?fid=$folderinfo[0]\">$folderinfo[1]</a><br />\n";
}
$folderjump .= "</select>\n";
$folderjump2 .= "</select>\n";
$folderoplist .= "</select>\n";

usercp_menu();


// Make navigation
add_breadcrumb($lang->nav_pms, "private.php");

switch($mybb->input['action'])
{
	case "send":
		add_breadcrumb($lang->nav_send);
		break;
	case "tracking":
		add_breadcrumb($lang->nav_tracking);
		break;
	case "folders":
		add_breadcrumb($lang->nav_folders);
		break;
	case "empty":
		add_breadcrumb($lang->nav_empty);
		break;
	case "export":
		add_breadcrumb($lang->nav_export);
		break;
}
if($mybb->input['preview'])
{
	$mybb->input['action'] = "send";
}

$send_errors = '';

if($mybb->input['action'] == "do_send" && $mybb->request_method == "post")
{
	if($mybb->usergroup['cansendpms'] == "no")
	{
		error_no_permission();
	}

	$plugins->run_hooks("private_send_do_send");

	// Attempt to see if this PM is a duplicate or not
	$time_cutoff = time() - (5 * 60 * 60);
	$query = $db->query("
		SELECT pm.pmid
		FROM (".TABLE_PREFIX."privatemessages pm, ".TABLE_PREFIX."users u)
		WHERE pm.toid=u.uid AND u.username='".$db->escape_string($mybb->input['to'])."' AND pm.dateline > {$time_cutoff} AND pm.fromid='{$mybb->user['uid']}' AND pm.subject='".$db->escape_string($mybb->input['subject'])."' AND pm.message='".$db->escape_string($mybb->input['message'])."' AND pm.folder!='3'
	");
	$duplicate_check = $db->fetch_field($query, "pmid");
	if($duplicate_check)
	{
		error($lang->error_pm_already_submitted);
	}

	require_once MYBB_ROOT."inc/datahandlers/pm.php";
	$pmhandler = new PMDataHandler();

	$pm = array(
		"subject" => $mybb->input['subject'],
		"message" => $mybb->input['message'],
		"icon" => $mybb->input['icon'],
		"fromid" => $mybb->user['uid'],
		"username" => $mybb->input['to'],
		"do" => $mybb->input['do'],
		"pmid" => $mybb->input['pmid']
	);

	$pm['options'] = array(
		"signature" => $mybb->input['options']['signature'],
		"disablesmilies" => $mybb->input['options']['disablesmilies'],
		"savecopy" => $mybb->input['options']['savecopy'],
		"readreceipt" => $mybb->input['options']['readreceipt']
	);

	if($mybb->input['saveasdraft'])
	{
		$pm['saveasdraft'] = 1;
	}
	$pmhandler->set_data($pm);

	// Now let the pm handler do all the hard work.
	if(!$pmhandler->validate_pm())
	{
		$pm_errors = $pmhandler->get_friendly_errors();
		$send_errors = inline_error($pm_errors);
		$mybb->input['action'] = "send";
	}
	else
	{
		$pminfo = $pmhandler->insert_pm();
		$plugins->run_hooks("private_do_send_end");

		if(isset($pminfo['draftsaved']))
		{
			redirect("private.php", $lang->redirect_pmsaved);
		}
		else
		{
			redirect("private.php", $lang->redirect_pmsent);
		}

	}
}

if($mybb->input['action'] == "send")
{
	if($mybb->usergroup['cansendpms'] == "no")
	{
		error_no_permission();
	}

	$plugins->run_hooks("private_send_start");

	if($mybb->settings['bbcodeinserter'] != "off" && $mybb->settings['pmsallowmycode'] != "no" && $mybb->user['showcodebuttons'] != 0)
	{
		$codebuttons = build_mycode_inserter();
		if($mybb->settings['pmsallowsmilies'] != "no")
		{
			$smilieinserter = build_clickable_smilies();
		}
	}

	$posticons = get_post_icons();
	$previewmessage = $mybb->input['message'];
	$message = htmlspecialchars_uni($mybb->input['message']);
	$subject = $previewsubject = htmlspecialchars_uni($mybb->input['subject']);

	if($mybb->input['preview'] || $send_errors)
	{
		if($options['signature'] == "yes")
		{
			$optionschecked['signature'] = "checked";
		}
		if($options['disablesmilies'] == "yes")
		{
			$optionschecked['disablesmilies'] = "checked";
		}
		if($options['savecopy'] != "no")
		{
			$optionschecked['savecopy'] = "checked";
		}
		if($options['readreceipt'] != "no")
		{
			$optionschecked['readreceipt'] = "checked";
		}
		$to = htmlspecialchars_uni($mybb->input['to']);
	}
	
	// Preview
	if($mybb->input['preview'])
	{
		$options = $mybb->input['options'];
		$query = $db->query("
			SELECT u.username AS userusername, u.*, f.*, g.title AS grouptitle, g.usertitle AS groupusertitle, g.namestyle, g.stars AS groupstars, g.starimage AS groupstarimage, g.image AS groupimage, g.usereputationsystem
			FROM ".TABLE_PREFIX."users u
			LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
			LEFT JOIN ".TABLE_PREFIX."usergroups g ON (g.gid=u.usergroup)
			WHERE u.uid='".$mybb->user['uid']."'
		");
		$post = $db->fetch_array($query);
		$post['userusername'] = $mybb->user['username'];
		$post['postusername'] = $mybb->user['username'];
		$post['message'] = $previewmessage;
		$post['subject'] = $previewsubject;
		$post['icon'] = $mybb->input['icon'];
		$post['smilieoff'] = $options['disablesmilies'];
		$post['dateline'] = time();
		if(!$options['signature']) 
 	    { 
 	    	$post['includesig'] = 'no'; 
 		} 
 		else 
 		{ 
 			$post['includesig'] = 'yes'; 
 		} 
		if(!$options['signature'])
		{
			$post['includesig'] = 'no';
		}
		else
		{
			$post['includesig'] = 'yes';
		}
		$postbit = build_postbit($post, 2);
		eval("\$preview = \"".$templates->get("previewpost")."\";");
	}
	else if(!$send_errors)
	{
		// New PM, so load default settings
		if($mybb->user['signature'] != "")
		{
			$optionschecked['signature'] = "checked";
		}
		if($mybb->usergroup['cantrackpms'] == "yes")
		{
			$optionschecked['readreceipt'] = "checked";
		}
		$optionschecked['savecopy'] = "checked";
	}
	// Draft, reply, forward
	if($mybb->input['pmid'] > 0 && !$mybb->input['preview'])
	{
		$query = $db->query("
			SELECT pm.*, u.username AS quotename
			FROM ".TABLE_PREFIX."privatemessages pm
			LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=pm.fromid)
			WHERE pm.pmid='".intval($mybb->input['pmid'])."' AND pm.uid='".$mybb->user['uid']."'
		");
		$pm = $db->fetch_array($query);
		$message = $pm['message'];
		$message = htmlspecialchars_uni($message);
		$subject = $pm['subject'];
		$subject = htmlspecialchars_uni($subject);
		if($pm['folder'] == "3")
		{ // message saved in drafts
			$mybb->input['uid'] = $pm['toid'];
			if($pm['includesig'] == "yes")
			{
				$optionschecked['signature'] = "checked";
			}
			if($pm['smilieoff'] == "yes")
			{
				$optionschecked['disablesmilies'] = "checked";
			}
			if($pm['receipt'])
			{
				$optionschecked['readreceipt'] = "checked";
			}
		}
		else
		{ // forward/reply
			$subject = preg_replace("#(FW|RE):( *)#is", "", $subject);
			$postdate = my_date($mybb->settings['dateformat'], $pm['dateline']);
			$posttime = my_date($mybb->settings['timeformat'], $pm['dateline']);
			$message = "[quote=$pm[quotename]]\n$message\n[/quote]";
			$quoted['message'] = preg_replace('#^/me (.*)$#im', "* ".$pm['quotename']." \\1", $quoted['message']);

			if($mybb->input['do'] == "forward")
			{
				$subject = "Fw: $subject";
			}
			elseif($mybb->input['do'] == "reply")
			{
				$subject = "Re: $subject";
				$uid = $pm['fromid'];
				$query = $db->simple_select(TABLE_PREFIX."users", "username", "uid='".$uid."'");
				$user = $db->fetch_array($query);
				$to = htmlspecialchars_uni($user['username']);
			}
		}
	}
	// New PM with recipient preset
	if($mybb->input['uid'] && !$mybb->input['preview'])
	{
		$query = $db->simple_select(TABLE_PREFIX."users", "username", "uid='".intval($mybb->input['uid'])."'");
		$user = $db->fetch_array($query);
		$to = htmlspecialchars_uni($user['username']);
	}

	// Load the auto complete javascript if it is enabled.
	eval("\$autocompletejs = \"".$templates->get("private_send_autocomplete")."\";");

	$pmid = $mybb->input['pmid'];
	$do = $mybb->input['do'];
	if($do != "forward" && $do != "reply")
	{
		$do = "";
	}
	eval("\$send = \"".$templates->get("private_send")."\";");
	$plugins->run_hooks("private_send_end");
	output_page($send);
}


if($mybb->input['action'] == "read")
{
	$plugins->run_hooks("private_read");

	$pmid = intval($mybb->input['pmid']);

	$query = $db->query("
		SELECT pm.*, u.*, f.*, g.title AS grouptitle, g.usertitle AS groupusertitle, g.stars AS groupstars, g.starimage AS groupstarimage, g.image AS groupimage, g.namestyle
		FROM ".TABLE_PREFIX."privatemessages pm
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=pm.fromid)
		LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
		LEFT JOIN ".TABLE_PREFIX."usergroups g ON (g.gid=u.usergroup)
		WHERE pm.pmid='".intval($mybb->input['pmid'])."' AND pm.uid='".$mybb->user['uid']."'
	");
	$pm = $db->fetch_array($query);
	if($pm['folder'] == 3)
	{
		header("Location: private.php?action=send&pmid={$pm['pmid']}");
		exit;
	}
	if(!$pm['pmid'])
	{
		error($lang->error_invalidpm);
	}
	if($pm['receipt'] == "1")
	{
		if($mybb->usergroup['cantrackpms'] == 'yes' && $mybb->usergroup['candenypmreceipts'] == 'yes' && $mybb->input['denyreceipt'] == "yes")
		{
			$receiptadd = "0";
		}
		else
		{
			$receiptadd = "2";
		}
	}
	if($pm['status'] == "0")
	{
		$time = time();
		$updatearray = array(
			'status' => 1,
			'readtime' => $time

		);
		if($receiptadd != "")
		{
			$updatearray['receipt'] = $receiptadd;
		}

		$db->update_query(TABLE_PREFIX."privatemessages", $updatearray, "pmid='{$pmid}'");

		// Update the unread count - it has now changed.
		update_pm_count($mybb->user['uid'], 6);
	}
	$pm['userusername'] = $pm['username'];
	$pm['subject'] = htmlspecialchars_uni($parser->parse_badwords($pm['subject']));
	if($pm['fromid'] == 0)
	{
		$pm['username'] = "MyBB Engine";
	}

	add_breadcrumb($pm['subject']);
	$message = build_postbit($pm, "2");
	eval("\$read = \"".$templates->get("private_read")."\";");
	$plugins->run_hooks("private_read_end");
	output_page($read);
}

if($mybb->input['action'] == "tracking")
{
	$plugins->run_hooks("private_tracking_start");
	$readmessages = '';
	$unreadmessages = '';
	$query = $db->query("
		SELECT pm.*, u.username as tousername
		FROM ".TABLE_PREFIX."privatemessages pm
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=pm.toid)
		WHERE receipt='2' AND status!='0' AND fromid='".$mybb->user['uid']."'
		ORDER BY pm.readtime DESC
	");
	while($readmessage = $db->fetch_array($query))
	{
		$readmessage['subject'] = htmlspecialchars_uni($parser->parse_badwords($readmessage['subject']));
		$readdate = my_date($mybb->settings['dateformat'], $readmessage['readtime']);
		$readtime = my_date($mybb->settings['timeformat'], $readmessage['readtime']);
		eval("\$readmessages .= \"".$templates->get("private_tracking_readmessage")."\";");
	}
	$query = $db->query("
		SELECT pm.*, u.username AS tousername
		FROM ".TABLE_PREFIX."privatemessages pm
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=pm.toid)
		WHERE receipt='1' AND status='0' AND fromid='".$mybb->user['uid']."'
		ORDER BY pm.dateline DESC
	");
	while($unreadmessage = $db->fetch_array($query))
	{
		$unreadmessage['subject'] = htmlspecialchars_uni($parser->parse_badwords($unreadmessage['subject']));
		$senddate = my_date($mybb->settings['dateformat'], $unreadmessage['dateline']);
		$sendtime = my_date($mybb->settings['timeformat'], $unreadmessage['dateline']);
		eval("\$unreadmessages .= \"".$templates->get("private_tracking_unreadmessage")."\";");
	}
	eval("\$tracking = \"".$templates->get("private_tracking")."\";");
	$plugins->run_hooks("private_tracking_end");
	output_page($tracking);
}
if($mybb->input['action'] == "do_tracking" && $mybb->request_method == "post")
{
	$plugins->run_hooks("private_do_tracking_start");
	if($mybb->input['stoptracking'])
	{
		if(is_array($mybb->input['readcheck']))
		{
			foreach($mybb->input['readcheck'] as $key => $val)
			{
				$sql_array = array(
					"receipt" => 0
				);
				$db->update_query(TABLE_PREFIX."privatemessages", $sql_array, "pmid=".intval($key)." AND fromid=".$mybb->user['uid']);
			}
		}
		$plugins->run_hooks("private_do_tracking_end");
		redirect("private.php", $lang->redirect_pmstrackingstopped);
	}
	elseif($mybb->input['stoptrackingunread'])
	{
		if(is_array($mybb->input['unreadcheck']))
		{
			foreach($mybb->input['unreadcheck'] as $key => $val)
			{
				$sql_array = array(
					"receipt" => 0
				);
				$db->update_query(TABLE_PREFIX."privatemessages", $sql_array, "pmid=".intval($key)." AND fromid=".$mybb->user['uid']);
			}
		}
		$plugins->run_hooks("private_do_tracking_end");
		redirect("private.php", $lang->redirect_pmstrackingstopped);
	}
	elseif($mybb->input['cancel'])
	{
		if(is_array($mybb->input['unreadcheck']))
		{
			foreach($mybb->input['unreadcheck'] as $pmid => $val)
			{
				$pmids[$pmid] = intval($pmid);
			}
			$pmids = implode(",", $pmids);
			$query = $db->simple_select(TABLE_PREFIX."privatemessages", "uid", "pmid IN ($pmids) AND fromid='".$mybb->user['uid']."'");
			while($pm = $db->fetch_array($query))
			{
				$pmuids[$pm['uid']] = $pm['uid'];
			}
			$db->delete_query(TABLE_PREFIX."privatemessages", "pmid IN ($pmids) AND fromid='".$mybb->user['uid']."'");
			foreach($pmuids as $uid)
			{
				// Message is cancelled, update PM count for this user
				update_pm_count($pm['uid']);
			}
		}
		$plugins->run_hooks("private_do_tracking_end");
		redirect("private.php", $lang->redirect_pmstrackingcancelled);
	}
}

if($mybb->input['action'] == "folders")
{
	$plugins->run_hooks("private_folders_start");
	$folderlist = '';
	$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
	foreach($foldersexploded as $key => $folders)
	{
		$folderinfo = explode("**", $folders, 2);
		$foldername = $folderinfo[1];
		$fid = $folderinfo[0];
		$foldername = get_pm_folder_name($fid, $foldername);
		if($folderinfo[0] == "1" || $folderinfo[0] == "2" || $folderinfo[0] == "3" || $folderinfo[0] == "4")
		{
			$foldername2 = get_pm_folder_name($fid);
			eval("\$folderlist .= \"".$templates->get("private_folders_folder_unremovable")."\";");
			unset($name);
		}
		else
		{
			eval("\$folderlist .= \"".$templates->get("private_folders_folder")."\";");
		}
	}
	$newfolders = '';
	for($i = 1; $i <= 5; $i++)
	{
		$fid = "new$i";
		$foldername = '';
		eval("\$newfolders .= \"".$templates->get("private_folders_folder")."\";");
	}
	eval("\$folders = \"".$templates->get("private_folders")."\";");
	$plugins->run_hooks("private_folders_end");
	output_page($folders);
}

if($mybb->input['action'] == "do_folders" && $mybb->request_method == "post")
{
	$plugins->run_hooks("private_do_folders_start");
	$highestid = 2;
	$folders = '';
	@reset($mybb->input['folder']);
	foreach($mybb->input['folder'] as $key => $val)
	{
		if(!$donefolders[$val])
		{
			if(my_substr($key, 0, 3) == "new")
			{
				$highestid++;
				$fid = intval($highestid);
			}
			else
			{
				if($key > $highestid)
				{
					$highestid = $key;
				}
				$fid = intval($key);
				switch($fid)
				{
					case 1:
						if($val == $lang->folder_inbox)
						{
							$val = '';
						}
						break;
					case 2:
						if($val == $lang->folder_sent_items)
						{
							$val = '';
						}
						break;
					case 3:
						if($val == $lang->folder_drafts)
						{
							$val = '';
						}
						break;
					case 4:
						if($val == $lang->folder_trash)
						{
							$val = '';
						}
						break;
				}
			}
			if($val != '' || ($key >= 1 && $key <= 4))
			{
				$foldername = $val;
				$foldername = $db->escape_string(htmlspecialchars_uni($foldername));
				if(strpos($foldername, "$%%$") === false)
				{
					if($folders != '')
					{
						$folders .= "$%%$";
					}
					$folders .= "$fid**$foldername";
				}
				else
				{
					error($lang->error_invalidpmfoldername);
				}
			}
			else
			{
				$db->delete_query(TABLE_PREFIX."privatemessages", "folder='$fid' AND uid='".$mybb->user['uid']."'");
			}
		}
	}

	$sql_array = array(
		"pmfolders" => $folders
	);
	$db->update_query(TABLE_PREFIX."users", $sql_array, "uid='".$mybb->user['uid']."'");
	// Update PM count
	update_pm_count();
	$plugins->run_hooks("private_do_folders_end");
	redirect("private.php", $lang->redirect_pmfoldersupdated);
}

if($mybb->input['action'] == "empty")
{
	$plugins->run_hooks("private_empty_start");
	$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
	$folderlist = '';
	foreach($foldersexploded as $key => $folders)
	{
		$folderinfo = explode("**", $folders, 2);
		$fid = $folderinfo[0];
		$foldername = get_pm_folder_name($fid, $folderinfo[1]);
		$query = $db->simple_select(TABLE_PREFIX."privatemessages", "COUNT(*) AS pmsinfolder", " folder='$fid' AND uid='".$mybb->user['uid']."'");
		$thing = $db->fetch_array($query);
		$foldercount = my_number_format($thing['pmsinfolder']);
		eval("\$folderlist .= \"".$templates->get("private_empty_folder")."\";");
	}
	eval("\$folders = \"".$templates->get("private_empty")."\";");
	$plugins->run_hooks("private_empty_end");
	output_page($folders);
}

if($mybb->input['action'] == "do_empty" && $mybb->request_method == "post")
{
	$plugins->run_hooks("private_do_empty_start");
	$emptyq = '';
	if(is_array($mybb->input['empty']))
	{
		foreach($mybb->input['empty'] as $key => $val)
		{
			if($val == "yes")
			{
				$key = intval($key);
				if($emptyq)
				{
					$emptyq .= " OR ";
				}
				$emptyq .= "folder='$key'";
			}
		}
		if($emptyq != '')
		{
			if($mybb->input['keepunread'] == "yes")
			{
				$keepunreadq = " AND status!='0'";
			}
			$db->delete_query(TABLE_PREFIX."privatemessages", "($emptyq) AND uid='".$mybb->user['uid']."' $keepunreadq");
		}
	}
	// Update PM count
	update_pm_count();

	$plugins->run_hooks("private_do_empty_end");
	redirect("private.php", $lang->redirect_pmfoldersemptied);
}

if($mybb->input['action'] == "do_stuff" && $mybb->request_method == "post")
{
	$plugins->run_hooks("private_do_stuff");
	if($mybb->input['hop'])
	{
		header("Location: private.php?fid=".intval($mybb->input['jumpto']));
	}
	elseif($mybb->input['moveto'])
	{
		if(is_array($mybb->input['check']))
		{
			foreach($mybb->input['check'] as $key => $val)
			{
				$sql_array = array(
					"folder" => intval($mybb->input['fid'])
				);
				$db->update_query(TABLE_PREFIX."privatemessages", $sql_array, "pmid='".intval($key)."' AND uid='".$mybb->user['uid']."'");
			}
		}
		// Update PM count
		update_pm_count();

		redirect("private.php?fid=".$mybb->input['fid'], $lang->redirect_pmsmoved);
	}
	elseif($mybb->input['delete'])
	{
		if(is_array($mybb->input['check']))
		{
			$pmssql = '';
			foreach($mybb->input['check'] as $key => $val)
			{
				if($pmssql)
				{
					$pmssql .= ",";
				}
				$pmssql .= "'".intval($key)."'";
			}
			$query = $db->simple_select(TABLE_PREFIX."privatemessages", "pmid, folder", "pmid IN ($pmssql) AND uid='".$mybb->user['uid']."' AND folder='4'", array('order_by' => 'pmid'));
			while($delpm = $db->fetch_array($query))
			{
				$deletepms[$delpm['pmid']] = 1;
			}
			reset($mybb->input['check']);
			foreach($mybb->input['check'] as $key => $val)
			{
				$key = intval($key);
				if($deletepms[$key])
				{
					$db->delete_query(TABLE_PREFIX."privatemessages", "pmid='$key' AND uid='".$mybb->user['uid']."'");
				}
				else
				{
					$sql_array = array(
						"folder" => 4,
					);
					$db->update_query(TABLE_PREFIX."privatemessages", $sql_array, "pmid='".$key."' AND uid='".$mybb->user['uid']."'");
				}
			}
		}
		// Update PM count
		update_pm_count();

		redirect("private.php", $lang->redirect_pmsdeleted);
	}
}

if($mybb->input['action'] == "delete")
{
	$plugins->run_hooks("private_delete_start");

	$sql_array = array(
		"folder" => 4
	);
	$db->update_query(TABLE_PREFIX."privatemessages", $sql_array, "pmid='".intval($mybb->input['pmid'])."' AND uid='".$mybb->user['uid']."'");

	// Update PM count
	update_pm_count();

	$plugins->run_hooks("private_delete_end");
	redirect("private.php", $lang->redirect_pmsdeleted);
}

if($mybb->input['action'] == "export")
{
	$plugins->run_hooks("private_export_start");
	$folderlist = "<select name=\"exportfolders[]\" multiple>\n";
	$folderlist .= "<option value=\"all\" selected>$lang->all_folders</option>";
	$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
	foreach($foldersexploded as $key => $folders)
	{
		$folderinfo = explode("**", $folders, 2);
		$folderinfo[1] = get_pm_folder_name($folderinfo[0], $folderinfo[1]);
		$folderlist .= "<option value=\"$folderinfo[0]\">$folderinfo[1]</option>\n";
	}
	$folderlist .= "</select>\n";
	eval("\$archive = \"".$templates->get("private_archive")."\";");
	$plugins->run_hooks("private_export_end");
	output_page($archive);
}

if($mybb->input['action'] == "do_export" && $mybb->request_method == "post")
{
	if($mybb->input['exporttype'] != "csv" && $mybb->input['exporttype'] != "text" && $mybb->input['exporttype'] != "html")
	{
		exit;
	}

	$plugins->run_hooks("private_do_export_start");
	$lang->private_messages_for = sprintf($lang->private_messages_for, $mybb->user['username']);
	$exdate = my_date($mybb->settings['dateformat'], time(), 0, 0);
	$extime = my_date($mybb->settings['timeformat'], time(), 0, 0);
	$lang->exported_date = sprintf($lang->exported_date, $exdate, $extime);
	$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
	if($mybb->input['pmid'])
	{
		$wsql = "pmid='".intval($mybb->input['pmid'])."' AND uid='".$mybb->user['uid']."'";
	}
	else
	{
		if($mybb->input['daycut'] && ($mybb->input['dayway'] != "disregard"))
		{
			$datecut = time()-($mybb->input['daycut'] * 86400);
			$wsql = "pm.dateline";
			if($mybb->input['dayway'] == "older")
			{
				$wsql .= "<=";
			}
			elseif($mybb->input['dayway'] == "newer")
			{
				$wsql .= ">=";
			}
			$wsql .= "'$datecut'";
		}
		else
		{
			$wsql = "1=1";
		}
		if(is_array($mybb->input['exportfolders']))
		{
			$folderlst = '';
			reset($mybb->input['exportfolders']);
			foreach($mybb->input['exportfolders'] as $key => $val)
			{
				$val = $db->escape_string($val);
				if($val == "all")
				{
					$folderlst = '';
					break;
				}
				else
				{
					if(!$folderlst)
					{
						$folderlst = " AND pm.folder IN ('$val'";
					}
					else
					{
						$folderlst .= ",'$val'";
					}
				}
			}
			if($folderlst)
			{
				$folderlst .= ")";
			}
			$wsql .= "$folderlst";
		}
		else
		{
			error($lang->error_pmnoarchivefolders);
		}
		if($mybb->input['exportunread'] != "yes")
		{
			$wsql .= " AND pm.status!='0'";
		}
	}
	$query = $db->query("
		SELECT pm.*, fu.username AS fromusername, tu.username AS tousername
		FROM ".TABLE_PREFIX."privatemessages pm
		LEFT JOIN ".TABLE_PREFIX."users fu ON (fu.uid=pm.fromid)
		LEFT JOIN ".TABLE_PREFIX."users tu ON (tu.uid=pm.toid)
		WHERE $wsql AND pm.uid='".$mybb->user['uid']."'
		ORDER BY pm.folder ASC, pm.dateline DESC
	");
	$numpms = $db->num_rows($query);
	if(!$numpms)
	{
		error($lang->error_nopmsarchive);
	}
	$pmsdownload = '';
	while($message = $db->fetch_array($query))
	{
		if($message['folder'] == 2 || $message['folder'] == 3)
		{ // Sent Items or Drafts Folder Check
			if($message['toid'])
			{
				$tofromuid = $message['toid'];
				if($mybb->input['exporttype'] == "txt")
				{
					$tofromusername = $message['tousername'];
				}
				else
				{
					$tofromusername = build_profile_link($message['tousername'], $tofromuid);
				}
			}
			else
			{
				$tofromusername = $lang->not_sent;
			}
			$tofrom = $lang->to;
		}
		else
		{
			$tofromuid = $message['fromid'];
			if($mybb->input['exporttype'] == "txt")
			{
				$tofromusername = $message['fromusername'];
			}
			else
			{
				$tofromusername = build_profile_link($message['fromusername'], $tofromuid);
			}

			if($tofromuid == 0)
			{
				$tofromusername = "MyBB Engine";
			}
			$tofrom = $lang->from;
		}
		if($tofromuid == 0)
		{
			$message['fromusername'] = "MyBB Engine";
		}
		if(!$message['toid'])
		{
			$message['tousername'] = $lang->not_sent;
		}

		$message['subject'] = $parser->parse_badwords($message['subject']);
		if($message['folder'] != "3")
		{
			$senddate = my_date($mybb->settings['dateformat'], $message['dateline'], 0, 0);
			$sendtime = my_date($mybb->settings['timeformat'], $message['dateline'], 0, 0);
			$senddate .= " $lang->at $sendtime";
		}
		else
		{
			$senddate = $lang->not_sent;
		}
		if($mybb->input['exporttype'] == "html")
		{
			$parser_options = array(
				"allow_html" => $mybb->settings['pmsallowhtml'],
				"allow_mycode" => $mybb->settings['pmsallowmycode'],
				"allow_smilies" => "no",
				"allow_imgcode" => $mybb->settings['pmsallowimgcode'],
				"me_username" => $mybb->user['username']
			);

			$message['message'] = $parser->parse_message($message['message'], $parser_options);
		}
		if($mybb->input['exporttype'] == "txt" || $mybb->input['exporttype'] == "csv")
		{
			$message['message'] = str_replace("\r\n", "\n", $message['message']);
			$message['message'] = str_replace("\n", "\r\n", $message['message']);
		}
		if(!$donefolder[$message['folder']])
		{
			reset($foldersexploded);
			foreach($foldersexploded as $key => $val)
			{
				$folderinfo = explode("**", $val, 2);
				if($folderinfo[0] == $message['folder'])
				{
					$foldername = $folderinfo[1];
					if($mybb->input['exporttype'] != "csv")
					{
						eval("\$pmsdownload .= \"".$templates->get("private_archive_".$nmybb->input['exporttype']."_folderhead", 1, 0)."\";");
					}
					$donefolder[$message['folder']] = 1;
				}
			}
		}
		eval("\$pmsdownload .= \"".$templates->get("private_archive_".$mybb->input['exporttype']."_message", 1, 0)."\";");
		$ids .= ",'$message[pmid]'";
	}
	$query = $db->simple_select(TABLE_PREFIX."themes", "css", "tid='{$theme['tid']}'");
	$css = $db->fetch_field($query, "css");

	eval("\$archived = \"".$templates->get("private_archive_".$mybb->input['exporttype'], 1, 0)."\";");
	if($mybb->input['deletepms'] == "yes")
	{ // delete the archived pms
		$db->delete_query(TABLE_PREFIX."privatemessages", "pmid IN (''$ids)");
		// Update PM count
		update_pm_count();
	}
	if($mybb->input['exporttype'] == "html")
	{
		$filename = "pm-archive.html";
		$contenttype = "text/html";
	}
	elseif($mybb->input['exporttype'] == "csv")
	{
		$filename = "pm-archive.csv";
		$contenttype = "application/octet-stream";
	}
	else
	{
		$filename = "pm-archive.txt";
		$contenttype = "text/plain";
	}
	$archived = ereg_replace("\\\'","'",$archived);
	header("Content-disposition: filename=$filename");
	header("Content-type: ".$contenttype);
	$plugins->run_hooks("private_do_export_end");
	if($mybb->input['exporttype'] == "html")
	{
		output_page($archived);
	}
	else
	{
		echo $archived;
	}
}

if(!$mybb->input['action'])
{
	$plugins->run_hooks("private_start");
	if(!$mybb->input['fid'])
	{
		$mybb->input['fid'] = 1;
	}

	$foldersexploded = explode("$%%$", $mybb->user['pmfolders']);
	foreach($foldersexploded as $key => $folders)
	{
		$folderinfo = explode("**", $folders, 2);
		if($folderinfo[0] == $mybb->input['fid'])
		{
			$folder = $folderinfo[0];
			$foldername = get_pm_folder_name($folder, $folderinfo[1]);
		}
	}

	$lang->pms_in_folder = sprintf($lang->pms_in_folder, $foldername);
	if($folder == 2 || $folder == 3)
	{ // Sent Items Folder
		$sender = $lang->sentto;
	}
	else
	{
		$sender = $lang->sender;
	}
	$doneunread = 0;
	$doneread = 0;
	// get total messages
	$query = $db->simple_select(TABLE_PREFIX."privatemessages", "COUNT(*) AS total", "uid='".$mybb->user['uid']."'");
	$pmscount = $db->fetch_array($query);
	if($mybb->usergroup['pmquota'] != "0" && $pmscount['total'] >= $mybb->usergroup['pmquota'] && $mybb->usergroup['cancp'] != "yes")
	{
		eval("\$limitwarning = \"".$templates->get("private_limitwarning")."\";");
	}

	// Do Multi Pages
	$query = $db->simple_select(TABLE_PREFIX."privatemessages", "COUNT(*) AS total", "uid='".$mybb->user['uid']."' AND folder='$folder'");
	$pmscount = $db->fetch_array($query);

	$perpage = $mybb->settings['threadsperpage'];
	$page = intval($mybb->input['page']);
	if(intval($mybb->input['page']) > 0)
	{
		$start = ($page-1) *$perpage;
	}
	else
	{
		$start = 0;
		$page = 1;
	}
	$end = $start + $perpage;
	$lower = $start+1;
	$upper = $end;
	if($upper > $threadcount)
	{
		$upper = $threadcount;
	}
	$multipage = multipage($pmscount['total'], $perpage, $page, "private.php?fid=$folder");
	$messagelist = '';
	
	$icon_cache = $cache->read("posticons");
	
	$query = $db->query("
		SELECT pm.*, fu.username AS fromusername, tu.username AS tousername
		FROM ".TABLE_PREFIX."privatemessages pm
		LEFT JOIN ".TABLE_PREFIX."users fu ON (fu.uid=pm.fromid)
		LEFT JOIN ".TABLE_PREFIX."users tu ON (tu.uid=pm.toid)
		WHERE pm.folder='$folder' AND pm.uid='".$mybb->user['uid']."'
		ORDER BY pm.dateline DESC
		LIMIT $start, $perpage
	");
	if($db->num_rows($query) > 0)
	{
		while($message = $db->fetch_array($query))
		{
			$msgalt = $msgsuffix = $msgprefix = '';
			// Determine Folder Icon
			if($message['status'] == 0)
			{
				$msgfolder = 'new_pm.gif';
				$msgalt = $lang->new_pm;
				$msgprefix = "<strong>";
				$msgsuffix = "</strong>";
			}
			elseif($message['status'] == 1)
			{
				$msgfolder = 'old_pm.gif';
				$msgalt = $lang->old_pm;
			}
			elseif($message['status'] == 3)
			{
				$msgfolder = 're_pm.gif';
				$msgalt = $lang->reply_pm;
			}
			elseif($message['status'] == 4)
			{
				$msgfolder = 'fw_pm.gif';
				$msgalt = $lang->fwd_pm;
			}
			if($folder == 2 || $folder == 3)
			{ // Sent Items or Drafts Folder Check
				if($message['toid'])
				{
					$tofromusername = $message['tousername'];
					$tofromuid = $message['toid'];
				}
				else
				{
					$tofromusername = $lang->not_sent;
				}
			}
			else
			{
				$tofromusername = $message['fromusername'];
				$tofromuid = $message['fromid'];
				if($tofromuid == 0)
				{
					$tofromusername = 'MyBB Engine';
				}
			}
			if($mybb->usergroup['cantrackpms'] == 'yes' && $mybb->usergroup['candenypmreceipts'] == 'yes' && $message['receipt'] == '1' && $message['folder'] != '3' && $message['folder'] != 2)
			{
				eval("\$denyreceipt = \"".$templates->get("private_messagebit_denyreceipt")."\";");
			}
			else
			{
				$denyreceipt = '';
			}
			if($message['icon'] > 0 && $icon_cache[$message['icon']])
			{
				$icon = $icon_cache[$message['icon']];
				$icon = "<img src=\"{$icon['path']}\" alt=\"{$icon['name']}\" />&nbsp;";
			}
			else
			{
				$icon = '';
			}
			$message['subject'] = htmlspecialchars_uni($parser->parse_badwords($message['subject']));
			if($message['folder'] != "3")
			{
				$sendpmdate = my_date($mybb->settings['dateformat'], $message['dateline']);
				$sendpmtime = my_date($mybb->settings['timeformat'], $message['dateline']);
				$senddate = $sendpmdate.", ".$sendpmtime;
			}
			else
			{
				$senddate = $lang->not_sent;
			}
			eval("\$messagelist .= \"".$templates->get("private_messagebit")."\";");
		}
	}
	else
	{
		eval("\$messagelist .= \"".$templates->get("private_nomessages")."\";");
	}

	if($mybb->usergroup['pmquota'] > 0)
	{
		$query = $db->simple_select(TABLE_PREFIX."privatemessages", "COUNT(*) AS total", "uid='".$mybb->user['uid']."'");
		$pmscount = $db->fetch_array($query);
		if($pmscount['total'] == 0)
		{
			$spaceused = 0;
		}
		else
		{
			$spaceused = $pmscount['total'] / $mybb->usergroup['pmquota'] * 100;
		}
		$spaceused2 = 100 - $spaceused;
		if($spaceused <= "50")
		{
			$belowhalf = round($spaceused, 0)."%";
		}
		else
		{
			$overhalf = round($spaceused, 0)."%";
		}
		eval("\$pmspacebar = \"".$templates->get("private_pmspace")."\";");
	}
	eval("\$folder = \"".$templates->get("private")."\";");
	$plugins->run_hooks("private_end");
	output_page($folder);
}
?>