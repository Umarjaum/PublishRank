<?php
/** Built-in PublishRank Newsroom module. */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/advanced.php';

final class PublishRank_Newsroom {
    use PublishRank_Newsroom_Advanced;
    const VERSION = '2.9.87';
    const POST_TYPE = 'uj_news_story';
    const CAP_ACCESS = 'uj_newsroom_access';
    const CAP_MANAGE = 'uj_newsroom_manage';
    const CAP_CREATE = 'uj_newsroom_create_story';
    const OPTION = 'uj_newsroom_role_caps';
    private static $instance;
    public static function instance() { return self::$instance ?: (self::$instance = new self()); }
    private function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_uj_save_story', [$this, 'save_story']);
        add_action('admin_post_uj_advanced_settings', [$this, 'save_advanced_settings']);
        add_action('admin_post_uj_discuss', [$this, 'handle_discussion']);
        add_action('admin_post_uj_upload_attachment', [$this, 'handle_attachment']);
        add_action('admin_post_uj_download_attachment', [$this, 'download_attachment']);
        add_action('admin_post_uj_personal_settings', [$this, 'save_personal_settings']);
        add_action('admin_post_uj_save_view', [$this, 'save_view']);
        add_action('admin_post_uj_export_csv', [$this, 'export_csv']);
        add_action('admin_post_uj_calendar_feed', [$this, 'calendar_feed']);
        add_action('admin_post_nopriv_uj_calendar_feed', [$this, 'calendar_feed']);
        add_action('admin_post_uj_publish_handoff', [$this, 'publishing_handoff']);
        add_action('admin_post_uj_update_stage', [$this, 'update_stage']);
        add_action('admin_post_uj_add_note', [$this, 'add_note']);
        add_action('admin_post_uj_save_roles', [$this, 'save_roles']);
        add_action('admin_post_uj_delete_story', [$this, 'delete_story']);
        add_action('admin_post_uj_bulk_update', [$this, 'bulk_update']);
        add_action('uj_newsroom_daily_reminders', [$this, 'send_due_reminders']);
        add_action('uj_newsroom_daily_reminders', [$this, 'send_daily_digests']);
        add_filter('post_row_actions', [$this, 'hide_row_actions'], 10, 2);
    }
    public static function activate() {
        $defaults = ['administrator'=>['access'=>1,'manage'=>1,'create'=>1], 'editor'=>['access'=>1,'manage'=>1,'create'=>1], 'author'=>['access'=>1,'manage'=>0,'create'=>1], 'contributor'=>['access'=>1,'manage'=>0,'create'=>1]];
        if (!get_option(self::OPTION)) add_option(self::OPTION, $defaults);
        self::apply_role_caps(get_option(self::OPTION, $defaults));
        self::instance()->register_post_type(); if (!wp_next_scheduled('uj_newsroom_daily_reminders')) wp_schedule_event(time()+3600, 'daily', 'uj_newsroom_daily_reminders'); flush_rewrite_rules();
    }
    public static function deactivate() { $timestamp=wp_next_scheduled('uj_newsroom_daily_reminders'); if($timestamp) wp_unschedule_event($timestamp,'uj_newsroom_daily_reminders'); flush_rewrite_rules(); }
    private static function apply_role_caps($config) {
        foreach (wp_roles()->roles as $slug=>$role_data) {
            $role = get_role($slug); if (!$role) continue;
            $settings = isset($config[$slug]) ? $config[$slug] : [];
            foreach ([self::CAP_ACCESS=>'access', self::CAP_MANAGE=>'manage', self::CAP_CREATE=>'create'] as $cap=>$key) {
                if (!empty($settings[$key])) $role->add_cap($cap); else $role->remove_cap($cap);
            }
        }
    }
    public function register_post_type() {
        register_post_type(self::POST_TYPE, ['labels'=>['name'=>'Newsroom Stories','singular_name'=>'Newsroom Story'], 'public'=>false, 'show_ui'=>false, 'show_in_rest'=>false, 'supports'=>['title','editor','author','revisions'], 'capability_type'=>'post', 'map_meta_cap'=>true]);
    }
    public function admin_menu() {
        if (!current_user_can(self::CAP_ACCESS)) return;
        // Primary Newsroom link follows Dashboard in the PublishRank submenu.
        $parent = \PublishRankSEO\Core\Branding::MENU_SLUG;
        add_submenu_page($parent, 'Newsroom', 'Newsroom', self::CAP_ACCESS, 'uj-newsroom', [$this,'render_dashboard']);
        // Add the remaining newsroom destinations after Site Verification, keeping
        // all links visible under PublishRank rather than under a removed top-level menu.
        add_action('admin_menu', [$this, 'admin_submenus'], 11);
    }
    public function admin_submenus() {
        if (!current_user_can(self::CAP_ACCESS)) return;
        $parent = \PublishRankSEO\Core\Branding::MENU_SLUG;
        add_submenu_page($parent,'Editorial Board','Planning Board',self::CAP_ACCESS,'uj-newsroom-board',[$this,'render_board']);
        add_submenu_page($parent,'Newsroom Calendar','Calendar',self::CAP_ACCESS,'uj-newsroom-calendar',[$this,'render_calendar']);
        add_submenu_page($parent,'Activity History','Activity',self::CAP_ACCESS,'uj-newsroom-activity',[$this,'render_activity']);
        add_submenu_page($parent,'My Notifications','My Notifications',self::CAP_ACCESS,'uj-newsroom-notifications',[$this,'render_personal_settings']);
        if (current_user_can(self::CAP_MANAGE)) add_submenu_page($parent,'Configuration','Newsroom Configuration',self::CAP_MANAGE,'uj-newsroom-settings',[$this,'render_advanced_settings']);
        if (current_user_can(self::CAP_MANAGE)) add_submenu_page($parent,'Newsroom Roles','Newsroom Roles',self::CAP_MANAGE,'uj-newsroom-roles',[$this,'render_roles']);
    }
    public function assets($hook) {
        if (strpos($hook, 'uj-newsroom') === false) return;
        wp_enqueue_style('uj-newsroom-admin', PUBLISHRANK_SEO_URL . 'assets/newsroom/admin.css', [], self::VERSION);
    }
    private function meta($id,$key,$default='') { $v=get_post_meta($id,'_uj_'.$key,true); return $v === '' ? $default : $v; }
    private function can_edit_story($id) { if(!current_user_can(self::CAP_ACCESS)&&!current_user_can(self::CAP_MANAGE))return false; return current_user_can(self::CAP_MANAGE) || (int)get_post_field('post_author',$id) === get_current_user_id() || (int)$this->meta($id,'reporter',0) === get_current_user_id() || (int)$this->meta($id,'editor',0) === get_current_user_id(); }
    private function url($page,$args=[]) { return add_query_arg(array_merge(['page'=>$page],$args),admin_url('admin.php')); }
    private function notice() { if (!empty($_GET['uj_msg'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['uj_msg']))).'</p></div>'; }
    private function users() { $users=get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_email']]);return array_values(array_filter($users,function($u){return user_can($u->ID,self::CAP_ACCESS)||user_can($u->ID,self::CAP_MANAGE);})); }
    private function user_select($name,$selected=0,$label='Unassigned') {
        $html='<select name="'.esc_attr($name).'"><option value="0">'.esc_html($label).'</option>';
        foreach ($this->users() as $u) $html.='<option value="'.(int)$u->ID.'" '.selected((int)$selected,(int)$u->ID,false).'>'.esc_html($u->display_name).'</option>';
        return $html.'</select>';
    }
    private function story_query($args=[]) {
        $defaults=['post_type'=>self::POST_TYPE,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'meta_value','meta_key'=>'_uj_deadline','order'=>'ASC'];
        $stories=get_posts(array_merge($defaults,$args));
        if(current_user_can(self::CAP_MANAGE)) return $stories;
        $uid=get_current_user_id();
        return array_values(array_filter($stories,function($s)use($uid){return (int)$s->post_author===$uid||(int)$this->meta($s->ID,'reporter',0)===$uid||(int)$this->meta($s->ID,'editor',0)===$uid;}));
    }
    private function story_form($story=null) {
        if (!current_user_can(self::CAP_CREATE) && !current_user_can(self::CAP_MANAGE)) return;
        $id=$story ? $story->ID : 0; $editable=current_user_can(self::CAP_MANAGE) || !$id || $this->can_edit_story($id);
        if (!$editable) return;
        $deadline=$id ? $this->meta($id,'deadline') : ''; $stage=$id ? $this->meta($id,'stage',array_key_first($this->stage_names())) : array_key_first($this->stage_names());
        echo '<details class="uj-create" '.($id?'open':'').'><summary>'.($id?'Edit assignment':'＋ New editorial assignment').'</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="uj-form">';
        wp_nonce_field('uj_save_story'); echo '<input type="hidden" name="action" value="uj_save_story"><input type="hidden" name="story_id" value="'.(int)$id.'">';
        echo '<label>Headline<input required name="title" value="'.esc_attr($id?$story->post_title:'').'" maxlength="200"></label><label>Brief / assignment details<textarea name="content" rows="4">'.esc_textarea($id?$story->post_content:'').'</textarea></label><div class="uj-form-grid"><label>Reporter'.$this->user_select('reporter',$id?$this->meta($id,'reporter',0):0).'</label><label>Editor'.$this->user_select('editor',$id?$this->meta($id,'editor',0):0).'</label><label>Deadline<input type="datetime-local" name="deadline" value="'.esc_attr($deadline).'" required></label><label>Stage<select name="stage">';
        foreach ($this->stage_names() as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($stage,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label>'.$this->add_advanced_fields('', $id).'<label>Priority<select name="priority">'; foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $pk=>$pv) echo '<option value="'.esc_attr($pk).'" '.selected($id?$this->meta($id,'priority','normal'):'normal',$pk,false).'>'.esc_html($pv).'</option>'; echo '</select></label></div><button class="button button-primary">'.($id?'Save changes':'Create assignment').'</button></form></details>';
        echo '<p class="description">Assignments are visible only to newsroom managers, their creator, and users assigned as reporter or editor.</p>';
    }
    private function user_name($id) { $u=$id ? get_user_by('id',(int)$id) : false; return $u ? $u->display_name : '—'; }
    private function is_overdue($story) { $d=$this->meta($story->ID,'deadline');return $this->meta($story->ID,'stage')!=='published'&&$d&&str_replace('T',' ',$d)<current_time('mysql'); }
    private function story_card($s,$compact=false) {
        $id=$s->ID; $stage=$this->meta($id,'stage','pitch'); $deadline=$this->meta($id,'deadline'); $overdue=$this->is_overdue($s);
        echo '<article class="uj-card '.($overdue?'is-overdue':'').'">';
        echo '<div class="uj-card-top">'.(current_user_can(self::CAP_MANAGE)?'<label class="uj-select"><input type="checkbox" name="story_ids[]" value="'.(int)$id.'" form="ujbulk"> Select</label>':'').'<a class="uj-title" href="'.esc_url($this->url('uj-newsroom',['edit'=>$id])).'">'.esc_html($s->post_title).'</a><span class="uj-stage uj-stage-'.esc_attr($stage).'">'.esc_html($this->stage_names()[$stage]??$stage).'</span></div>';
        if (!$compact && $s->post_content) echo '<p class="uj-summary">'.esc_html(wp_trim_words(wp_strip_all_tags($s->post_content),24)).'</p>';
        echo '<div class="uj-meta"><span class="uj-priority uj-priority-'.esc_attr($this->meta($id,'priority','normal')).'">'.esc_html(strtoupper($this->meta($id,'priority','normal'))).'</span><span>Desk: '.esc_html($this->meta($id,'desk',$this->meta($id,'section','—'))).'</span><span>Reporter: '.esc_html($this->user_name($this->meta($id,'reporter',0))).'</span><span>Editor: '.esc_html($this->user_name($this->meta($id,'editor',0))).'</span><span class="'.($overdue?'uj-overdue':'').'">Due: '.esc_html($deadline ? mysql2date(get_option('date_format').' '.get_option('time_format'),str_replace('T',' ',$deadline)) : '—').($overdue?' · OVERDUE':'').'</span></div>';
        if ($this->can_edit_story($id)) {
            echo '<div class="uj-card-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('uj_update_stage_'.$id); echo '<input type="hidden" name="action" value="uj_update_stage"><input type="hidden" name="story_id" value="'.(int)$id.'"><select name="stage" aria-label="Change stage">'; foreach ($this->stage_names() as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($stage,$k,false).'>'.esc_html($v).'</option>'; echo '</select><button class="button">Update stage</button></form><a class="button" href="'.esc_url($this->url('uj-newsroom',['edit'=>$id])).'">Notes / Edit</a></div>';
        }
        echo '</article>';
    }
    public function render_dashboard() {
        echo '<div class="wrap uj-wrap"><h1>PublishRank Newsroom</h1>'; $this->notice();
        $all=$this->story_query(); $counts=array_fill_keys(array_keys($this->stage_names()),0); $overdue=0;
        foreach($all as $s){$k=$this->meta($s->ID,'stage','pitch');if(isset($counts[$k]))$counts[$k]++;if($this->is_overdue($s))$overdue++;}
        echo '<div class="uj-stats">'; foreach($counts as $k=>$n) echo '<div class="uj-stat"><span>'.esc_html($this->stage_names()[$k]).'</span><strong>'.(int)$n.'</strong></div>'; echo '<div class="uj-stat uj-stat-alert"><span>Overdue</span><strong>'.(int)$overdue.'</strong></div></div>';
        if(isset($_GET['edit'])){$story=get_post((int)$_GET['edit']);if($story&&$story->post_type===self::POST_TYPE&&$this->can_edit_story($story->ID))$this->story_form($story);else echo '<div class="notice notice-error"><p>Story not found or access denied.</p></div>';}
        else $this->story_form();
        echo '<h2>All stories</h2><div class="uj-toolbar"><form method="get"><input type="hidden" name="page" value="uj-newsroom"><input type="search" name="q" placeholder="Search headlines and briefs" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['q']??''))).'"><select name="stage"><option value="">All stages</option>'; foreach($this->stage_names() as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($_GET['stage']??'',$k,false).'>'.esc_html($v).'</option>'; echo '</select><select name="desk"><option value="">All desks</option>';foreach($this->desks() as $desk)echo '<option value="'.esc_attr($desk).'" '.selected($_GET['desk']??'',$desk,false).'>'.esc_html($desk).'</option>';echo '</select><select name="priority"><option value="">All priorities</option>'; foreach(['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $pk=>$pv)echo '<option value="'.esc_attr($pk).'" '.selected($_GET['priority']??'',$pk,false).'>'.esc_html($pv).'</option>';echo '</select><button class="button">Filter</button></form></div>';$this->render_saved_views();echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('uj_export_csv');echo '<input type="hidden" name="action" value="uj_export_csv"><button class="button">Export CSV</button></form>';if(current_user_can(self::CAP_MANAGE)){echo '<form id="ujbulk" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('uj_bulk_update');echo '<input type="hidden" name="action" value="uj_bulk_update"><label>Bulk change stage <select name="bulk_stage">';foreach($this->stage_names() as $bk=>$bv)echo '<option value="'.esc_attr($bk).'">'.esc_html($bv).'</option>';echo '</select></label> <button class="button">Apply to selected</button></form>';}echo '<div class="uj-story-list">';
        $stage_filter=sanitize_key($_GET['stage']??'');$query=sanitize_text_field(wp_unslash($_GET['q']??''));$priority_filter=sanitize_key($_GET['priority']??'');$desk_filter=sanitize_text_field(wp_unslash($_GET['desk']??''));$shown=0;foreach($all as $s) { $text=strtolower($s->post_title.' '.wp_strip_all_tags($s->post_content)); if(($stage_filter&&$this->meta($s->ID,'stage','pitch')!==$stage_filter)||($priority_filter&&$this->meta($s->ID,'priority','normal')!==$priority_filter)||($desk_filter&&$this->meta($s->ID,'desk')!==$desk_filter)||($query&&strpos($text,strtolower($query))===false))continue;$this->story_card($s);$shown++; } if(!$shown)echo '<div class="uj-empty">No matching stories.</div>';
        if(!$all)echo '<div class="uj-empty">No stories yet. Create the newsroom’s first assignment above.</div>'; echo '</div></div>';
        if(isset($_GET['edit'])) $this->render_story_detail((int)$_GET['edit']);
    }
    private function render_story_detail($id) {
        $story=get_post($id); if(!$story||$story->post_type!==self::POST_TYPE||!$this->can_edit_story($id)) return;
        $notes=get_post_meta($id,'_uj_notes',true); if(!is_array($notes))$notes=[];
        echo '<div class="wrap uj-wrap uj-notes"><h2>Private editorial notes</h2><p>Visible only to newsroom users with story access. Notes are not published.</p><div class="uj-note-list">';
        foreach(array_reverse($notes) as $n)echo '<div class="uj-note"><strong>'.esc_html($n['author']).'</strong> <time>'.esc_html($n['date']).'</time><p>'.nl2br(esc_html($n['text'])).'</p></div>';
        if(!$notes)echo '<p>No notes yet.</p>'; echo '</div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="uj-form">'; wp_nonce_field('uj_add_note_'.$id); echo '<input type="hidden" name="action" value="uj_add_note"><input type="hidden" name="story_id" value="'.(int)$id.'"><label>Add a private note<textarea required name="note" rows="3" maxlength="5000"></textarea></label><button class="button button-primary">Add note</button></form>';
        if(current_user_can(self::CAP_MANAGE)){echo '<form class="uj-delete" method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Permanently delete this assignment and its notes?\')">';wp_nonce_field('uj_delete_story_'.$id);echo '<input type="hidden" name="action" value="uj_delete_story"><input type="hidden" name="story_id" value="'.(int)$id.'"><button class="button">Delete story</button></form>';}
        $this->render_collaboration($id); $this->render_revisions($id); $this->render_handoff($id);
        echo '</div>';
    }
    public function render_board() {
        echo '<div class="wrap uj-wrap"><h1>Daily editorial planning board</h1>'; $this->notice(); $today=current_time('Y-m-d'); echo '<p class="description">Assignments due today ('.$today.') and current stage overview.</p><div class="uj-board">';
        $all=$this->story_query(); foreach($this->stage_names() as $k=>$v){echo '<section class="uj-lane"><h2>'.esc_html($v).'<span>'.count(array_filter($all,function($s)use($k){return $this->meta($s->ID,'stage','pitch')===$k;})).'</span></h2>'; $any=false; foreach($all as $s)if($this->meta($s->ID,'stage','pitch')===$k){$any=true;$this->story_card($s,true);} if(!$any)echo '<p class="uj-lane-empty">No stories</p>';echo '</section>'; } echo '</div></div>';
    }
    public function render_calendar() {
        $month=sanitize_text_field($_GET['month']??current_time('Y-m')); if(!preg_match('/^\d{4}-\d{2}$/',$month))$month=current_time('Y-m'); $first=strtotime($month.'-01'); $prev=date('Y-m',strtotime('-1 month',$first));$next=date('Y-m',strtotime('+1 month',$first));
        echo '<div class="wrap uj-wrap"><h1>Newsroom calendar</h1><div class="uj-calendar-nav"><a class="button" href="'.esc_url($this->url('uj-newsroom-calendar',['month'=>$prev])).'">← Previous</a><strong>'.esc_html(date_i18n('F Y',$first)).'</strong><a class="button" href="'.esc_url($this->url('uj-newsroom-calendar',['month'=>$next])).'">Next →</a></div><div class="uj-calendar"><div class="uj-weekdays">';foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)echo '<div>'.esc_html($day).'</div>'; echo '</div><div class="uj-days">';
        $offset=(int)date('N',$first)-1;$days=(int)date('t',$first);$cells=(int)(ceil(($offset+$days)/7)*7);$byday=[];foreach($this->story_query() as $s){$d=$this->meta($s->ID,'deadline');if($d&&substr($d,0,7)===$month)$byday[(int)substr($d,8,2)][]=$s;}
        for($i=0;$i<$cells;$i++){$num=$i-$offset+1;$inside=$num>=1&&$num<=$days;echo '<div class="uj-day '.(!$inside?'is-muted':'').'">'.($inside?'<span class="uj-day-num">'.$num.'</span>':'');if($inside&&!empty($byday[$num]))foreach($byday[$num] as $s)echo '<a class="uj-event uj-event-'.esc_attr($this->meta($s->ID,'stage','pitch')).'" href="'.esc_url($this->url('uj-newsroom',['edit'=>$s->ID])).'" title="'.esc_attr($s->post_title).'">'.esc_html($s->post_title).'</a>';echo '</div>';}
        echo '</div></div><p class="description">Calendar entries are scheduled assignment deadlines. Overdue, unpublished assignments are also highlighted on the story cards.</p></div>';
    }
    public function render_activity() {
        echo '<div class="wrap uj-wrap"><h1>Editorial activity history</h1><p class="description">Recent story creation, assignment, stage, and private-note events.</p><table class="widefat striped uj-activity"><thead><tr><th>When</th><th>Story</th><th>Event</th><th>By</th><th>Details</th></tr></thead><tbody>';
        $events=$this->story_query(['meta_key'=>'_uj_activity','orderby'=>'meta_value','order'=>'DESC']);$rows=[];foreach($events as $s){$a=get_post_meta($s->ID,'_uj_activity',true);if(is_array($a))foreach($a as $e)$rows[]=['id'=>$s->ID,'title'=>$s->post_title]+$e;}usort($rows,function($a,$b){return strcmp($b['time'],$a['time']);});$rows=array_slice($rows,0,200);
        foreach($rows as $e)echo '<tr><td>'.esc_html($e['time']).'</td><td><a href="'.esc_url($this->url('uj-newsroom',['edit'=>$e['id']])).'">'.esc_html($e['title']).'</a></td><td>'.esc_html($e['event']).'</td><td>'.esc_html($e['by']).'</td><td>'.esc_html($e['details']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5">No newsroom activity yet.</td></tr>';echo '</tbody></table><p class="description">Showing up to the latest 200 recorded events.</p></div>';
    }
    public function render_roles() {
        if(!current_user_can(self::CAP_MANAGE))return; $caps=get_option(self::OPTION,[]);echo '<div class="wrap uj-wrap"><h1>Newsroom roles & permissions</h1>'; $this->notice(); echo '<p>Choose which existing WordPress roles can access the newsroom, manage all assignments and configuration, or create assignments. Administrators retain their normal WordPress privileges; avoid granting access to roles that should not see private editorial material.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('uj_save_roles');echo '<input type="hidden" name="action" value="uj_save_roles"><table class="widefat striped"><thead><tr><th>WordPress role</th><th>Access newsroom</th><th>Manage all stories & roles</th><th>Create assignments</th></tr></thead><tbody>';foreach(wp_roles()->roles as $slug=>$rd){$label=translate_user_role($rd['name']);$c=$caps[$slug]??[];echo '<tr><td>'.esc_html($label).'</td>';foreach(['access','manage','create'] as $key)echo '<td><label><input type="checkbox" name="caps['.esc_attr($slug).']['.$key.']" value="1" '.checked(!empty($c[$key]),true,false).'> '.esc_html(ucfirst($key)).'</label></td>';echo '</tr>';}echo '</tbody></table><p><button class="button button-primary">Save permissions</button></p></form><p><strong>Suggested setup:</strong> assign newsroom access and create permission to reporters; reserve manage permission for editors and managing editors. Story ownership/assignment permissions allow assigned reporters and editors to update stages and add private notes.</p></div>';
    }
    private function log_event($id,$event,$details='') { $a=get_post_meta($id,'_uj_activity',true);if(!is_array($a))$a=[];$a[]=['time'=>current_time('mysql'),'event'=>$event,'by'=>wp_get_current_user()->display_name,'details'=>$details];update_post_meta($id,'_uj_activity',$a); }
    public function save_story() {
        if(!current_user_can(self::CAP_CREATE)&&!current_user_can(self::CAP_MANAGE))wp_die('Permission denied.');check_admin_referer('uj_save_story');$id=absint($_POST['story_id']??0);$oldReporter=$id?(int)$this->meta($id,'reporter',0):0;$oldEditor=$id?(int)$this->meta($id,'editor',0):0;$oldDeadline=$id?$this->meta($id,'deadline'):'';$oldPriority=$id?$this->meta($id,'priority','normal'):'normal';$oldDesk=$id?$this->meta($id,'desk'):'';$title=sanitize_text_field(wp_unslash($_POST['title']??''));$content=wp_kses_post(wp_unslash($_POST['content']??''));$deadline=sanitize_text_field(wp_unslash($_POST['deadline']??''));$stage=sanitize_key($_POST['stage']??'pitch');if(!$title||!in_array($stage,array_keys($this->stage_names()),true)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',$deadline))wp_die('Please provide a headline, valid stage, and deadline.');
        if(!$id&&!current_user_can(self::CAP_MANAGE)&&$stage!==array_key_first($this->stage_names()))wp_die('New stories must start at the first workflow stage.');if($id){$old=get_post($id);if(!$old||$old->post_type!==self::POST_TYPE||!$this->can_edit_story($id))wp_die('Permission denied.');$priorStage=$this->meta($id,'stage',array_key_first($this->stage_names()));if(!current_user_can(self::CAP_MANAGE)&&$priorStage!==$stage){$keys=array_keys($this->stage_names());if(abs(array_search($stage,$keys,true)-array_search($priorStage,$keys,true))>1)wp_die('Stage transition requires the next or previous workflow stage.');}wp_update_post(['ID'=>$id,'post_title'=>$title,'post_content'=>$content]);}else{$id=wp_insert_post(['post_type'=>self::POST_TYPE,'post_status'=>'publish','post_title'=>$title,'post_content'=>$content,'post_author'=>get_current_user_id()],true);if(is_wp_error($id))wp_die(esc_html($id->get_error_message()));}
        $reporter=absint($_POST['reporter']??0);$editor=absint($_POST['editor']??0);if(!current_user_can(self::CAP_MANAGE)){if(!$id||!$this->meta($id,'reporter'))$reporter=get_current_user_id();$editor=$oldEditor;}
        update_post_meta($id,'_uj_reporter',$reporter);update_post_meta($id,'_uj_editor',$editor);update_post_meta($id,'_uj_deadline',$deadline);$priority=sanitize_key($_POST['priority']??'normal');if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';update_post_meta($id,'_uj_priority',$priority);update_post_meta($id,'_uj_section',sanitize_text_field(wp_unslash($_POST['section']??'')));$source=esc_url_raw(wp_unslash($_POST['source_url']??''));update_post_meta($id,'_uj_source_url',$source);$this->save_advanced_meta($id);if($oldReporter!==$reporter)$this->log_event($id,'Reporter changed',$this->user_name($oldReporter).' → '.$this->user_name($reporter));if($oldEditor!==$editor)$this->log_event($id,'Editor changed',$this->user_name($oldEditor).' → '.$this->user_name($editor));if($oldDeadline!==$deadline)$this->log_event($id,'Deadline changed',$oldDeadline.' → '.$deadline);if($oldPriority!==$priority)$this->log_event($id,'Priority changed',$oldPriority.' → '.$priority);$newDesk=$this->meta($id,'desk');if($oldDesk!==$newDesk)$this->log_event($id,'Desk changed',$oldDesk.' → '.$newDesk);$previous=$this->meta($id,'stage');update_post_meta($id,'_uj_stage',$stage);$this->log_event($id,$previous?'Assignment updated':'Story created',($previous?'Details updated':'') );if($previous&&$previous!==$stage)$this->log_event($id,'Stage changed',$this->stage_names()[$previous].' → '.$this->stage_names()[$stage]);if(!$previous||$oldReporter!==$reporter||$oldEditor!==$editor)$this->notify_assignment($id,$reporter,$editor);wp_safe_redirect($this->url('uj-newsroom',['uj_msg'=>'Assignment saved']));exit;
    }
    public function update_stage() { $id=absint($_POST['story_id']??0);check_admin_referer('uj_update_stage_'.$id);$stage=sanitize_key($_POST['stage']??'');$s=get_post($id);if(!$s||$s->post_type!==self::POST_TYPE||!$this->can_edit_story($id)||!isset($this->stage_names()[$stage]))wp_die('Permission denied or invalid stage.');$old=$this->meta($id,'stage','pitch');if(!current_user_can(self::CAP_MANAGE)){$keys=array_keys($this->stage_names());$oldIndex=array_search($old,$keys,true);$newIndex=array_search($stage,$keys,true);if($oldIndex===false||$newIndex===false||abs($newIndex-$oldIndex)>1)wp_die('Stage transition requires the next or previous workflow stage.');}update_post_meta($id,'_uj_stage',$stage);if($old!==$stage)$this->log_event($id,'Stage changed',$this->stage_names()[$old].' → '.$this->stage_names()[$stage]);wp_safe_redirect(wp_get_referer()?:$this->url('uj-newsroom'));exit; }
    public function add_note() { $id=absint($_POST['story_id']??0);check_admin_referer('uj_add_note_'.$id);if(!$this->can_edit_story($id))wp_die('Permission denied.');$text=sanitize_textarea_field(wp_unslash($_POST['note']??''));if(!$text)wp_die('Note cannot be empty.');$notes=get_post_meta($id,'_uj_notes',true);if(!is_array($notes))$notes=[];$notes[]=['author'=>wp_get_current_user()->display_name,'date'=>current_time('mysql'),'text'=>$text];update_post_meta($id,'_uj_notes',$notes);$this->log_event($id,'Private note added','');wp_safe_redirect($this->url('uj-newsroom',['edit'=>$id,'uj_msg'=>'Private note added']));exit; }
    public function save_roles() { if(!current_user_can(self::CAP_MANAGE))wp_die('Permission denied.');check_admin_referer('uj_save_roles');$raw=isset($_POST['caps'])?(array)wp_unslash($_POST['caps']):[];$new=[];foreach(wp_roles()->roles as $slug=>$rd){$new[$slug]=[];foreach(['access','manage','create'] as $key)$new[$slug][$key]=!empty($raw[$slug][$key])?1:0;}update_option(self::OPTION,$new);self::apply_role_caps($new);wp_safe_redirect($this->url('uj-newsroom-roles',['uj_msg'=>'Permissions saved']));exit; }
    public function delete_story() { $id=absint($_POST['story_id']??0);check_admin_referer('uj_delete_story_'.$id);if(!current_user_can(self::CAP_MANAGE))wp_die('Permission denied.');$s=get_post($id);if(!$s||$s->post_type!==self::POST_TYPE)wp_die('Story not found.');$files=get_post_meta($id,'_uj_files',true);if(is_array($files))foreach($files as $f){$name=sanitize_file_name($f['file']??'');$path=WP_CONTENT_DIR.'/uploads/uj-newsroom-private/'.$name;if($name&&is_file($path))@unlink($path);}wp_delete_post($id,true);wp_safe_redirect($this->url('uj-newsroom',['uj_msg'=>'Story deleted']));exit; }
    private function notify_assignment($id,$reporter,$editor) {
        $story=get_post($id); if(!$story)return;
        $emails=[]; foreach(array_unique([absint($reporter),absint($editor)]) as $uid){$u=$uid?get_user_by('id',$uid):false;if($u&&is_email($u->user_email)&&get_user_meta($uid,'uj_newsroom_email_notifications',true)!=='0')$emails[]=$u->user_email;}
        if(!$emails)return;
        $subject='Newsroom assignment: '.$story->post_title;
        $message="You have been assigned to an editorial story.\n\nHeadline: ".$story->post_title."\nStage: ".$this->stage_names()[$this->meta($id,'stage','pitch')]."\nDeadline: ".$this->meta($id,'deadline')."\n\nSign in to WordPress and open PublishRank Newsroom to view the assignment.";
        wp_mail($emails,$subject,$message);
    }
    public function send_due_reminders() {
        if (get_option('uj_newsroom_reminders_enabled','1')!=='1') return;
        $today=current_time('Y-m-d'); foreach(get_posts(['post_type'=>self::POST_TYPE,'post_status'=>'publish','posts_per_page'=>-1]) as $s){$d=$this->meta($s->ID,'deadline');$stage=$this->meta($s->ID,'stage','pitch');if(!$d||$stage==='published'||substr($d,0,10)>$today)continue;$last=$this->meta($s->ID,'reminder_date');if($last===$today)continue;
            $emails=[];foreach([absint($this->meta($s->ID,'reporter',0)),absint($this->meta($s->ID,'editor',0))] as $uid){$u=$uid?get_user_by('id',$uid):false;if($u&&is_email($u->user_email)&&get_user_meta($uid,'uj_newsroom_email_notifications',true)!=='0')$emails[]=$u->user_email;}
            if($emails){wp_mail(array_unique($emails),'Overdue newsroom assignment: '.$s->post_title,"This assignment is overdue.\n\nDeadline: ".$d."\nCurrent stage: ".$this->stage_names()[$stage]."\n\nPlease update the story in PublishRank Newsroom.");update_post_meta($s->ID,'_uj_reminder_date',$today);$this->log_event($s->ID,'Overdue reminder sent',$today);}
        }
    }
    public function bulk_update() {
        if(!current_user_can(self::CAP_MANAGE)) wp_die('Permission denied.');check_admin_referer('uj_bulk_update');$ids=array_map('absint',(array)($_POST['story_ids']??[]));$stage=sanitize_key($_POST['bulk_stage']??'');if(!$ids||!isset($this->stage_names()[$stage])){wp_safe_redirect($this->url('uj-newsroom',['uj_msg'=>'Select stories and a valid stage']));exit;}
        foreach($ids as $id){$s=get_post($id);if(!$s||$s->post_type!==self::POST_TYPE)continue;$old=$this->meta($id,'stage','pitch');if(!current_user_can(self::CAP_MANAGE)){$keys=array_keys($this->stage_names());$oldIndex=array_search($old,$keys,true);$newIndex=array_search($stage,$keys,true);if($oldIndex===false||$newIndex===false||abs($newIndex-$oldIndex)>1)wp_die('Stage transition requires the next or previous workflow stage.');}update_post_meta($id,'_uj_stage',$stage);if($old!==$stage)$this->log_event($id,'Stage changed',$this->stage_names()[$old].' → '.$this->stage_names()[$stage].' (bulk update)');}
        wp_safe_redirect($this->url('uj-newsroom',['uj_msg'=>'Bulk stage update completed']));exit;
    }
    public function hide_row_actions($actions,$post) { if($post->post_type===self::POST_TYPE)return [];return $actions; }
}
PublishRank_Newsroom::instance();
