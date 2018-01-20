<?php
class MassEmail {
	public $settings = array(
		'name' => 'Mass Email',
		'admin_menu_category' => 'General',
		'admin_menu_name' => 'Mass Email',
		'admin_menu_icon' => '<i class="icon-email-envelope"></i>',
		'description' => 'Send a mass email to your users.',
	);
	function user_area() {
		global $billic, $db;
		if ($_GET['Unsubscribe'] == 'Y') {
			$billic->set_title('Unsubscribe');
			echo '<h1>Unsubscribe</h1><p>We\'re sorry to see you go.</p>';
			$email = base64_decode(urldecode($_GET['E']));
			$hash = urldecode($_GET['H']);
			$listid = $_GET['L'];
			//emailoptout
			if (md5(get_config('billic_push_key') . $email) != $hash) {
				err('Invalid link (something has corrupted it)');
			}
			if (isset($_POST['unsubscribe'])) {
				if ($_POST['unsub_future_lists'] == 1) $db->q('UPDATE `users` SET `emailoptout` = 1 WHERE `email` = ?', $email);
				if ($_POST['unsub_all_lists'] == 1) $db->q('UPDATE `massemail_emails` SET `unsubscribed` = 1 WHERE `email` = ?', $email);
				elseif ($_POST['unsub_this_list'] == 1) $db->q('UPDATE `massemail_emails` SET `unsubscribed` = 1 WHERE `email` = ? AND `listid` = ?', $email, $listid);
				echo '<div class="alert alert-success" role="alert">You have been unsubscribed.</div>';
				exit;
			}
			echo '<form method="POST">';
			if (!empty($listid)) {
				echo '<input type="checkbox" name="unsub_this_list" value="1" checked> Unsubscribe from this mailing list<br><br>';
			}
			echo '<input type="checkbox" name="unsub_all_lists" value="1" onClick="return confirm(\'Warning: You may not receive service-related emails!\');"> Unsubscribe from <b>ALL</b> mailing lists<br><br>';
			echo '<input type="checkbox" name="unsub_future_lists" value="1" onClick="return confirm(\'Warning: You will not receive any new mailing lists we may create!\');"> Unsubscribe from all <b>future</b> mailing lists<br><br>';
			echo '<input type="submit" class="btn btn-success" name="unsubscribe" value="Save Settings &raquo;">';
			echo '</form>';
		}
	}
	function admin_area() {
		global $billic, $db;
		ini_set('memory_limit', '100M');
		if ($_GET['Action'] == 'Bounce') {
			echo '<h1><i class="icon-email-envelope"></i> Mass Email &raquo; Bounce Handler</h1>';
			$error_rep = error_reporting(E_ALL);
			$imap = imap_open("{localhost:993/imap/ssl/novalidate-cert}INBOX", "bounce@servebyte.com", "@nMW[xdLXf,C");
			if ($imap) {
				//Check no.of.msgs
				$num = imap_num_msg($imap);
				//if there is a message in your inbox
				if ($num > 0) {
					//read that mail recently arrived
					echo imap_qprint(imap_fetchbody($imap, $num, 0));
				}
				//close the stream
				imap_close($imap);
			}
			error_reporting($error_rep);
			return;
		}
		if ($_GET['Action'] == 'CreateList') {
			echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; Create Mailing List</h1>';
			if (isset($_POST['create'])) {
				$listID = $db->insert('massemail_lists', array(
					'desc' => $_POST['desc'],
				));
				if (!isset($_POST['create_empty_list'])) {
					$users = [];
					if (isset($_POST['servicemodule'])) {
						$services = $db->q('SELECT `userid` FROM `services` WHERE `module` = ? GROUP BY `userid`', $_POST['servicemodule']);
						foreach ($services as $service) {
							$users[] = $service['userid'];
						}
					}
					if (empty($users)) {
						$users_tmp = $db->q('SELECT `id` FROM `users`');
						foreach ($users_tmp as $user) {
							$users[] = $user['id'];
						}
					}
					if (isset($_POST['users_terminated_services'])) {
						foreach ($users as $k => $userid) {
							$hasTerminated = false;
							$hasActive = false;
							$services = $db->q('SELECT `domainstatus` FROM `services` WHERE `userid` = ?', $userid);
							foreach ($services as $service) {
								if ($service['domainstatus'] == 'Active') {
									$hasActive = true;
								} elseif ($service['domainstatus'] == 'Terminated') {
									$hasTerminated = true;
								}
							}
							if (!($hasActive === false && $hasTerminated === true)) {
								unset($users[$k]);
							}
						}
					}
					foreach ($users as $k => $userid) {
						$sql = 'SELECT `firstname`, `lastname`, `email` FROM `users` WHERE `id` = ?';
						if ($_POST['ignore_blocked_orders'] == 1) {
							$sql.= ' AND `blockorders` = 0';
						}
						$user = $db->q($sql, $userid);
						$user = $user[0];
						if (empty($user)) continue;
						if (isset($_POST['preserve_unsubscription']) && $user['emailoptout'] == 1) continue;
						$db->insert('massemail_emails', array(
							'listid' => $listID,
							'email' => $user['email'],
							'name' => $user['firstname'] . ' ' . $user['lastname']
						));
					}
				}
				$billic->redirect('/Admin/MassEmail/Action/ManageList/ID/' . $listID . '/');
			}
			$modules = $db->q('SELECT `module` FROM `services` GROUP BY `module`');
			$servicemodules = '<option value="">-- Do not filter by module --</option>';
			foreach ($modules as $module) {
				$servicemodules.= '<option value="' . $module['module'] . '">' . $module['module'] . '</option>';
			}
			echo <<<CODE
<form method="POST">
	<table class="table">
		<tr><th>New List</th><th></th></tr>
		<tr><td>Description:</td><td><input type="text" name="desc" class="form-control"></td></tr>
		<tr><th width="200">Filter</th><th></th></tr>
		<tr><td>Service Module:</td><td><select name="servicemodule" class="form-control">$servicemodules</select></td></tr>
		<tr><td colspan="2"><input type="checkbox" name="create_empty_list" value="1"> Create an empty list. (Ignores all other options.)</td></tr>
		<tr><td colspan="2"><input type="checkbox" name="users_terminated_services" value="1"> Match only users with terminated services.</td></tr>
		<tr><td colspan="2"><input type="checkbox" name="preserve_unsubscription" value="1" checked onClick="return confirm('Warning: Do not spam people!')"> Do not add users to this list which have previously unsubscribed.</td></tr>
		<tr><td colspan="2"><input type="checkbox" name="ignore_blocked_orders" value="1" checked> Do not add users who have orders blocked.</td></tr>
	</table>
	<div align="center"><input type="submit" name="create" value="Create List &raquo;" class="btn btn-success"></div>
</form>
CODE;
			return;
		}
		if ($_GET['Action'] == 'DeleteList') {
			$db->q('DELETE FROM `massemail_emails` WHERE `listid` = ?', $_GET['ID']);
			$db->q('DELETE FROM `massemail_lists` WHERE `id` = ?', $_GET['ID']);
			$billic->redirect('/Admin/MassEmail/Action/ManageLists/');
		}
		if ($_GET['Action'] == 'ManageLists') {
			echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; Manage Lists</h1>';
			$billic->module("ListManager");
			$total = $db->q('SELECT COUNT(*) FROM `massemail_lists`');
			$total = $total[0]['COUNT(*)'];
			$pagination = $billic->pagination(array(
				'total' => $total,
			));
			echo $pagination['menu'];
			$lists = $db->q('SELECT * FROM `massemail_lists` ORDER BY `id` DESC LIMIT ' . $pagination['start'] . ',' . $pagination['limit']);
			echo '<div style="float: right;padding-right: 40px;">Showing ' . $pagination['start_text'] . ' to ' . $pagination['end_text'] . ' of ' . $total . ' Lists</div>' . $billic->modules['ListManager']->search_link();
			echo '<table class="table table-striped"><tr><th>ID</th><th>Description</th><th>Created</th><th>Contacts</th><th>Actions</th></tr>';
			if (empty($lists)) echo '<tr><td colspan="20">None to display.</td></tr>';
			foreach ($lists as $list) {
				$count = $db->q('SELECT COUNT(*) FROM `massemail_emails` WHERE `listid` = ?', $list['id']);
				$count = $count[0]['COUNT(*)'];
				echo '<tr><td>' . safe($list['id']) . '</td><td>' . safe($list['desc']) . '</td><td>' . safe($list['created']) . '</td><td>' . safe($count) . '</td><td><a href="/Admin/MassEmail/Action/ManageList/ID/' . $list['id'] . '/" class="btn btn-primary btn-xs">Manage List</a> <a href="/Admin/MassEmail/Action/DeleteList/ID/' . $list['id'] . '/" class="btn btn-danger btn-xs" onClick="return confirm(\'Are you sure you want to delete this list, along with all the contacts?\')">Delete List</a></td></tr>';
			}
			echo '</table>';
			return;
		}
		if ($_GET['Action'] == 'ManageList') {
			$list = $db->q('SELECT * FROM `massemail_lists` WHERE `id` = ?', $_GET['ID']);
			$list = $list[0];
			if (empty($list)) {
				err('List does not exist');
			}
			if ($_GET['Do'] == 'AddEmails') {
				echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; <a href="/Admin/MassEmail/Action/ManageLists/">Manage List</a> &raquo; <a href="/Admin/MassEmail/Action/ManageList/ID/' . $list['id'] . '/">' . safe($list['desc']) . '</a> &raquo; Add Emails</h1>';
				if (isset($_POST['emails'])) {
					$emails = str_replace("\r", '', $_POST['emails']);
					$emails = explode(PHP_EOL, $emails);
					foreach ($emails as $email) {
						$tmp = explode(' ', $email);
						$email = $tmp[0];
						unset($tmp[0]);
						$name = implode(' ', $tmp);
						if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
							$db->insert('massemail_emails', array(
								'listid' => $list['id'],
								'email' => $email,
								'name' => $name
							));
						}
					}
					$billic->redirect('/Admin/MassEmail/Action/ManageList/ID/' . $list['id'] . '/');
				}
				echo '<p>Put each email address on a new line followed by an optional name. For example;<br>email1@example.org John Doe<br>email2@example.org John Smith<br>email3@example.org Joe Smith</p><form method="POST"><textarea class="form-control" name="emails" rows="12" style="width: 100%">' . safe($_POST['body']) . '</textarea><br><div align="center"><input type="submit" name="addemails" value="Add Emails &raquo;" class="btn btn-success"></div></form>';
				return;
			}
			echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; <a href="/Admin/MassEmail/Action/ManageLists/">Manage List</a> &raquo; ' . safe($list['desc']) . '</h1>';
			echo '<a href="/Admin/MassEmail/Action/ManageList/ID/' . $list['id'] . '/Do/AddEmails/" class="btn btn-success">Add Emails</a> ';
			echo '<br><br>';
			if (isset($_POST['delete'])) {
				if (empty($_POST['ids'])) {
					$billic->errors[] = 'No emails were selected to be deleted.';
				} else {
					foreach ($_POST['ids'] as $id => $crap) {
						$db->q('DELETE FROM `massemail_emails` WHERE `id` = ?', $id);
					}
					$_status = 'deleted';
				}
			}
			$billic->module("ListManager");
			$total = $db->q('SELECT COUNT(*) FROM `massemail_emails` WHERE `listid` = ?', $list['id']);
			$total = $total[0]['COUNT(*)'];
			$pagination = $billic->pagination(array(
				'total' => $total,
			));
			echo $pagination['menu'];
			$emails = $db->q('SELECT * FROM `massemail_emails` WHERE `listid` = ? ORDER BY `email` ASC LIMIT ' . $pagination['start'] . ',' . $pagination['limit'], $list['id']);
			$billic->show_errors();
			echo '<div style="float: right;padding-right: 40px;">Showing ' . $pagination['start_text'] . ' to ' . $pagination['end_text'] . ' of ' . $total . ' Contacts</div>' . $billic->modules['ListManager']->search_link();
			echo '<form method="POST">With Selected: <button type="submit" class="btn btn-xs btn-danger" name="delete" onclick="return confirm(\'Are you sure you want to delete the selected emails?\');"><i class="icon-remove"></i> Delete</button><br><table class="table table-striped"><tr><th><input type="checkbox" onclick="checkAll(this, \'ids\')"></th><th>Name</th><th>Email Address</th><th style="text-align:center">Subscribed</th><th>Email Last Sent</th></tr>';
			if (empty($emails)) echo '<tr><td colspan="20">None to display.</td></tr>';
			foreach ($emails as $email) {
				if ($email['lastsent'] === null) {
					$email['lastsent'] = 'Never';
				}
				echo '<tr><td><input type="checkbox" name="ids[' . $email['id'] . ']"></td><td>' . safe($email['name']) . '</td><td>' . safe($email['email']) . '</td><td align="center">' . ($email['unsubscribed'] == 0 ? '&#10003;' : '&#x2718;') . '</td><td>' . safe($email['lastsent']) . '</td></tr>';
			}
			echo '</table></form>';
			return;
		}
		if ($_GET['Action'] == 'Sent') {
			if (isset($_GET['PreviewBody'])) {
				$sent = $db->q('SELECT * FROM `massemail_sent` WHERE `id` = ?', $_GET['PreviewBody']);
				$sent = $sent[0];
				if (empty($sent)) {
					die('Invalid email');
				}
				echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; <a href="/Admin/MassEmail/Action/Sent/">Sent Emails</a> &raquo; Body for email #' . $sent['id'] . '</h1>';
				echo '<pre>' . safe($sent['body']) . '</pre>';
				return;
			}
			echo '<h1><i class="icon-email-envelope"></i> <a href="/Admin/MassEmail/">Mass Email</a> &raquo; Sent Emails</h1>';
			$billic->module("ListManager");
			$total = $db->q('SELECT COUNT(*) FROM `massemail_sent`');
			$total = $total[0]['COUNT(*)'];
			$pagination = $billic->pagination(array(
				'total' => $total,
			));
			echo $pagination['menu'];
			$sents = $db->q('SELECT * FROM `massemail_sent` ORDER BY `id` DESC LIMIT ' . $pagination['start'] . ',' . $pagination['limit']);
			echo '<div style="float: right;padding-right: 40px;">Showing ' . $pagination['start_text'] . ' to ' . $pagination['end_text'] . ' of ' . $total . ' Emails</div>' . $billic->modules['ListManager']->search_link();
			echo '<table class="table table-striped"><tr><th>ID</th><th>Subject</th><th>Created</th><th>Finished</th><th>Progress</th><th>Actions</th></tr>';
			if (empty($sents)) echo '<tr><td colspan="20">None to display.</td></tr>';
			foreach ($sents as $sent) {
				if ($sent['finished'] === null) {
					$count = $db->q('SELECT COUNT(*) FROM `massemail_emails` WHERE `listid` = ? AND `unsubscribed` = 0', $sent['listid']);
					$numRecipients = $count[0]['COUNT(*)'];
					$count = $db->q('SELECT COUNT(*) FROM `massemail_emails` WHERE `listid` = ? AND `unsubscribed` = 0 AND `id` > ?', $sent['listid'], $sent['lastemailid']);
					$numRecipientsRemaining = $count[0]['COUNT(*)'];
					$numSent = ($numRecipients - $numRecipientsRemaining);
					$progress = 'Sent ' . $numSent . ' of ' . $numRecipients . ' emails (' . round((100 / $numRecipients) * $numSent, 1) . '%)';
				} else {
					$progress = '100%';
				}
				echo '<tr><td>' . safe($sent['id']) . '</td><td>' . safe($sent['subject']) . '</td><td>' . safe($sent['created']) . '</td><td>' . safe($sent['finished']) . '</td><td>' . $progress . '</td><td><a href="/Admin/MassEmail/Action/Sent/PreviewBody/' . $sent['id'] . '/" class="btn btn-primary btn-xs">Preview Body</a></td></tr>';
			}
			echo '</table>';
			return;
		}
		echo '<h1><i class="icon-email-envelope"></i> Mass Email</h1>';
		echo '<a href="/Admin/MassEmail/Action/CreateList/" class="btn btn-success">Create a List</a> ';
		echo '<a href="/Admin/MassEmail/Action/ManageLists/" class="btn btn-primary">Manage Lists</a> ';
		echo '<a href="/Admin/MassEmail/Action/Sent/" class="btn btn-primary">Sent Emails</a> ';
		echo '<br><br>';
		if (!empty($_POST['preview']) || !empty($_POST['send'])) {
			if (empty($_POST['listid'])) {
				$billic->errors[] = 'You must select a mailing list';
			}
			if (empty($billic->errors)) {
				$list = $db->q('SELECT * FROM `massemail_lists` WHERE `id` = ?', $_POST['listid']);
				$list = $list[0];
				if (empty($list)) {
					$billic->errors[] = 'Invalid mailing list';
				}
			}
		}
		if (!empty($_POST['preview'])) {
			if (empty($billic->errors)) {
				$emails = $db->q('SELECT * FROM `massemail_emails` WHERE `listid` = ? LIMIT 5', $list['id']);
				$iframe = 0;
				echo <<<CODE
<script>var Base64={_keyStr:"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",encode:function(e){var t="";var n,r,i,s,o,u,a;var f=0;e=Base64._utf8_encode(e);while(f<e.length){n=e.charCodeAt(f++);r=e.charCodeAt(f++);i=e.charCodeAt(f++);s=n>>2;o=(n&3)<<4|r>>4;u=(r&15)<<2|i>>6;a=i&63;if(isNaN(r)){u=a=64}else if(isNaN(i)){a=64}t=t+this._keyStr.charAt(s)+this._keyStr.charAt(o)+this._keyStr.charAt(u)+this._keyStr.charAt(a)}return t},decode:function(e){var t="";var n,r,i;var s,o,u,a;var f=0;e=e.replace(/[^A-Za-z0-9+/=]/g,"");while(f<e.length){s=this._keyStr.indexOf(e.charAt(f++));o=this._keyStr.indexOf(e.charAt(f++));u=this._keyStr.indexOf(e.charAt(f++));a=this._keyStr.indexOf(e.charAt(f++));n=s<<2|o>>4;r=(o&15)<<4|u>>2;i=(u&3)<<6|a;t=t+String.fromCharCode(n);if(u!=64){t=t+String.fromCharCode(r)}if(a!=64){t=t+String.fromCharCode(i)}}t=Base64._utf8_decode(t);return t},_utf8_encode:function(e){e=e.replace(/rn/g,"n");var t="";for(var n=0;n<e.length;n++){var r=e.charCodeAt(n);if(r<128){t+=String.fromCharCode(r)}else if(r>127&&r<2048){t+=String.fromCharCode(r>>6|192);t+=String.fromCharCode(r&63|128)}else{t+=String.fromCharCode(r>>12|224);t+=String.fromCharCode(r>>6&63|128);t+=String.fromCharCode(r&63|128)}}return t},_utf8_decode:function(e){var t="";var n=0;var r=c1=c2=0;while(n<e.length){r=e.charCodeAt(n);if(r<128){t+=String.fromCharCode(r);n++}else if(r>191&&r<224){c2=e.charCodeAt(n+1);t+=String.fromCharCode((r&31)<<6|c2&63);n+=2}else{c2=e.charCodeAt(n+1);c3=e.charCodeAt(n+2);t+=String.fromCharCode((r&15)<<12|(c2&63)<<6|c3&63);n+=3}}return t}}
</script>
CODE;
				$menu = '<ul class="nav nav-tabs" style="margin-left: 10px">';
				$previews = '<div class="tab-content">';
				foreach ($emails as $email) {
					$name = ucwords(strtolower($email['name']));
					$firstname = explode(' ', $name);
					$firstname = $firstname[0];
					$menu.= '<li' . ($iframe == 0 ? ' class="active"' : '') . '><a href="#preview' . $iframe . '" data-toggle="tab">' . $name . '</a></li>';
					$subject = $_POST['subject'];
					$subject = str_replace('%firstname%', $firstname, $subject);
					$subject = str_replace('%name%', $name, $subject);
					$body = $_POST['body'];
					$body = str_replace('%firstname%', $firstname, $body);
					$body = str_replace('%name%', $name, $body);
					$body = str_replace('%unsublink%', 'http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/MassEmail/Unsubscribe/Y/E/' . urlencode(base64_encode($email['email'])) . '/H/' . urlencode(md5(get_config('billic_push_key') . $email['email'])) . '/L/' . $list['id'] . '/', $body);
					//$billic->email($email['email'], $subject, $body);
					$previews.= '<div class="tab-pane' . ($iframe == 0 ? ' active' : '') . '" id="preview' . $iframe . '">Preview of email to be sent to ' . $name . '. (' . $email['email'] . ')<br><b>' . htmlentities($subject) . '</b><br><iframe id="massemail_iframe' . $iframe . '" style="width:320px;height:480px;resize: both;overflow: auto;" scrolling="auto" frameborder="1"></iframe><script>addLoadEvent(function() { $("#massemail_iframe' . $iframe . '").contents().find("html").html(Base64.decode(\'' . base64_encode($body) . '\')); });</script></div>';
					$iframe++;
				}
				$menu.= '</ul>';
				$previews.= '</div>';
				echo $menu . $previews;
				echo '<form method="POST">';
				echo '<input type="hidden" name="listid" value="' . safe($_POST['listid']) . '">';
				echo '<input type="hidden" name="subject" value="' . safe($_POST['subject']) . '">';
				echo '<input type="hidden" name="body" value="' . safe($_POST['body']) . '">';
				echo '<input type="submit" class="btn btn-warning" value="&laquo; Edit Email"> ';
				echo '<input type="submit" name="send" class="btn btn-success" value="Send Emails &raquo;" onclick="return confirm(\'Are you sure you want to send the email to the entire list?\');">';
				echo '</form>';
				return;
			}
		} else if (isset($_POST['send'])) {
			$emailid = $db->insert('massemail_sent', array(
				'listid' => $list['id'],
				'subject' => $_POST['subject'],
				'body' => $_POST['body'],
			));
			echo 'Email #' . $emailid . ' has been queued! <a href="/Admin/MassEmail/Action/Sent/">Click here</a> to view the progress.';
			return;
		}
		$billic->show_errors();
		$listshtml = '';
		$lists = $db->q('SELECT `id`, `desc` FROM `massemail_lists` ORDER BY `id` DESC');
		if (empty($lists)) {
			echo '<p>To create a new email, please create a list first.</p>';
			return;
		}
		foreach ($lists as $list) {
			$count = $db->q('SELECT COUNT(*) FROM `massemail_emails` WHERE `listid` = ?', $list['id']);
			$count = $count[0]['COUNT(*)'];
			$listshtml.= '<option value="' . $list['id'] . '"' . ($_POST['listid'] == $list['id'] ? ' selected' : '') . '>#' . $list['id'] . ' &rarr; ' . safe($list['desc']) . ' &bull; ' . $count . ' Contacts</option>';
		}
		if (empty($_POST['body'])) {
			$_POST['body'] = 'Dear %name%,<br>
<br>
<br>
Thank you,<br>
' . get_config('billic_companyname') . '<br>
<hr />
<a href="%unsublink%">Click here to unsubscribe from future emails.</a><br>';
		}
		echo '<form method="POST"><table class="table table-striped"><tr><th colspan="2">New Mass Email</th></tr>';
		echo '<tr><td width="100">Send to:</td><td><select name="listid" class="form-control"><option value="">--- Select a mailing list ---</option>' . $listshtml . '</select></td></tr>';
		echo '</table><br><table class="table table-striped"><tr><th>Email Subject</th></tr>';
		echo '<tr><td><input type="text" class="form-control" name="subject" style="width: 100%" value="' . safe($_POST['subject']) . '"></td></tr>';
		echo '</table><br><table class="table table-striped"><tr><th>Email Body</th></tr>';
		echo '<tr><td><textarea name="body" rows="12" style="width: 100%" id="email_body">' . safe($_POST['body']) . '</textarea></td></tr>';
		echo '</table><div align="center"><input type="submit" class="btn btn-success" name="preview" value="Preview &raquo;"></div></form>%firstname% = The user\'s first name.<br>%name% = The user\'s full name.<br>%unsublink% = unsubscribe link';
		echo '<script src="//cdn.ckeditor.com/4.5.9/full/ckeditor.js"></script><script>addLoadEvent(function() {
	// Update message while typing (part 1)z
	key_count_global = 0; // Global variable

	CKEDITOR.replace(\'email_body\', {   
		allowedContent: true,
		enterMode: CKEDITOR.ENTER_BR,
		disableNativeSpellChecker: false,
	});
});</script>';
	}
	function cron() {
		global $billic, $db;
		$start = time();
		$sents = $db->q('SELECT * FROM `massemail_sent` WHERE `finished` IS NULL ORDER BY `id` DESC');
		foreach ($sents as $sent) {
			// TODO: Configurable number of emails per minute
			$nextRecipients = $db->q('SELECT * FROM `massemail_emails` WHERE `listid` = ? AND `unsubscribed` = 0 AND `id` > ? LIMIT 10', $sent['listid'], $sent['lastemailid']);
			if (empty($nextRecipients)) {
				$db->q('UPDATE `massemail_sent` SET `finished` = NOW() WHERE `id` = ?', $sent['id']);
				break;
			} else {
				foreach ($nextRecipients as $email) {
					if (time() - $start > 30) // Do not spend longer than 30 seconds sending emails, since the cron job is ran every minute
					break;
					$name = ucwords(strtolower($email['name']));
					$firstname = explode(' ', $name);
					$firstname = $firstname[0];
					$subject = $sent['subject'];
					$subject = str_replace('%firstname%', $firstname, $subject);
					$subject = str_replace('%name%', $name, $subject);
					$body = $sent['body'];
					$body = str_replace('%firstname%', $firstname, $body);
					$body = str_replace('%name%', $name, $body);
					// TODO: Improve unsubscribe to include mailing lists
					$body = str_replace('%unsublink%', 'http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/User/MassEmail/Unsubscribe/Y/E/' . urlencode(base64_encode($email['email'])) . '/H/' . urlencode(md5(get_config('billic_push_key') . $email['email'])) . '/L/' . $list['id'] . '/', $body);
					$billic->email($email['email'], $subject, $body);
					$sent['lastemailid'] = $email['id'];
				}
				$db->q('UPDATE `massemail_sent` SET `lastemailid` = ? WHERE `id` = ?', $sent['lastemailid'], $sent['id']);
			}
		}
	}
}
