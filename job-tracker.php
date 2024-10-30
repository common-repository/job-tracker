<?php
/*
 Plugin Name: Job Tracker
 Plugin URI: http://mohanjith.com/wordpress/job-tracker.html
 Description: Track jobs and create jobs for completed or shipped jobs
 Author: S H Mohanjith
 Version: 0.1.9
 Author URI: http://mohanjith.com/
 Text Domain: job-tracker
 License: GPL

 Copyright 2009  S H Mohanjith (email : moha@mohanjith.net)
 */

define("JOB_TRACKER_VERSION_NUM", "0.1.7");
define("JOB_TRACKER_TRANS_DOMAIN", "job-tracker-rcp");

global $job_tracker, $_job_tracker_meta_cache, $_job_tracker_clear_cache, $_job_tracker_getinfo;

$job_tracker = new Job_Tracker();

// ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.$job_tracker->path);

class Job_Tracker {
	private $uri;
	private $the_path;
	
	public function the_path() {
		$path =	WP_PLUGIN_URL."/".basename(dirname(__FILE__));
		return $path;
	}
	
	public function tablename($table) {
		global $table_prefix;
		return $table_prefix.'job_tracker_'.$table;
	}

	public function frontend_path() {
		$path =	WP_PLUGIN_URL."/".basename(dirname(__FILE__));
		if(get_option('job_tracker_force_https') == 'true') $path = str_replace('http://','https://',$path);
		return $path;
	}

	public function __construct() {

		$version = get_option('job_tracker_version');
		$_file = "job-tracker/" . basename(__FILE__);

		$this->path = dirname(__FILE__);
		$this->file = basename(__FILE__);
		$this->directory = basename($this->path);
		$this->uri = WP_PLUGIN_URL."/".$this->directory;
		$this->the_path = $this->the_path();
		
		register_activation_hook($_file, array(&$this, 'install'));
		register_deactivation_hook($_file, array(&$this, 'uninstall'));

		add_action('init',  array($this, 'init'), 0);
		add_action('admin_head', array($this, 'admin_head'));
		add_action('admin_menu', array($this, 'add_pages'));
		
		add_filter('wp_redirect', array($this, 'redirect'));
		add_filter('the_content', array($this, 'the_content'));
		add_action('wp_head', 'job_tracker_frontend_css');
		
		$this->SetUserAccess(get_option('job_tracker_user_level'));
	}
	
	public function __destruct() {
	}
	
	public function SetUserAccess($level = 8) {
		if (empty($level)) {
			$level = 8;
		}
		if (is_array($level) && count($level) > 0) {
			$this->job_tracker_user_level = 'manage_job_tracker';
		} else {
			$this->job_tracker_user_level = $level;
		}
	}
	
	public function uninstall() {
		global $wpdb;
	}

	public function install() {
		global $wpdb;

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$sql_main = "CREATE TABLE IF NOT EXISTS ". Job_Tracker::tablename('jobs') ." (
				  id int(11) NOT NULL auto_increment,
				  user_id varchar(20) NOT NULL default '',
				  role_id int NOT NULL,
				  display_id varchar(32) NOT NULL,
				  created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  subject text NOT NULL,
				  description text NOT NULL,
				  `status` enum('created','accepted', 'working', 'completed','shipped') NOT NULL,
				  `archived` enum('0','1') NOT NULL,
				  PRIMARY KEY (id),
				  UNIQUE KEY `display_id` (`display_id`)
				);";
		dbDelta($sql_main);
		
		$sql_log = "CREATE TABLE IF NOT EXISTS " . Job_Tracker::tablename('log') . " (
					  id bigint(20) NOT NULL auto_increment,
					  job_id int(11) NOT NULL default '0',
					  action_type varchar(255) NOT NULL,
					  `value` longtext NOT NULL,
					  time_stamp timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
					  PRIMARY KEY  (id)
					);";
		dbDelta($sql_log);
		
		$sql_meta= "CREATE TABLE IF NOT EXISTS `" . Job_Tracker::tablename('meta') . "` (
				`meta_id` bigint(20) NOT NULL auto_increment,
				`job_id` bigint(20) NOT NULL default '0',
				`meta_key` varchar(255) default NULL,
				`meta_value` longtext,
				PRIMARY KEY  (`meta_id`),
				KEY `job_id` (`job_id`),
				KEY `meta_key` (`meta_key`)
				);";
		dbDelta($sql_meta);
		
		add_option('job_tracker_version', JOB_TRACKER_VERSION_NUM);
		add_option('job_tracker_protocol', 'http');
		add_option('job_tracker_user_level', array('administrator'));
		
		$current_role = get_option('job_tracker_user_level');
		
		if (!is_array($current_role) || in_array('level_8', $current_role)) {
			$current_role = array('administrator');
			update_option('job_tracker_user_level', $current_role);
		}
		
		$ro = new WP_Roles();
		foreach ($ro->role_objects as $role) {
			if (in_array($role->name, $current_role)) {
				$role->add_cap('manage_job_tracker', true);
			}
		}
			
		add_option('job_tracker_page','');
		add_option('job_tracker_redirect_after_user_add', 'no');
		add_option('job_tracker_force_https','false');
	}
	
	public function init() {
		global $wpdb, $wp_version;

		if (version_compare($wp_version, '2.6', '<')) // Using old WordPress
			load_plugin_textdomain(JOB_TRACKER_TRANS_DOMAIN, PLUGINDIR.'/'.dirname(plugin_basename(__FILE__)).'/languages');
		else
			load_plugin_textdomain(JOB_TRACKER_TRANS_DOMAIN, PLUGINDIR.'/'.dirname(plugin_basename(__FILE__)).'/languages', dirname(plugin_basename(__FILE__)).'/languages');

		wp_enqueue_script('jquery');

		if(is_admin()) {
			wp_enqueue_script('jquery.tablesorter',$this->uri."/js/jquery.tablesorter.min.js", array('jquery'), '1.8.0');
			wp_enqueue_script('jquery.autogrow-textarea',$this->uri."/js/jquery.autogrow-textarea.js", array('jquery'), '1.8.0');
			wp_enqueue_script('job-tracker',$this->uri."/js/job-tracker.js", array('jquery', 'jquery.tablesorter', 'jquery.autogrow-textarea'), '0.1.1');
			
			if (isset($_REQUEST['job_tracker_action'])) {
				switch ($_REQUEST['job_tracker_action']) {
					case 'create_invoice':
						if (isset($_REQUEST['invoice_id'])) {
							$invoice_id = $_REQUEST['invoice_id'];
						} else {
							$invoice_id = null;
						}
						if (isset($_REQUEST['job_id'])) {
							$job_id = $_REQUEST['job_id'];
							job_tracker_create_invoice($job_id, $invoice_id);
						}
						break;
				}
			}
		}
	}
	
	public function admin_head() {
		global $wpdb;
		
		echo "<link rel='stylesheet' href='".$this->uri."/css/wp_admin.css?v=0.1.0' type='text/css'type='text/css' media='all' />";
	}
	
	public function redirect($location) {
		if (get_option('job_tracker_redirect_after_user_add') == 'yes' && preg_match('/^users\.php\?usersearch/', $location) > 0) {
			return 'admin.php?page=job_tracker_new_job';
		}
		return $location;
	}
	
	public function add_pages() {
		global $job_tracker;
		
		$file = "job-tracker/" . basename(__FILE__);

		add_menu_page(__('Job Tracker System'), __('Job Tracker'),  $this->job_tracker_user_level, $file, array(&$this,'job_overview'), $this->uri."/images/33-cabinet.png");
		add_submenu_page($file, __("Manage Job"), __("New Job"), $this->job_tracker_user_level, 'job_tracker_new_job', array(&$this,'new_job'));
		add_submenu_page($file, __("Settings"), __("Settings"), $this->job_tracker_user_level, 'job_tracker_settings', array(&$this,'settings_page'));
		
		add_submenu_page('profile.php', __("Your jobs"), __("Jobs"), 'subscriber', 'user_job_overview', array(&$this,'job_overview_user'));
		// add_submenu_page('profile.php', __("Your jobs"), __("Jobs"), 'administrator', 'user_job_overview', array(&$this,'job_overview_user'));
	}
	
	public function settings_page() {
		global $wpdb;
		
		if(isset($_POST['job_tracker_update_settings'])) {
			if(isset($_POST['job_tracker_protocol'])) update_option('job_tracker_protocol', $_POST['job_tracker_protocol']);
			if(isset($_POST['job_tracker_page'])) update_option('job_tracker_page', $_POST['job_tracker_page']);
			if(isset($_POST['job_tracker_redirect_after_user_add'])) update_option('job_tracker_redirect_after_user_add', $_POST['job_tracker_redirect_after_user_add']);
			if(isset($_POST['job_tracker_force_https'])) update_option('job_tracker_force_https', $_POST['job_tracker_force_https']);
			
			if(isset($_POST['job_tracker_user_level'])) {
				if (is_array($_POST['job_tracker_user_level']) && count($_POST['job_tracker_user_level']) > 0) {
					$ro = new WP_Roles();
					foreach ($ro->role_objects as $role) {
						if ($role->has_cap('manage_job_tracker') && !in_array($role->name, $_POST['job_tracker_user_level'])) {
							$role->remove_cap('manage_job_tracker');
						}
						if (!$role->has_cap('manage_job_tracker') && in_array($role->name, $_POST['job_tracker_user_level'])) {
							$role->add_cap('manage_job_tracker', true);
						}
					}
				}
				update_option('job_tracker_user_level', $_POST['job_tracker_user_level']);	
			}
		}
	?>
	<div class="wrap">
		<h2><?php _e("Job Tracker Settings", JOB_TRACKER_TRANS_DOMAIN) ?></h2>
<form method="POST">
<table class="form-table" id="settings_page_table" style="clear: none;">

	<tr>
		<th><a class="job_tracker_tooltip"
			title="<?php _e("Select the page where your jobs will be displayed. Clients must follow their secured link, simply opening the page will not show any jobs.", JOB_TRACKER_TRANS_DOMAIN) ?>"><?php _e("Page to Display Jobs", JOB_TRACKER_TRANS_DOMAIN) ?></a>:</th>
		<td><select name='job_tracker_page'>
			<option></option>
			<?php $list_pages = $wpdb->get_results("SELECT ID, post_title, post_name, guid FROM ". $wpdb->prefix ."posts WHERE post_status = 'publish' AND post_type = 'page' ORDER BY post_title");
			$job_tracker_page = get_option('job_tracker_page');
			foreach ($list_pages as $page)
			{
				echo "<option  style='padding-right: 10px;'";
				if(isset($job_tracker_page) && $job_tracker_page == $page->ID) echo " SELECTED ";
				echo " value=\"".$page->ID."\">". $page->post_title . "</option>\n";
			}
			?></select></td>
	</tr>
	
	<tr>
	
		<th><a class="job_tracker_tooltip"
			title="<?php _e("If your website has an SSL certificate and you want to use it, the link to the jobs will be formatted for https.", JOB_TRACKER_TRANS_DOMAIN) ?>"><?php _e("Protocol to Use for Jobs URLs", JOB_TRACKER_TRANS_DOMAIN) ?></a>:</th>
		<td><select name="job_tracker_protocol">
			<option></option>
			<option style="padding-right: 10px;" value="https"
			<?php if(get_option('job_tracker_protocol') == 'https') echo 'selected="yes"';?>>https</option>
			<option style="padding-right: 10px;" value="http"
			<?php if(get_option('job_tracker_protocol') == 'http') echo 'selected="yes"';?>>http</option>
		</select></td>
	</tr>
	
	<tr>
		<th><a class="job_tracker_tooltip"
			title="<?php _e("If enforced, WordPress will automatically reload the jobs page into HTTPS mode even if the user attemps to open it in non-secure mode.", JOB_TRACKER_TRANS_DOMAIN) ?>"><?php _e("Enforce HTTPS", JOB_TRACKER_TRANS_DOMAIN) ?></a>:</th>
		<td><select name="job_tracker_force_https">
			<option></option>
			<option value="true" style="padding-right: 10px;"
			<?php if(get_option('job_tracker_force_https') == 'true') echo 'selected="yes"';?>><?php _e("Yes", JOB_TRACKER_TRANS_DOMAIN) ?></option>
			<option value="false" style="padding-right: 10px;"
			<?php if(get_option('job_tracker_force_https') == 'false') echo 'selected="yes"';?>><?php _e("No", JOB_TRACKER_TRANS_DOMAIN) ?></option>
		</select> <a href="http://mohanjith.com/ssl-certificates.html"
			class="job_tracker_click_me"><?php _e("Do you need an SSL Certificate?", JOB_TRACKER_TRANS_DOMAIN) ?></a>
		</td>
	</tr>
	
	<tr>
		<th><?php _e("User Level to manage job tracker", JOB_TRACKER_TRANS_DOMAIN) ?>:</th>
		<td><select name="job_tracker_user_level[]" id="job_tracker_user_level" size="3" multiple="multiple" >
		<?php
			foreach (get_editable_roles() as $role => $details) {
				$name = translate_user_role($details['name'] );
		?>
			<option value="<?php print $role; ?>" style="padding-right: 10px;"
			<?php if(in_array($role, get_option('job_tracker_user_level', array('administrator')))) echo 'selected="yes"';?>><?php _e($name, JOB_TRACKER_TRANS_DOMAIN) ?></option>
		<?php 
			}
		?>
		</select>
		</td>
	</tr>
	
	<tr>
		<td></td>
		<td><input type="submit"
			value="<?php _e('Update', JOB_TRACKER_TRANS_DOMAIN); ?>"
			class="button" /></td>
	</tr>
</table>
<input type="hidden" name="job_tracker_update_settings" value="1" />
</form>
</div>
	<?php 
	}
	
	public function job_overview() {
		global $wpdb;
		
		if (isset($_POST['multiple_jobs']) && is_array($_POST['multiple_jobs']) && isset($_POST['job_tracker_action'])) {
			switch ($_POST['job_tracker_action']) {
				case 'delete_job':
					job_tracker_delete($_POST['multiple_jobs']);
					break;
				case 'archive_job':
					job_tracker_archive($_POST['multiple_jobs']);
					break;
				case 'unarchive_job':
					job_tracker_unarchive($_POST['multiple_jobs']);
					break;
				case 'mark_as_accepted':
					job_tracker_change_status($_POST['multiple_jobs'], 'accepted');
					$_status = 'Accepted';
					break;
				case 'mark_as_working':
					job_tracker_change_status($_POST['multiple_jobs'], 'working');
					$_status = 'Working';
					break;
				case 'mark_as_completed':
					job_tracker_change_status($_POST['multiple_jobs'], 'completed');
					$_status = 'Completed';
					break;
				case 'mark_as_shipped':
					job_tracker_change_status($_POST['multiple_jobs'], 'shipped');
					$_status = 'Shipped';
					break;
			}
			
			foreach ($_POST['multiple_jobs'] as $job_id) {
				$lines_array = array();
				if (job_tracker_meta($job_id, 'itemized_list')) {
					$lines_array = unserialize(urldecode(job_tracker_meta($job_id, 'itemized_list')));
				}
				$_status_message = (isset($_POST['status_message']) && !empty($_POST['status_message']))?$_POST['status_message']:$_status;
				$lines_array[] = array('datetime' => date('Y-m-d H:i:s'), 'description' => $_status_message);
				
				$job_tracker_itemized_list = urlencode(serialize($lines_array));
				job_tracker_update_job_meta($job_id, 'itemized_list', $job_tracker_itemized_list);
				
				if (isset($_POST['tracking_number']) && !empty($_POST['tracking_number'])) {
					job_tracker_update_job_meta($job_id, 'tracking_number', $_POST['tracking_number']);
				}
				if (isset($_POST['carrier']) && !empty($_POST['carrier'])) {
					job_tracker_update_job_meta($job_id, 'carrier', $_POST['carrier']);
				}
			}
		}
		
		// The error takes precedence over others being that nothing can be done w/o tables
		if(!$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('jobs')."';") || !$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('log')."';")) { $warning_message = ""; }
	
		if(get_option("job_tracker_page") == '') { $warning_message .= __('Jobs page not selected. ', JOB_TRACKER_TRANS_DOMAIN); }
		if(get_option("job_tracker_page") == '') {
			$warning_message .= __("Visit ", JOB_TRACKER_TRANS_DOMAIN)."<a href='admin.php?page=job_tracker_settings'>settings page</a>".__(" to configure.", JOB_TRACKER_TRANS_DOMAIN);
		}
	
		if(!$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('meta')."';") || !$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('jobs')."';") || !$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('log')."';")) {
			$warning_message = __("The plugin database tables are gone, deactivate and reactivate plugin to re-create them.", JOB_TRACKER_TRANS_DOMAIN);
		}
	
		if($warning_message) echo "<div id=\"message\" class='error' ><p>$warning_message</p></div>";
		if($message) echo "<div id=\"message\" class='updated fade' ><p>$message</p></div>";
	
		$all_jobs = $wpdb->get_results("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id != ''");
		
		?>
		<div class="wrap">
		<form id="jobs-filter" action="" method="post">
<h2><?php _e('Job Overview', JOB_TRACKER_TRANS_DOMAIN); ?></h2>
<div class="tablenav clearfix">

<div class="alignleft"><select name="job_tracker_action" id="job_tracker_action">
	<option value="-1" selected="selected"><?php _e('-- Actions --', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="archive_job"><?php _e('Archive Job(s)', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="unarchive_job"><?php _e('Un-Archive Job(s)', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="mark_as_accepted"><?php _e('Mark as Accepted', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="mark_as_working"><?php _e('Mark as Working', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="mark_as_completed"><?php _e('Mark as Completed', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="mark_as_shipped"><?php _e('Mark as Shipped', JOB_TRACKER_TRANS_DOMAIN); ?></option>
	<option value="delete_job"><?php _e('Delete', JOB_TRACKER_TRANS_DOMAIN); ?></option>
</select> <input type="submit" value="Apply"
	class="button-secondary action" />
<input type="hidden" name="carrier" id="job_tracker_carrier" value="" />
<input type="hidden" name="tracking_number" id="job_tracker_tracking_number" value="" />
<input type="hidden" name="status_message" id="job_tracker_status_message" value="" /></div>

<div class="alignright">
<ul class="subsubsub" style="margin: 0;">
	<li><?php _e('Filter:', JOB_TRACKER_TRANS_DOMAIN); ?></li>
	<li><a href='#' class="" id=""><?php _e('All Jobs', JOB_TRACKER_TRANS_DOMAIN); ?></a>
	|</li>
	<li><a href='#' class="accepted" id=""><?php _e('Accepted', JOB_TRACKER_TRANS_DOMAIN); ?></a>
	|</li>
	<li><a href='#' class="working" id=""><?php _e('Working', JOB_TRACKER_TRANS_DOMAIN); ?></a>
	|</li>
	<li><a href='#' class="completed" id=""><?php _e('Completed', JOB_TRACKER_TRANS_DOMAIN); ?></a>
	|</li>
	<li><a href='#' class="shipped" id=""><?php _e('Shipped', JOB_TRACKER_TRANS_DOMAIN); ?></a>
	|</li>
	<li><?php _e('Custom: ', JOB_TRACKER_TRANS_DOMAIN); ?><input
		type="text" id="FilterTextBox" class="search-input"
		name="FilterTextBox" /></li>
</ul>
</div>
</div>
<br class="clear" />
		<?php $this->job_overview_table($all_jobs); ?>
	<?php if($wpdb->query("SELECT archived FROM `".Job_Tracker::tablename('jobs')."` WHERE archived = '1'")) { ?><a
	href="admin.php?page=job-tracker/job-tracker.php&archived=true" class="<?php print ($_REQUEST['archived'] == 'true')?'expanded':'collapsed';?>" id="job_tracker_show_archived" ><?php _e('Show / Hide Archived', JOB_TRACKER_TRANS_DOMAIN); ?></a><?php }?>
</form>
</div>
		<?php 
	}
	
	public function job_overview_user() {
		global $wpdb, $current_user;

		$all_jobs = $wpdb->get_results("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id != '' AND user_id = '{$current_user->ID}' ");
		
		?>
		<div class="wrap">
		<form id="jobs-filter" action="" method="post">
<h2><?php _e('Jobs', JOB_TRACKER_TRANS_DOMAIN); ?></h2>

		<?php $this->job_overview_table($all_jobs, 1); ?>
	<?php if($wpdb->query("SELECT archived FROM `".Job_Tracker::tablename('jobs')."` WHERE archived = '1'")) { ?><a
	href="admin.php?page=job-tracker/job-tracker.php&archived=true" class="<?php print ($_REQUEST['archived'] == 'true')?'expanded':'collapsed';?>" id="job_tracker_show_archived" ><?php _e('Show / Hide Archived', JOB_TRACKER_TRANS_DOMAIN); ?></a><?php }?>
</form>
</div>
		<?php 
	}
	
	public function job_overview_table($all_jobs, $type = 0) {
		global $wpdb;
	?>
		<table class="widefat" id="job_sorter_table">
			<thead>
				<tr>
					<th class="check-column"><input type="checkbox" id="CheckAll" /></th>
					<th class="job_id_col"><?php _e('Job Id', JOB_TRACKER_TRANS_DOMAIN); ?></th>
					<th><?php _e('Subject', JOB_TRACKER_TRANS_DOMAIN); ?></th>
					<th><?php _e('Status', JOB_TRACKER_TRANS_DOMAIN); ?></th>
					<?php if (!$user_table) { ?>
					<th><?php _e('User', JOB_TRACKER_TRANS_DOMAIN); ?></th>
					<?php } ?>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php
		
			$x_counter = 0;
			foreach ($all_jobs as $job) {
				// Stop if this is a recurring bill
				if(!job_tracker_meta($job->job_num,'job_tracker_recurring_billing')) {
					if ($_REQUEST['archived'] != 'true' && $job->archived == 1) continue;
					
					$x_counter++;
					unset($class_settings);
		
					//Basic Settings
					$job_id = $job->id;
					
					$subject = $job->subject;
					$job_link = job_tracker_build_link($job_id);
					$magic_link = preg_replace('/job_id/', 'generate_from', $job_link);
					$user_id = $job->user_id;
		
					//Determine if unique/custom id used
					$custom_id = job_tracker_meta($job_id,'job_tracker_custom_job_id');
					$display_id = empty($job->display_id)?$job_id:$job->display_id;
		
					// Determine What to Call Recipient
					$profileuser = get_userdata($user_id);
					$first_name = $profileuser->first_name;
					$last_name = $profileuser->last_name;
					$user_nicename = $profileuser->user_nicename;
					if(empty($first_name) || empty($last_name)) $call_me_this = $user_nicename; else $call_me_this = $first_name . " " . $last_name;
		
					// Color coding
					$class_settings .= " {$job->status} ";
					if($job->archived == '1')  $class_settings .= " job_tracker_archived ";
					
					$lines_array = array();
					if (job_tracker_meta($job_id, 'itemized_list')) {
						$lines_array = unserialize(urldecode(job_tracker_meta($job_id, 'itemized_list')));
					}
					
					$days_since = __(ucwords($job->status), JOB_TRACKER_TRANS_DOMAIN);
					
					if (count($lines_array) > 0 && !empty($lines_array[count($lines_array)]['description']) && strtolower($lines_array[count($lines_array)]['description']) != strtolower($job->status)) {
						$days_since .= ": {$lines_array[count($lines_array)]['description']}";
					}
		
					$output_row  = "<tr class='$class_settings'>\n";
					$output_row .= "	<th class='check-column'><input type='checkbox' name='multiple_jobs[]' value='$job_id'/></th>\n";
					$output_row .= "	<td><a href='admin.php?page=job_tracker_new_job&job_tracker_action=viewJob&job_id=$job_id'>$display_id</a></td>\n";
					$output_row .= "	<td><a href='admin.php?page=job_tracker_new_job&job_tracker_action=viewJob&job_id=$job_id'>$subject</a></td>\n";
					$output_row .= "	<td>$days_since</td>\n";
					if ($user_table == 0) {
						$output_row .= "	<td> <a href='user-edit.php?user_id=$user_id'>$call_me_this</a></td>\n";
					}
					if ($user_table < 2) {
						$output_row .= "	<td><a href='$job_link'>".__('View Job', JOB_TRACKER_TRANS_DOMAIN)."</a>";
					} 
					if ($user_table < 2) {
						if (job_tracker_meta($job_id, 'invoice_id')) {
							$invoice = new Web_Invoice_GetInfo(job_tracker_meta($job_id, 'invoice_id'));
													 
							$output_row .= "	| <a href='{$invoice->display('link')}'>".__('View Invoice', JOB_TRACKER_TRANS_DOMAIN)."</a>";
							$output_row .= "	| <a href='admin.php?page=new_web_invoice&web_invoice_action=doInvoice&invoice_id={$invoice->id}'>".__('Edit Invoice', JOB_TRACKER_TRANS_DOMAIN)."</a>";
						} else if ($job->status == 'completed' || $job->status == 'shipped') {
							$output_row .= "	| <a href='admin.php?page=job_tracker_new_job&job_tracker_action=create_invoice&job_id=$job_id'>".__('Create Invoice', JOB_TRACKER_TRANS_DOMAIN)."</a>";
							
							$_user_invoices = $wpdb->get_results("SELECT DISTINCT invoice_num FROM ".Web_Invoice::tablename('main')." b WHERE b.user_id = '{$user_id}'", ARRAY_N);
							$_paid_invoices = $wpdb->get_results("SELECT DISTINCT invoice_id FROM ".Web_Invoice::tablename('meta')." WHERE invoice_id IN (SELECT DISTINCT invoice_num FROM ".Web_Invoice::tablename('main')." b WHERE b.user_id = '{$user_id}') AND meta_key = 'paid_status' AND meta_value = 'paid'", ARRAY_N);
							
							$user_invoices = array();
							if (is_array($_user_invoices)) {
								foreach ($_user_invoices as $_invoice) {
									$user_invoices[] = $_invoice[0];
								}
							}
							
							$paid_invoices = array();
							if (is_array($_paid_invoices)) {
								foreach ($_paid_invoices as $_invoice) {
									$paid_invoices[] = $_invoice[0];
								}
							}
							
							$open_invoices = array_diff($user_invoices, $paid_invoices);
							$open_invoice = next($open_invoices);
							$output_row .= "	| <a href='admin.php?page=job_tracker_new_job&job_id=$job_id&invoice_id={$open_invoice}#misc-publishing-actions'>".__('Add to Invoice', JOB_TRACKER_TRANS_DOMAIN)."</a>";
						}
					}
					$output_row .= "	</td>\n";
					$output_row .= "</tr>";
		
					echo $output_row;
				} /* Recurring Billing Stop */
			}
			if($x_counter == 0) {
				// No result
				?>
				<tr>
					<td colspan="6" align="center">
					<div style="padding: 20px;"><?php _e('You have not created any jobs yet, ', JOB_TRACKER_TRANS_DOMAIN); ?><a
						href="job_tracker_new_job"><?php _e('create one now.', JOB_TRACKER_TRANS_DOMAIN); ?></a></div>
					</td>
				</tr>
				<?php
		
			}
			?>
			</tbody>
		</table>
		<?php 
	}
	
	public function new_job() {
		global $wpdb;
		
		if (isset($_REQUEST['job_id'])) {
			$job_id = intval($_REQUEST['job_id']);
		}
		
		if (isset($_REQUEST['job_tracker_action'])) {
			switch ($_REQUEST['job_tracker_action']) {
				case 'clear_log':
					$message = job_tracker_clear_job_status($job_id);
					break;
				case 'create_invoice':
					if (!isset($_REQUEST['invoice_id'])) {
						$error = true;
						$message = "There was a problem saving invoice. Try deactivating and reactivating plugin. REF: ".mysql_errno();
					}
					break;
			}
		}
		
		if (isset($_POST['job_id'])) {
			$message = job_tracker_save_job($job_id);
		}
		
		if (!empty($_REQUEST['user_id'])) $user_id = $_REQUEST['user_id'];

		if ($job_id == '') {unset($job_id);}
		
		$lines_array = array(array('datetime' => '-', 'description' => ""));
		$locked_down = false;
		
		// Job Exists, we are modifying it
		if(isset($job_id)) {			
			$job_info = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '".$job_id."'");
			$user_id = $job_info->user_id;
			$subject = $job_info->subject;
			$description = $job_info->description;
			$profileuser = get_userdata($job_info->user_id);
			$job_tracker_custom_job_id = $job_info->display_id;
			
			if (job_tracker_meta($job_id, 'itemized_list')) {
				$lines_array = unserialize(urldecode(job_tracker_meta($job_id, 'itemized_list')));
			}
			
			$locked_down = ($job_info->status == 'shipped' || $job_info->status == 'completed');
			
			$tracking_number = job_tracker_meta($job_id, 'tracking_number');
			$carrier = job_tracker_meta($job_id, 'carrier');
		}
	
		// Brand New Job
		if(!isset($job_id) && isset($_REQUEST['user_id'])) {
			$profileuser = get_user_to_edit($_REQUEST['user_id']);
		}
	
		// Load Userdata
		$user_email = $profileuser->user_email;
		$first_name = $profileuser->first_name;
		$last_name = $profileuser->last_name;
		$company_name = $profileuser->company_name;
		$tax_id = $profileuser->tax_id;
		$streetaddress = $profileuser->streetaddress;
		$city = $profileuser->city;
		$state = $profileuser->state;
		$zip = $profileuser->zip;
		$country = $profileuser->country;
	
		if(get_option("job_tracker_page") == '') { $warning_message .= __('Job page not selected. ', JOB_TRACKER_TRANS_DOMAIN); }
		if(get_option("job_tracker_page") == '') {
			$warning_message .= __("Visit ", JOB_TRACKER_TRANS_DOMAIN)."<a href='admin.php?page=job_tracker_settings'>settings page</a>".__(" to configure.", JOB_TRACKER_TRANS_DOMAIN);
		}
	
		if(!$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('meta')."';") || !$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('jobs')."';") || !$wpdb->query("SHOW TABLES LIKE '".Job_Tracker::tablename('log')."';")) {
			$warning_message = __("The plugin database tables are gone, deactivate and reactivate plugin to re-create them.", JOB_TRACKER_TRANS_DOMAIN);
		}
	
		if($warning_message) echo "<div id=\"message\" class='error' ><p>$warning_message</p></div>";
		if($message) echo "<div id=\"message\" class='updated fade' ><p>$message</p></div>";
	?>
<div class="wrap">
	<?php if(!isset($job_id)) { ?>
<h2><?php _e('New Job', JOB_TRACKER_TRANS_DOMAIN); ?></h2>
	<?php  job_tracker_draw_user_selection_form($user_id); 
		  } else {
			$_SESSION['last_new_job'] = false;
	      } ?>
	<?php if(isset($user_id) && isset($job_id)) { ?>
<h2><?php _e('Manage Job', JOB_TRACKER_TRANS_DOMAIN); ?></h2>
	<?php } ?>

	<?php if(isset($user_id)) { ?>
<div id="poststuff" class="metabox-holder">
<form id="new_job_tracker_form"
	action="admin.php?page=job_tracker_new_job&amp;job_tracker_action=save"
	method="POST"><input type="hidden" name="user_id"
	value="<?php echo $user_id; ?>" /> <input type="hidden"
	name="job_id"
	value="<?php if(isset($job_id)) { echo $job_id; } else { echo rand(10000000, 90000000);}  ?>" />
<div class="postbox" id="job_tracker_client_info_div">
<h3><label for="link_name"><?php _e('Client Information', JOB_TRACKER_TRANS_DOMAIN); ?></label></h3>
<div class="inside">
<table class="form-table" id="add_new_web_job">
	<tr>
		<th><?php _e("Email Address", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td><?php echo $user_email; ?> <a class="job_tracker_click_me"
			href="user-edit.php?user_id=<?php echo $user_id; ?>#billing_info"><?php _e('Go to User Profile', JOB_TRACKER_TRANS_DOMAIN); ?></a></td>

	</tr>
	<tr style="height: 90px;">
		<th><?php _e("Billing Information", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td>
		<div id="job_tracker_edit_user_from_job"><span
			class="job_tracker_make_editable<?php if(!$first_name) echo " job_tracker_unset"; ?>"
			id="job_tracker_first_name"><?php if($first_name) echo $first_name; else echo __("Set First Name", JOB_TRACKER_TRANS_DOMAIN); ?></span>
		<span
			class="job_tracker_make_editable<?php if(!$last_name) echo " job_tracker_unset"; ?>"
			id="job_tracker_last_name"><?php if($last_name) echo $last_name; else echo __("Set Last Name", JOB_TRACKER_TRANS_DOMAIN); ?></span><br />
		<span
			class="job_tracker_make_editable<?php if(!$company_name) echo " job_tracker_unset"; ?>"
			id="job_tracker_company_name"><?php if($company_name) echo $company_name; else echo __("Set Company", JOB_TRACKER_TRANS_DOMAIN); ?></span><br/>
		<span
			class="job_tracker_make_editable<?php if(!$streetaddress) echo " job_tracker_unset"; ?>"
			id="job_tracker_streetaddress"><?php if($streetaddress) echo $streetaddress; else echo __("Set Street Address", JOB_TRACKER_TRANS_DOMAIN); ?></span><br />
		<span
			class="job_tracker_make_editable<?php if(!$city) echo " job_tracker_unset"; ?>"
			id="job_tracker_city"><?php if($city) echo $city; else echo __("Set City", JOB_TRACKER_TRANS_DOMAIN); ?></span><br/>
		<span
			class="job_tracker_make_editable<?php if(!$state) echo " job_tracker_unset"; ?>"
			id="job_tracker_state"><?php if($state) echo $state; else echo __("Set State", JOB_TRACKER_TRANS_DOMAIN); ?></span>
		<span
			class="job_tracker_make_editable<?php if(!$zip) echo " job_tracker_unset"; ?>"
			id="job_tracker_zip"><?php if($zip) echo $zip; else echo __("Set Zip Code", JOB_TRACKER_TRANS_DOMAIN); ?></span><br/>
		<span
			class="job_tracker_make_editable<?php if(!$country) echo " job_tracker_unset"; ?>"
			id="job_tracker_country"><?php if($country) echo $country; else echo __("Set Country", JOB_TRACKER_TRANS_DOMAIN); ?></span><br/>
		</div>
		</td>
	</tr>
	<tr>
		<th><?php _e("Tax ID", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td>
		<div id="job_tracker_edit_tax_form_job">
			<span
				class="job_tracker_make_editable<?php if(!$tax_id) echo " job_tracker_unset"; ?>"
				id="job_tracker_tax_id"><?php if($tax_id) echo $tax_id; else echo __("Set Tax ID", JOB_TRACKER_TRANS_DOMAIN); ?></span>
		</div>
		</td>
	</tr>
</table>
</div>

<div id="job_tracker_main_info" class="metabox-holder">
<div id="submitdiv" class="postbox" style="">
<h3 class="hndle"><span><?php _e("Job Details", JOB_TRACKER_TRANS_DOMAIN) ?></span></h3>
<div class="inside">
<table class="form-table">
	<tr class="job_main">
		<th><?php _e("Subject", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td><input id="job_subject" class="subject" name='subject'
			value='<?php echo $subject; ?>' /></td>
	</tr>

	<tr class="job_main">
		<th><?php _e("Description / PO", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td><textarea class="job_description_box" name='description'><?php echo $description; ?></textarea></td>
	</tr>
	
	<?php if (is_array($lines_array) && count($lines_array) > 0) { ?>
	<tr class="job_main">
		<th><?php _e("Status info", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td>
		<table id="job_list" class="itemized_list">
			<tr>
				<th class="id"><?php _e("Id", JOB_TRACKER_TRANS_DOMAIN) ?></th>
				<th class="timestamp" style="width: 55px;"><?php _e("Date/time", JOB_TRACKER_TRANS_DOMAIN) ?></th>
				<th class="description" style="width: 80%;"><?php _e("Details", JOB_TRACKER_TRANS_DOMAIN) ?></th>
			</tr>

			<?php
			$counter = 1;
			foreach($lines_array as $line){	 ?>

			<tr valign="top">
				<td valign="top" class="id"><?php echo $counter; ?></td>
				<td valign="top" class="datetime"><?php echo $line[datetime]; ?>
				<input type="hidden" name="itemized_list[<?php echo $counter; ?>][datetime]" value="<?php echo $line[datetime]; ?>" /></td>
				<td valign="top" class="description"><textarea style="height: 25px; width: 100%;"
					name="itemized_list[<?php echo $counter; ?>][description]"
					class="item_description autogrow" <?php echo ($locked_down)?"readonly='readonly'":""; ?>><?php echo stripslashes($line[description]); ?></textarea></td>
			</tr>
			<?php $counter++; } ?>
		</table>
		</td>
	</tr>
	<?php } ?>
	
	<?php if (!$locked_down) { ?>
	<tr class="job_main">
		<th style='vertical-align: bottom; text-align: right;'>
		<p><a href="#" id="add_itemized_item"><?php _e("Add Another Line", JOB_TRACKER_TRANS_DOMAIN) ?></a><br />
		<span class='job_tracker_light_text'></span></p>
		</th>
		<td></td>
	</tr>
	<?php } else { ?>
	<tr class="job_main">
		<th><?php _e("Carrier", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td><input class="job_carrier_box" name='carrier' value="<?php echo $carrier; ?>" /></td>
	</tr>
	<tr class="job_main">
		<th><?php _e("Tracking number", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td><input class="job_tracking_number_box" name='tracking_number' value="<?php echo $tracking_number; ?>" /></td>
	</tr>
	<?php } ?>
</table>
</div>
</div>
</div>

<div id="submitdiv" class="postbox" style="">
<h3 class="hndle"><span><?php _e("Publish", JOB_TRACKER_TRANS_DOMAIN) ?></span></h3>
<div class="inside">
<div id="minor-publishing">

<div id="misc-publishing-actions">
<table class="form-table">
	<tr class="job_main">
		<th><?php _e("Job ID ", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td style="font-size: 1.1em; padding-top: 7px;"><input
			class="job_tracker_custom_job_id<?php if(empty($job_tracker_custom_job_id) || $job_id == $job_tracker_custom_job_id) { echo " job_tracker_hidden"; } ?>"
			name="job_tracker_custom_job_id"
			value="<?php echo $job_tracker_custom_job_id;?>" /> <?php if(isset($job_id)) { if (empty($job_tracker_custom_job_id) || $job_id == $job_tracker_custom_job_id) { echo $job_id; } } else { echo rand(10000000, 90000000);}  ?>
		<a
			class="job_tracker_custom_job_id job_tracker_click_me <?php if(!empty($job_tracker_custom_job_id) && $job_id != $job_tracker_custom_job_id) { echo " job_tracker_hidden"; } ?>"
			href="#"><?php _e("Custom Job ID", JOB_TRACKER_TRANS_DOMAIN) ?></a>

		</td>
	</tr>
<?php 
	if (!$lock_down) { 
		if (job_tracker_meta($job_id, 'invoice_id')) {
?>
	<tr class="job_main">
		<th><?php _e("Invoice ID ", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td style="font-size: 1.1em; padding-top: 7px;"><input
			class="job_tracker_invoice_id job_tracker_hidden"
			name="job_tracker_invoice_id"
			value="<?php echo job_tracker_meta($job_id, 'invoice_id'); ?>" />
		<?php echo job_tracker_meta($job_id, 'invoice_id'); ?>
		</td>
	</tr>
<?php
		} else {
	?>
	<tr class="job_main">
		<th><?php _e("Invoice ", JOB_TRACKER_TRANS_DOMAIN) ?></th>
		<td style="font-size: 1.1em; padding-top: 7px;">
		<?php 
		$_user_invoices = $wpdb->get_results("SELECT DISTINCT invoice_num FROM ".Web_Invoice::tablename('main')." b WHERE b.user_id = '{$user_id}'", ARRAY_N);
		$_paid_invoices = $wpdb->get_results("SELECT DISTINCT invoice_id FROM ".Web_Invoice::tablename('meta')." WHERE invoice_id IN (SELECT DISTINCT invoice_num FROM ".Web_Invoice::tablename('main')." b WHERE b.user_id = '{$user_id}') AND meta_key = 'paid_status' AND meta_value = 'paid'", ARRAY_N);
							
		$user_invoices = array();
		if (is_array($_user_invoices)) {
			foreach ($_user_invoices as $_invoice) {
				$user_invoices[] = $_invoice[0];
			}
		}
							
		$paid_invoices = array();
		if (is_array($_paid_invoices)) {
			foreach ($_paid_invoices as $_invoice) {
				$paid_invoices[] = $_invoice[0];
			}
		}					
		
		$open_invoices = array_diff($user_invoices, $paid_invoices);
		$open_invoice = next($open_invoices);
		
		$all_invoices = $wpdb->get_results("SELECT * FROM ".Web_Invoice::tablename('main')." WHERE user_id = {$user_id}"); 
?>
		<select name="job_tracker_invoice_id" id="job_tracker_invoice_id">
			<option selected value=""></option>
			<?php 	foreach ($all_invoices as $invoice) {
				$profileuser = get_user_to_edit($invoice->user_id);
				?>
			<option <?php print ($open_invoice == $invoice->invoice_num)?'selected="selected"':''; ?> value="<?php echo $invoice->invoice_num; ?>"><?php if(web_invoice_recurring($invoice->invoice_num)) { _e("(recurring)", WEB_INVOICE_TRANS_DOMAIN); } ?>
			<?php echo $invoice->subject . " - $" .$invoice->amount; ?></option>

			<?php } ?>
		</select>
		<a href="admin.php?page=job_tracker_new_job&job_tracker_action=create_invoice&job_id=<?php print $job_id; ?>&invoice_id=<?php print $open_invoice; ?>"
			class='button' id="job_tracker_invoice_id_link"
			><?php _e("Add to Invoice", JOB_TRACKER_TRANS_DOMAIN); ?></a>
		</td>
	</tr>
<?php 	
		}
	} ?>
</table>
<script type="text/javascript">
	_job_tracker_add_to_invoice_url = 'admin.php?page=job_tracker_new_job&job_tracker_action=create_invoice&job_id=<?php print $job_id; ?>&invoice_id=';
</script>
</div>
<div class="clear"></div>
</div>

<div id="major-publishing-actions">

<div id="publishing-action"><input type="submit" name="save"
	class="button-primary" value="Save" />
<?php 
	if (!$lock_down) { 
		if (job_tracker_meta($job_id, 'invoice_id')) {
?>
	<a class="button" href="admin.php?page=new_web_invoice&web_invoice_action=doInvoice&invoice_id=<?php print job_tracker_meta($job_id, 'invoice_id'); ?>"><?php _e('View Invoice', JOB_TRACKER_TRANS_DOMAIN); ?></a>
<?php 
		} else {
	?>
	<a class="button" href="admin.php?page=job_tracker_new_job&job_tracker_action=create_invoice&job_id=<?php print $job_id; ?>"><?php _e('Create Invoice', JOB_TRACKER_TRANS_DOMAIN); ?></a>
<?php 	
		}
	} ?>
	<a class="button" href="admin.php?page=job-tracker/job-tracker.php"><?php _e('Cancel', JOB_TRACKER_TRANS_DOMAIN); ?></a></div>
<div class="clear"></div>
</div>

</div>
</div>

</div>
</form>
</div>
		<?php if(job_tracker_get_job_status($job_id,'100')) { ?>
<div class="job_tracker_status updated">
<h2><?php _e("This Job's History ", JOB_TRACKER_TRANS_DOMAIN) ?>(<a
	href="admin.php?page=job_tracker_new_job&job_id=<?php echo $job_id; ?>&job_tracker_action=clear_log"><?php _e("Clear Log", JOB_TRACKER_TRANS_DOMAIN) ?></a>)</h2>
<ul id="job_history_log">
<?php echo job_tracker_get_job_status($job_id,'100'); ?>
</ul>
</div>
<?php } else { ?> <?php }?> <br class="cb" />
		<?php 
	  }
	  ?></div>
	  <?php 
	}
	
	public function job_list() {
		global $wpdb, $current_user;

		$all_jobs = $wpdb->get_results("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id != '' AND (status NOT IN ('completed','shipped') OR created > NOW( ) -  INTERVAL 2 week) AND archived = '0'");
		
		?>
		<div id="job_page" class="clearfix">		
			<table id="job_tracker_itemized_table" class="itemized_list">
				<thead>
					<tr>
						<th class="job_id_col"><?php _e('Job Id', JOB_TRACKER_TRANS_DOMAIN); ?></th>
						<th><?php _e('Subject', JOB_TRACKER_TRANS_DOMAIN); ?></th>
						<th><?php _e('Status', JOB_TRACKER_TRANS_DOMAIN); ?></th>
					</tr>
				</thead>
	
				<tbody>
				<?php
			
				$x_counter = 0;
				foreach ($all_jobs as $job) {
					// Stop if this is a recurring bill
					if(!job_tracker_meta($job->job_num,'job_tracker_recurring_billing')) {
						if ($_REQUEST['archived'] != 'true' && $job->archived == 1) continue;
						
						$x_counter++;
						unset($class_settings);
			
						//Basic Settings
						$job_id = $job->id;
						
						$subject = $job->subject;
						$job_link = job_tracker_build_link($job_id);
						$magic_link = preg_replace('/job_id/', 'generate_from', $job_link);
						$user_id = $job->user_id;
			
						//Determine if unique/custom id used
						$custom_id = job_tracker_meta($job_id,'job_tracker_custom_job_id');
						$display_id = empty($job->display_id)?$job_id:$job->display_id;
			
						// Determine What to Call Recipient
						$profileuser = get_userdata($user_id);
						$first_name = $profileuser->first_name;
						$last_name = $profileuser->last_name;
						$user_nicename = $profileuser->user_nicename;
						if(empty($first_name) || empty($last_name)) $call_me_this = $user_nicename; else $call_me_this = $first_name . " " . $last_name;
			
						// Color coding
						$class_settings .= " {$job->status} ";
						if($job->archived == '1')  $class_settings .= " job_tracker_archived ";
						
						$lines_array = array();
						if (job_tracker_meta($job_id, 'itemized_list')) {
							$lines_array = unserialize(urldecode(job_tracker_meta($job_id, 'itemized_list')));
						}
						
						$days_since = __(ucwords($job->status), JOB_TRACKER_TRANS_DOMAIN);
						
						if (count($lines_array) > 0 && !empty($lines_array[count($lines_array)]['description'])) {
							$days_since .= ": {$lines_array[count($lines_array)]['description']}";
						}
			
						$output_row  = "<tr class='$class_settings'>\n";
						$output_row .= "	<td>$display_id</td>\n";
						$output_row .= "	<td>$subject</td>\n";
						$output_row .= "	<td>$days_since</td>\n";
						$output_row .= "</tr>";
			
						echo $output_row;
					} /* Recurring Billing Stop */
				}
				if($x_counter == 0) {
					// No result
					?>
					<tr>
						<td colspan="6" align="center">
						<div style="padding: 20px;"><?php _e('You have not created any jobs yet, ', JOB_TRACKER_TRANS_DOMAIN); ?><a
							href="job_tracker_new_job"><?php _e('create one now.', JOB_TRACKER_TRANS_DOMAIN); ?></a></div>
						</td>
					</tr>
					<?php
			
				}
				?>
				</tbody>
			</table>
		</div>
		<div class="clear"></div>
		<?php 
		return "";
	}
	
	public function the_content($content) {
		global $post;
		$ip=$_SERVER['REMOTE_ADDR'];
	
		// check if web_invoice_web_invoice_page is set, and that this it matches the current page, and the invoice_id is valid
		if(get_option('job_tracker_page') != '' && is_page(get_option('job_tracker_page'))) {
			if(!($job_id = job_tracker_md5_to_job($_GET['job_id']))) return $this->job_list();
			
			$job_info = new Job_Tracker_GetInfo($job_id);
			
			$lines_array = array();
			if (job_tracker_meta($job_id, 'itemized_list')) {
				$lines_array = unserialize(urldecode(job_tracker_meta($job_id, 'itemized_list')));
			}
		?>
		<div id="job_page" class="clearfix">
			<div class="clearfix">
				<h2 id="job_tracker_welcome_message" class="job_page_subheading"><?php printf(__('Welcome, %s', JOB_TRACKER_TRANS_DOMAIN), $job_info->recipient('callsign')); ?>!</h2>
			</div>
			<p class="job_tracker_main_description"><?php printf(__('Job Id: <b>%1$s</b>', JOB_TRACKER_TRANS_DOMAIN), $job_info->display('display_id')); ?>.</p>
			<h3><?php echo stripcslashes($job_info->display('subject'));  ?></h3>
			<p><?php echo stripcslashes($job_info->display('description'));  ?></p>
			<h3>Status Information</h3>
			<table id="job_tracker_itemized_table" class="itemized_list">
				<tr>
					<th class="id" style="width: 40px;"><?php _e("Id", JOB_TRACKER_TRANS_DOMAIN) ?></th>
					<th class="timestamp" style="width: 180px;"><?php _e("Date/time", JOB_TRACKER_TRANS_DOMAIN) ?></th>
					<th class="description" ><?php _e("Details", JOB_TRACKER_TRANS_DOMAIN) ?></th>
				</tr>
	
				<?php
				$counter = 1;
				foreach($lines_array as $line){	 
					if (empty($line[description])) continue;
					if($i % 2) { print "<tr class='alt_row' valign='top'>"; } 
					else { print "<tr class='alt_row' valign='top'>"; } 
				?>
					<td valign="top" class="id"><?php echo $counter; ?></td>
					<td valign="top" class="datetime"><?php echo $line[datetime]; ?></td>
					<td valign="top" class="description"><?php echo stripslashes($line[description]); ?></td>
				</tr>
				<?php $counter++; } ?>
			</table>
			<?php 
			if (job_tracker_meta($job_id, 'carrier') || job_tracker_meta($job_id, 'tracking_number') || job_tracker_meta($job_id, 'invoice_id')) {
			?>
			<h3><?php _e('Package information', JOB_TRACKER_TRANS_DOMAIN); ?></h3>
			<table >
			<?php 
			if (job_tracker_meta($job_id, 'carrier')) {
			?>
				<tr>
					<th style="text-align: left;"><?php _e('Carrier', JOB_TRACKER_TRANS_DOMAIN); ?>:</th>
					<td><?php print job_tracker_meta($job_id, 'carrier'); ?></td>
				</tr>
			<?php
			}
			if (job_tracker_meta($job_id, 'tracking_number')) {
			?>
				<tr>
					<th style="text-align: left;"><?php _e('Tracking number', JOB_TRACKER_TRANS_DOMAIN) ?>:</th>
					<td><?php print job_tracker_meta($job_id, 'tracking_number'); ?></td>
				</tr>
			<?php
			} 
			if (job_tracker_meta($job_id, 'invoice_id')) {
				$invoice = new Web_Invoice_GetInfo(job_tracker_meta($job_id, 'invoice_id'));
?>
			<tr>
				<td colspan="2"><a href="<?php print $invoice->display('link'); ?>"><?php _e('View Invoice', JOB_TRACKER_TRANS_DOMAIN); ?></a></td>
			</tr>
<?php 
			}
			}
?>
			
			</table>
		</div>
		<div class="clear"></div>
		<?php 
		} else {
			return $content;
		}
	}
}

class Job_Tracker_GetInfo {
	var $id;
	var $_row_cache;

	function __construct($job_id) {
		global $_job_tracker_getinfo, $_job_tracker_clear_cache, $wpdb;

		$this->id = $job_id;

		if (isset($_job_tracker_getinfo[$this->id]) && $_job_tracker_getinfo[$this->id]) {
			$this->_row_cache = $_job_tracker_getinfo[$this->id];
		}

		if (!$this->_row_cache || $_job_tracker_clear_cache) {
			$this->_setRowCache($wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '{$job_id}'"));
			$_job_tracker_clear_cache = false;
		}

		if (!$this->_row_cache) {
			$this->_setRowCache($wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '{$job_id}'"));
		}
	}

	function _setRowCache($row) {
		global $_job_tracker_getinfo;

		if (!$row) {
			$this->id = null;
			return;
		}

		$this->_row_cache = $row;
		$_job_tracker_getinfo[$this->id] = $this->_row_cache;
	}

	function recipient($what) {
		global $_job_tracker_clear_cache, $wpdb;

		if (!$this->_row_cache || $_job_tracker_clear_cache) {
			$this->_setRowCache($wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE job_num = '{$this->id}'"));
			$_job_tracker_clear_cache = false;
		}

		if ($this->_row_cache) {
			$uid = $this->_row_cache->user_id;
			$user_email = $wpdb->get_var("SELECT user_email FROM ". $wpdb->prefix . "users WHERE id=".$uid);
		} else {
			$uid = false;
			$user_email = false;
		}

		$job_info = $this->_row_cache;

		switch ($what) {
			case 'callsign':
				$first_name = get_usermeta($uid,'first_name');
				$last_name = get_usermeta($uid,'last_name');
				if(empty($first_name) || empty($last_name)) return $user_email; else return $first_name . " " . $last_name;
				break;

			case 'user_id':
				return $uid;
				break;

			case 'email_address':
				return $user_email;
				break;

			case 'first_name':
				return get_usermeta($uid,'first_name');
				break;

			case 'last_name':
				return get_usermeta($uid,'last_name');
				break;

			case 'log_status':
				if($status_update = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('log')." WHERE job_id = ".$this->id ." ORDER BY `".Job_Tracker::tablename('log')."`.`time_stamp` DESC LIMIT 0 , 1"))
				return $status_update->value . " - " . job_tracker_Date::convert($status_update->time_stamp, 'Y-m-d H', __('M d Y'));
				break;

			case 'paid_date':
				$paid_date = $wpdb->get_var("SELECT time_stamp FROM  ".Job_Tracker::tablename('log')." WHERE action_type = 'paid' AND job_id = '".$this->id."' ORDER BY time_stamp DESC LIMIT 0, 1");
				if($paid_date) return web_inv;
				break;

			case 'streetaddress':
				return get_usermeta($uid,'streetaddress');
				break;

			case 'state':
				return strtoupper(get_usermeta($uid,'state'));
				break;

			case 'city':
				return get_usermeta($uid,'city');
				break;

			case 'zip':
				return get_usermeta($uid,'zip');
				break;

			case 'country':
				if(get_usermeta($uid,'country')) return get_usermeta($uid,'country');  else  return "US";
				break;
			
			case 'company_name':
				if(get_usermeta($uid,'company_name')) return get_usermeta($uid,'company_name');  else  return "";
				break;
			
			case 'tax_id':
				if(get_usermeta($uid,'tax_id')) return get_usermeta($uid,'tax_id');  else  return "";
				break;
		}

	}
	
	public function display($what) {
		global $_job_tracker_clear_cache, $wpdb;

		if (!$this->_row_cache || $_job_tracker_clear_cache) {
			$this->_setRowCache($wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE job_num = '{$this->id}'"));
			$_job_tracker_clear_cache = false;
		}

		$job_info = $this->_row_cache;

		switch ($what) {
			case 'log_status':
				if($status_update = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('log')." WHERE job_id = ".$this->id ." ORDER BY `".Job_Tracker::tablename('log')."`.`time_stamp` DESC LIMIT 0 , 1"))
				return $status_update->value . " - " . job_tracker_Date::convert($status_update->time_stamp, 'Y-m-d H', __('M d Y'));
				break;

			case 'archive_status':
				$result = $wpdb->get_col("SELECT action_type FROM  ".Job_Tracker::tablename('log')." WHERE job_id = '".$this->id."' ORDER BY time_stamp DESC");
				foreach($result as $event){
					if ($event == 'unarchive') { return ''; break; }
					if ($event == 'archive') { return 'archive'; break; }
				}
				break;

			case 'link':
				$link_to_page = get_permalink(get_option('job_tracker_page'));
				$hashed = md5($this->id);
				if(get_option("permalink_structure")) { return $link_to_page . "?job_id=" .$hashed; }
				else { return  $link_to_page . "&job_id=" . $hashed; }
				break;
				
			case 'job_hash':
				return md5($this->id);
				break;

			case 'hash':
				return md5($this->id);
				break;

			case 'display_id':
				$job_tracker_custom_job_id = job_tracker_meta($this->id,'job_tracker_custom_job_id');
				if(empty($job_tracker_custom_job_id)) { return $this->id; }	else { return $job_tracker_custom_job_id; }
				break;

			case 'subject':
				return $job_info->subject;
				break;

			case 'description':
				return  str_replace("\n", "<br />", $job_info->description);
				break;

			case 'status':
				return $job_info->status;
				break;
		}
	}

}

function job_tracker_save_job($job_id) {

	global $wpdb;
	
	if ($unprivileged) {
		$profileuser = get_currentuserinfo();
	} else {
		$profileuser = get_userdata($_POST['user_id']);
	}
	
	$description = $_REQUEST['description'];
	$subject = $_REQUEST['subject'];
	$amount = $_REQUEST['amount'];
	$user_id = $_REQUEST['user_id'];
	$job_tracker_custom_job_id = $_REQUEST['job_tracker_custom_job_id'];
	
	if (empty($job_tracker_custom_job_id)) {
		$job_tracker_custom_job_id = $job_id;
	}

	$job_tracker_first_name = $_REQUEST['job_tracker_first_name'];
	$job_tracker_last_name = $_REQUEST['job_tracker_last_name'];
	$job_tracker_tax_id = $_REQUEST['job_tracker_tax_id'];
	$job_tracker_company_name = $_REQUEST['job_tracker_company_name'];
	$job_tracker_streetaddress = $_REQUEST['job_tracker_streetaddress'];
	$job_tracker_city = $_REQUEST['job_tracker_city'];
	$job_tracker_state = $_REQUEST['job_tracker_state'];
	$job_tracker_zip = $_REQUEST['job_tracker_zip'];
	$job_tracker_country = $_REQUEST['job_tracker_country'];
	$job_tracker_carrier = $_REQUEST['carrier'];
	$job_tracker_tracking_number = $_REQUEST['tracking_number'];
	
	for ($i=1; $i<=count($_REQUEST['itemized_list']); $i++) {
		if (strtotime($_REQUEST['itemized_list'][$i]['datetime']) == 0) {
			$_REQUEST['itemized_list'][$i]['datetime'] = date('Y-m-d H:i:s');
		}
	}
	
	$job_tracker_itemized_list = urlencode(serialize($_REQUEST['itemized_list']));
	
	// Check if this is new job creation, or an update
	if(job_tracker_does_job_exist($job_id)) {
		// Updating Old Job

		if(job_tracker_get_job_attrib($job_id,'subject') != $subject) { 
			$wpdb->query("UPDATE ".Job_Tracker::tablename('jobs')." SET subject = '$subject' WHERE id = $job_id"); 
			job_tracker_update_log($job_id, 'updated', ' Subject Updated '); 
			$message .= "Subject updated. ";
			job_tracker_clear_cache();
		}
		if(job_tracker_get_job_attrib($job_id,'description') != $description) { 
			$wpdb->query("UPDATE ".Job_Tracker::tablename('jobs')." SET description = '$description' WHERE id = $job_id"); 
			job_tracker_update_log($job_id, 'updated', ' Description Updated '); 
			$message .= "Description updated. ";
			job_tracker_clear_cache();
		}
		if(job_tracker_get_job_attrib($job_id,'display_id') != $job_tracker_custom_job_id) { 
			$wpdb->query("UPDATE ".Job_Tracker::tablename('jobs')." SET display_id = '$job_tracker_custom_job_id' WHERE id = $job_id"); 
			job_tracker_update_log($job_id, 'updated', ' Custom Job Id Updated '); 
			$message .= "Custom Job Id updated. ";
			job_tracker_clear_cache();
		}
	}
	else {
		// Create New Job

		if($wpdb->query("INSERT INTO ".Job_Tracker::tablename('jobs')." (id,description,display_id,user_id,subject,status,archived)	VALUES ('$job_id','$description','$job_tracker_custom_job_id','$user_id','$subject','created','0')")) {
			$message = "New Job saved.";
			job_tracker_update_log($job_id, 'created', ' Created ');;
		}
		else {
			$error = true; $message = "There was a problem saving job. Try deactivating and reactivating plugin. REF: ".mysql_error();
		}
	}
	
	if(!empty($job_tracker_itemized_list)) job_tracker_update_job_meta($job_id, 'itemized_list', $job_tracker_itemized_list);
	if(!empty($job_tracker_carrier)) job_tracker_update_job_meta($job_id, 'carrier', $job_tracker_carrier);
	if(!empty($job_tracker_tracking_number)) job_tracker_update_job_meta($job_id, 'tracking_number', $job_tracker_tracking_number);
	
	//Update User Information
	if(!empty($job_tracker_first_name)) update_usermeta($user_id, 'first_name', $job_tracker_first_name);
	if(!empty($job_tracker_last_name)) update_usermeta($user_id, 'last_name', $job_tracker_last_name);
	if(!empty($job_tracker_company_name)) update_usermeta($user_id, 'company_name', $job_tracker_company_name);
	if(!empty($job_tracker_tax_id)) update_usermeta($user_id, 'tax_id', $job_tracker_tax_id);
	if(!empty($job_tracker_streetaddress)) update_usermeta($user_id, 'streetaddress', $job_tracker_streetaddress);
	if(!empty($job_tracker_city)) update_usermeta($user_id, 'city', $job_tracker_city);
	if(!empty($job_tracker_state)) update_usermeta($user_id, 'state', $job_tracker_state);
	if(!empty($job_tracker_zip)) update_usermeta($user_id, 'zip', $job_tracker_zip);
	if(!empty($job_tracker_country)) update_usermeta($user_id, 'country', $job_tracker_country);

	//If there is a message, append it with the web job link
	if($message && $job_id) {
		$job_info = new Job_Tracker_GetInfo($job_id);
		$message .= " <a href='".$job_info->display('link')."'>View Job</a>.";
	}

	if(!$error) return $message;
	if($error) return "An error occured: $message.";
}

function job_tracker_meta($job_id,$meta_key)
{
	global $wpdb;
	global $_job_tracker_meta_cache;

	if (!isset($_job_tracker_meta_cache[$job_id][$meta_key]) || !$_job_tracker_meta_cache[$job_id][$meta_key]) {
		$_job_tracker_meta_cache[$job_id][$meta_key] = $wpdb->get_var("SELECT meta_value FROM `".Job_Tracker::tablename('meta')."` WHERE meta_key = '$meta_key' AND job_id = '$job_id'");
	}

	return $_job_tracker_meta_cache[$job_id][$meta_key];
}

function job_tracker_update_job_meta($job_id,$meta_key,$meta_value)
{
	global $wpdb;
	global $_job_tracker_meta_cache;
	
	if(empty($meta_value)) {
		// Delete meta_key if no value is set
		$wpdb->query("DELETE FROM ".Job_Tracker::tablename('meta')." WHERE  job_id = '$job_id' AND meta_key = '$meta_key'");
	}
	else
	{
		// Check if meta key already exists, then we replace it Job_Tracker::tablename('meta')
		if($wpdb->get_var("SELECT meta_key 	FROM `".Job_Tracker::tablename('meta')."` WHERE meta_key = '$meta_key' AND job_id = '$job_id'"))
		{ $wpdb->query("UPDATE `".Job_Tracker::tablename('meta')."` SET meta_value = '$meta_value' WHERE meta_key = '$meta_key' AND job_id = '$job_id'"); }
		else
		{ $wpdb->query("INSERT INTO `".Job_Tracker::tablename('meta')."` (job_id, meta_key, meta_value) VALUES ('$job_id','$meta_key','$meta_value')"); }
	}

	if (isset($_job_tracker_meta_cache[$job_id][$meta_key])) {
		$_job_tracker_meta_cache[$job_id][$meta_key] = $meta_value;
	}
}

function job_tracker_delete_job_meta($job_id,$meta_key='')
{
	global $wpdb;
	global $_job_tracker_meta_cache;

	
	if(empty($meta_key))
	{ $wpdb->query("DELETE FROM `".Job_Tracker::tablename('meta')."` WHERE job_id = '$job_id' ");}
	else
	{ $wpdb->query("DELETE FROM `".Job_Tracker::tablename('meta')."` WHERE job_id = '$job_id' AND meta_key = '$meta_key'");}
	
	if (isset($_job_tracker_meta_cache[$job_id][$meta_key])) {
		$_job_tracker_meta_cache[$job_id][$meta_key] = false;
	}
}

function job_tracker_build_link($job_id) {
	// in job class
	global $wpdb;

	$link_to_page = get_permalink(get_option('job_tracker_page'));


	$hashed_job_id = md5($job_id);
	if(get_option("permalink_structure")) { $link = $link_to_page . "?job_id=" .$hashed_job_id; }
	else { $link =  $link_to_page . "&job_id=" . $hashed_job_id; }

	return $link;
}

function job_tracker_draw_user_selection_form($user_id) {
	global $wpdb;
	?>

<div class="postbox" id="wp_new_job_tracker_div">
<div class="inside">
<form action="admin.php?page=job_tracker_new_job" method='POST'>
<table class="form-table" id="get_user_info">
	<tr class="job_main">
		<th><?php if(isset($user_id)) { _e("Start New Job For: ", JOB_TRACKER_TRANS_DOMAIN); } else { _e("Create New Job For: ", JOB_TRACKER_TRANS_DOMAIN); } ?></th>
		<td><select name='user_id' class='user_selection'>
			<option></option>
			<?php
			$get_all_users = $wpdb->get_results("SELECT * FROM ". $wpdb->prefix . "users LEFT JOIN ". $wpdb->prefix . "usermeta on ". $wpdb->prefix . "users.id=". $wpdb->prefix . "usermeta.user_id and ". $wpdb->prefix . "usermeta.meta_key='last_name' ORDER BY ". $wpdb->prefix . "usermeta.meta_value");
			foreach ($get_all_users as $user)
			{
				$profileuser = get_user_to_edit($user->ID);
				echo "<option ";
				if(isset($user_id) && $user_id == $user->ID) echo " SELECTED ";
				if(!empty($profileuser->last_name) && !empty($profileuser->first_name)) { echo " value=\"".$user->ID."\">". $profileuser->last_name. ", " . $profileuser->first_name . " (".$profileuser->user_email.")</option>\n";  }
				else
				{
					echo " value=\"".$user->ID."\">". $profileuser->user_login. " (".$profileuser->user_email.")</option>\n";
				}
			}
			?>
		</select> <input type='submit' class='button'
			id="job_tracker_create_new_job"
			value='<?php _e("Create New Job", JOB_TRACKER_TRANS_DOMAIN); ?>' />


			<?php 
		if(!isset($user_id)) { _e("User must have a profile to receive jobs.", JOB_TRACKER_TRANS_DOMAIN);

		if(current_user_can('create_users')) { if($GLOBALS['wp_version'] < '2.7') { echo "<a href=\"users.php\">".__("Create a new user account.", JOB_TRACKER_TRANS_DOMAIN)."</a>";  }
		else {
			echo "<a href=\"user-new.php\">".__("Create a new user account.", JOB_TRACKER_TRANS_DOMAIN)."</a>";
		} } }	 ?></td>
	</tr>

</table>
</form>
</div>
</div>
		<?php
}

function job_tracker_get_job_status($job_id,$count='1')
{
	if($job_id != '') {
		global $wpdb;
		$query = "SELECT * FROM ".Job_Tracker::tablename('log')."
	WHERE job_id = $job_id
	ORDER BY time_stamp DESC
	LIMIT 0 , $count";

		$status_update = $wpdb->get_results($query);

		foreach ($status_update as $single_status)
		{
			$message .= "<li>" . $single_status->value . " on <span class='job_tracker_tamp_stamp'>" . date(__('Y-m-d H:i:s'), strtotime($single_status->time_stamp)) . "</span></li>";
		}

		return $message;
	}
}

function job_tracker_does_job_exist($job_id) {
	global $wpdb;
	$job = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '{$job_id}'");
	return $job->id;
}

function job_tracker_update_log($job_id,$action_type,$value)
{
	global $wpdb;
	if(isset($job_id))
	{
		$time_stamp = date("Y-m-d h-i-s");
		$wpdb->query("INSERT INTO ".Job_Tracker::tablename('log')."
	(job_id , action_type , value, time_stamp)
	VALUES ('$job_id', '$action_type', '$value', '$time_stamp');");
	}
}

function job_tracker_query_log($job_id,$action_type) {
	global $wpdb;
	if($results = $wpdb->get_results("SELECT * FROM ".Job_Tracker::tablename('log')." WHERE job_id = '$job_id' AND action_type = '$action_type' ORDER BY 'time_stamp' DESC")) return $results;
}

function job_tracker_get_job_attrib($job_id,$attribute)
{
	global $wpdb;
	$query = "SELECT $attribute FROM ".Job_Tracker::tablename('jobs')." WHERE id = ".$job_id."";
	return $wpdb->get_var($query);
}

function job_tracker_clear_cache() {
	global $_job_tracker_clear_cache;
	
	$_job_tracker_clear_cache = true;
}

function job_tracker_get_job($job_id) {
	global $wpdb;
	$job = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '{$job_id}'");
	return $job;
}

function job_tracker_delete($job_id) {
	global $wpdb;

	// Check to see if array is passed or single.
	if(is_array($job_id))
	{
		$counter=0;
		foreach ($job_id as $single_job_id) {
			$counter++;
			
			$wpdb->query("DELETE FROM ".Job_Tracker::tablename('jobs')." WHERE id = '$single_job_id'");

			do_action('job_tracker_delete', $single_job_id);
			job_tracker_update_log($single_job_id, "deleted", "Deleted on ");

			// Get all meta keys for this job, then delete them

			$all_job_meta_values = $wpdb->get_col("SELECT job_id FROM ".Job_Tracker::tablename('meta')." WHERE job_id = '$single_job_id'");

			foreach ($all_job_meta_values as $meta_key) {
				job_tracker_delete_job_meta($single_job_id);
			}
		}
		return $counter . " job(s) successfully deleted.";
	} else {
			
		// Delete Single
		$wpdb->query("DELETE FROM ".Job_Tracker::tablename('jobs')." WHERE id = '$job_id'");
		// Make log entry
		
		do_action('job_tracker_delete', $job_id);
		job_tracker_update_log($job_id, "deleted", "Deleted on ");
		
		$all_job_meta_values = $wpdb->get_col("SELECT job_id FROM ".Job_Tracker::tablename('meta')." WHERE job_id = '$single_job_id'");

		foreach ($all_job_meta_values as $meta_key) {
			job_tracker_delete_job_meta($single_job_id);
		}
		
		return "Job successfully deleted.";
	}
}

function job_tracker_archive($job_id) {
	global $wpdb;

	// Check to see if array is passed or single.
	if(is_array($job_id))
	{
		$counter=0;
		foreach ($job_id as $single_job_id) {
			$counter++;
			job_tracker_update_job_attribute($single_job_id, "archived", "1", true);
		}
		return $counter . " job(s) archived.";

	}
	else
	{
		job_tracker_update_job_attribute($job_id, "archived", "1");
		return "Job successfully archived.";
	}
}

function job_tracker_unarchive($job_id) {
	global $wpdb;

	// Check to see if array is passed or single.
	if(is_array($job_id))
	{
		$counter=0;
		foreach ($job_id as $single_job_id) {
			$counter++;
			job_tracker_update_job_attribute($single_job_id, "archived", "0", true);
		}
		return $counter . " job(s) un-archived.";

	}
	else
	{
		job_tracker_update_job_attribute($job_id, "archived", "0");
		return "Job successfully un-archived.";
	}
}

function job_tracker_change_status($job_id, $status) {
	global $wpdb;
	
	$valid_statuses = array('created','accepted','working','completed','shipped',);

	if (!in_array($status, $valid_statuses)) return;
	
	$counter=0;
	// Check to see if array is passed or single.
	if(is_array($job_id))
	{
		foreach ($job_id as $single_job_id) {
			$counter++;
			job_tracker_update_job_attribute($single_job_id, "status", "$status", true);
			job_tracker_update_log($single_job_id,'status',"Job marked as {$status}");
		}

		return $counter . " job(s) marked as {$status}.";
	}
	else
	{
		$counter++;
		job_tracker_update_job_attribute($single_job_id, "status", "$status", true);
		job_tracker_update_log($job_id,'paid',"Job marked as {$status}");
		
		return $counter . " job marked as {$status}.";
	}
}

function job_tracker_update_job_attribute($job_id, $attribute, $value, $silent = false) {
	global $wpdb;
	
	if(job_tracker_get_job_attrib($job_id,$attribute) != $value) { 
		$wpdb->query("UPDATE ".Job_Tracker::tablename('jobs')." SET `{$attribute}` = '$value' WHERE id = $job_id"); 
		if (!$silent) job_tracker_update_log($job_id, 'updated', ' '. ucwords($attribute) .' Updated '); 
		$message .= ucwords($attribute) . " updated. ";
		job_tracker_clear_cache();
	}
}

function job_tracker_clear_job_status($job_id) {
	global $wpdb;
	if(isset($job_id)) {
		if($wpdb->query("DELETE FROM ".Job_Tracker::tablename('log')." WHERE job_id = '$job_id'"))
		return "Logs for job #$job_id cleared.";
	}
}

function job_tracker_md5_to_job($md5) {
	global $wpdb, $_job_tracker_md5_to_invoice_cache;

	if (isset($_job_tracker_md5_to_invoice_cache[$md5]) && $_job_tracker_md5_to_invoice_cache[$md5]) {
		return $_job_tracker_md5_to_invoice_cache[$md5];
	}

	$md5_escaped = mysql_escape_string($md5);
	$all_invoices = $wpdb->get_col("SELECT id FROM ".Job_Tracker::tablename('jobs')." WHERE MD5(id) = '{$md5_escaped}' OR MD5(display_id) = '{$md5_escaped}'");
	foreach ($all_invoices as $value) {
		if(md5($value) == $md5) {
			$_job_tracker_md5_to_invoice_cache[$md5] = $value;
			return $_job_tracker_md5_to_invoice_cache[$md5];
		}
	}
}

function job_tracker_create_invoice($job_id, $invoice_id = null) {
	global $wpdb;
	
	$job_info = $wpdb->get_row("SELECT * FROM ".Job_Tracker::tablename('jobs')." WHERE id = '{$job_id}'");
	
	$job_number = $job_info->display_id;
	$user_id = $job_info->user_id;
	
	if (empty($job_number)) $job_number = $job_id;
	
	if($invoice_id != null) {
		$message = "Added to Invoice {$invoice_id}.";
		$job_ids = web_invoice_meta($invoice_id, 'added_job_ids');
		if (is_array($job_ids)) {
			$job_ids[] = $job_id;
		} else {
			$job_ids = array($job_id);
		}
		web_invoice_update_invoice_meta($invoice_id, 'added_job_ids', $job_ids);
		job_tracker_update_job_meta($job_id, 'invoice_id', $invoice_id);
		job_tracker_update_job_meta($job_id, 'added_to_invoice', true);
		job_tracker_update_log($job_id, 'invoice', 'Added to Invoice # '.$invoice_id);
	} else {
		
		if ($invoice_id == null) {
			$invoice_id = rand(10000000, 90000000);
		}
		$subject = "{$job_info->subject} - Job # {$job_number} ";
		
		if ($wpdb->query("INSERT INTO ".Web_Invoice::tablename('main')." (invoice_num, user_id, subject, description, status) VALUES ('$invoice_id','$user_id','$subject','{$job_info->description}','0')")) {		
			$message = "New Invoice saved.";
			web_invoice_update_log($invoice_id, 'created', ' Created ');
			web_invoice_update_invoice_meta($invoice_id, 'job_id', $job_id);
			job_tracker_update_job_meta($job_id, 'invoice_id', $invoice_id);
			job_tracker_update_log($job_id, 'invoice', 'Created Invoice # '.$invoice_id);
		}
	}
	
	if ($invoice_id) {
		$_REQUEST['invoice_id'] = $invoice_id;
		header('HTTP/1.1 302 Found');
		header("Location: admin.php?page=new_web_invoice&web_invoice_action=doInvoice&invoice_id={$invoice_id}");
		exit(0);
	}
}

function job_tracker_frontend_css() {
	if(get_option('job_tracker_page') != '' && is_page(get_option('job_tracker_page')))  {
		echo '<meta name="robots" content="noindex, nofollow" />';
		echo '<link type="text/css" media="screen" rel="stylesheet" href="' . Job_Tracker::frontend_path() . '/css/wp_screen.css?201031201"></link>' . "\n";
	}
}
