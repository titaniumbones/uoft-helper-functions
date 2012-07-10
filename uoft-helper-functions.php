<?php
/*
Plugin Name: UofT Helper Functions
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: Helper Functions for U of Toronto History Dept website. CCTM must be installed first!!
Version: 0.0.1
Author: Matt Price
Author URI: http://hackinghistory.ca
License: GPL3
*/




// include() or require() any necessary files here...
  include_once( CCTM_PATH . '/includes/GetPostsQuery.php');
  include_once( CCTM_PATH . '/includes/SummarizePosts.php');


// Settings and/or Configuration Details go here...




// Tie into WordPress Hooks and any functions that should run on load.

// if need debugging we can put that here. 
// add_action('wp_footer', '_uoth_debug');

// The main thing here is getting the course tables updated regularly

// On activation, set up a twice-daily update of tables

// create the twicedaily schedule
function uot_add_twicedaily( $schedules ) {
	// add a 'weekly' schedule to the existing set
	$schedules['weekly'] = array(
		'interval' => 43200,
		'display' => __('Twice Daily')
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'uot_add_twicedaily' ); 

// on registration, create the schedule
register_activation_hook(__FILE__, 'uot_tablecache_activation');
add_action('uot_cache_coursetables', 'uot_updatecoursetables');
function uot_tablecache_activation() {
  // this method only registers via the plugin registration interface
  if ( !wp_next_scheduled( 'uot_cache_coursetables' ) ) {
    wp_schedule_event(time(), 'twicedaily', 'uot_cache_coursetables');
  }
}

// on deactivation, remove the cron job.  
register_deactivation_hook(__FILE__, 'uot_tablecache_deactivation');
function uot_tablecache_deactivation() {
	wp_clear_scheduled_hook('cache_coursetables');
	wp_clear_scheduled_hook('uothelper_cache_coursetables');
	wp_clear_scheduled_hook('uot_cache_coursetables');
}



// verisoning, creating database
global $uot_db_version;
$uot_db_version = "0.1";
global $wpdb;
global $coursecachetable;
$coursecachetable = $wpdb->prefix . "coursecache"; 
function uot_install () {
   global $wpdb;
   global $coursecachetable;

   $sql = "CREATE TABLE " . $coursecachetable . " (
	  id varchar NOT NULL,
	  html text NOT NULL,
	  time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
	  UNIQUE KEY id (id)
	);";
   require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
   dbDelta($sql);
   add_option("uot_db_version", $uot_db_version);

   /* $installed_ver = get_option( "uot_db_version" );
    * if( $installed_ver != $uot_db_version ) {
    *    $sql = "CREATE TABLE " . $table_name . " (
    *    id mediumint(9) NOT NULL AUTO_INCREMENT,
    *    time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
    *    name tinytext NOT NULL,
    *    text text NOT NULL,
    *    url VARCHAR(100) DEFAULT '' NOT NULL,
    *    UNIQUE KEY id (id)
    *  );"; */

      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sql);

      update_option( "uot_db_version", $uot_db_version );
}
register_activation_hook(__FILE__,'uot_install');

function uot_update_db_check() {
  global $uot_db_version;
  if (get_site_option('uot_db_version') != $uot_db_version) {
    uot_install();
  }
}
add_action('plugins_loaded', 'uot_update_db_check');

// last one is to reduce the clutter in tag clouds.  
// cf http://theme.fm/2011/07/how-to-customize-the-wordpress-tag-cloud-widget-1045/
add_filter( 'simpletaxo_widget_tag_cloud_args', 'uoth_widget_tag_cloud_args' );
function uoth_widget_tag_cloud_args( $args ) {
  $args['largest'] = 19;
  $args['smallest'] = 9;
  $args['unit'] = 'px';    
  return $args;
}

function uot_chown_profiles() {
  global $wpdb;
  $allusers = $wpdb->get_results("SELECT ID, user_email FROM $wpdb->users where 1");
  print_r ($allusers);
  foreach ($allusers as $u) {
    $profile = find_user_profile_postid($u);
    print_r ($u);
    print "; ";
    print_r($profile);
    if (! empty($profile)) {
        $update = $wpdb->update($wpdb->posts, array('post_author' => $u->ID), array('ID' => $profile->post_id));
        print_r ($update);
      }
    print "<br>";
  } 
}
add_shortcode('chown_profile', 'uot_chown_profiles');


function uot_own_my_profile ($user_id) {
  global $wpdb;
  $user = get_userdata ($user_id);
  // print_r($user->roles);
  if ( (!in_array('faculty', $user->roles) ) and (!in_array('staff', $user->roles) ) and (!in_array('student', $user->roles) ) ) {
    return;
      } 
  else {
    $profile = find_user_profile_postid($user);
    if (! empty($profile)) {
      $update = $wpdb->update($wpdb->posts, array('post_author' => $user->ID), array('ID' => $profile->post_id));
      // print "found a profile for " . $user->user_email ;
      // print_r ($profile);
      return $profile->post_id;
    } else {
      // Create post object
      $my_post = array();
      $my_post['post_title'] = $user->last_name . ', ' . $user->first_name;
      $my_post['post_content'] = 'Please update this text to reflect your academic interests.';
      $my_post['post_status'] = 'draft';
      $my_post['post_author'] = $user_id;
      $my_post['post_type'] = 'people';
      $newprofile = wp_insert_post( $my_post );
      update_post_meta($newprofile, 'name_first', $user->first_name); // etc
      update_post_meta($newprofile, 'name_last', $user->last_name); // etc
      update_post_meta($newprofile, 'email', $user->user_email); // etc
      // print "created a new post for " .  $user->user_email ;
      //print_r ($profile);
      return $newprofile;
    }
  }
}

add_action( 'user_register', 'uot_own_my_profile');

function find_user_profile_postid($user) {
  global $wpdb;
  $query = "SELECT post_id from $wpdb->postmeta where meta_key = 'email' and meta_value LIKE '%" . $user->user_email . "%'";
  $profile = $wpdb->get_row($query);
  return $profile;
  
}

// oops, one more set to shift ownership of pofile page to new user



// "Private" internal functions named with a leading underscore
function _uoth_debug() {
  print "file name: " . __FILE__ ;
  print current_time('mysql');
  print "  next scheduled";
  print wp_next_scheduled('uot_cache_coursetable');
  print_r (get_option('cron'));
}


// The "Public" functions

// list children for main site pages
function child_pages_shortcode() {
   global $post;
   return '<ul class="childpages">'.wp_list_pages('echo=0&depth=0&title_li=&child_of='.$post->ID).'</ul>';
}
add_shortcode('children', 'child_pages_shortcode');

/** tiny helper to simplify title munging in course templates */
function strip_nums($ctitle){
  return substr(strstr($ctitle," "),1);
} 

// list taxonomy terms, to be used in shortcodes
function uot_list_terms_shortcode($atts) {
  $args = shortcode_atts( array(
                                'heading' => '',
                                'title_li' => '',
                                'taxonomy' => 'geographical-areas',
                                'orderby' => 'name',
                                'hierarchical' => 1,
                                'show_count' => 0,
                                'hide_empty' => 1,
                                ), $atts );
  if (empty($args['heading'])) {
    $t = get_taxonomy($args['taxonomy']);
    $args['heading'] = $t->labels->name;

    }
  $args['echo'] = false;
  $terms = SummarizePosts::get_taxonomy_terms($args);

  //  print_r($terms);
  $html = "<ul>hereitcomes".$args->title;
  foreach ($terms as $c){
    if ($c->is_active) {
      $html .= ' <li><a href="' . $c->permalink . ' class="active-collection"' .  $c->name . '</a></li>';
    }
    else {
      $html .= '<li><a href="' . $c->permalink . '">' .  $c->name . '</a></li>';     
    }
  }
  $output = '<h4>' . $args['heading'] . '</h4><div class="uot-columns"><ul class="uot-outer">' . wp_list_categories ($args) . "</ul></div>";
  $html .='</ul>';
  return $output;
}
add_shortcode('uot-list-terms', 'uot_list_terms_shortcode');


/** helper to get instructors from arrays. We'll use it in  other functions, below
*/
function get_instructor_list($l, $format='short'){
  // The array objects in CCTM are JSON-style objects
  // CCTM to_array filter parses them back in to PHP
  $insts = CCTM::filter( $l, 'to_array');
  // print_r ($insts);
  $html = "";
  $c = count($insts);
  // print ", count: " . $c;
  $sep = ", ";
  foreach ((array)$insts as $i) {
    // protect against empty IDs
    if ($i) {
      $c -= 1;
      if ($c <1) {
        $sep = "";
      }

      // better would be if we replace get_the_title with our own get_posts query:
      $Q = new GetPostsQuery();
      $cargs = array();
      $cargs['include'] = $i;
      $me = $Q->get_posts($cargs);
      $me = $me[0];
      if ($format == 'short') {
        $name_text = $me['name_first'][0] . " " .$me['name_last'];
      } 
      else {
        $name_text = $me['name_first'] . " " .$me['name_last'];
      }
      $html .= sprintf('<a href="%s">%s</a>%s', get_permalink($i), $name_text, $sep );
    }
  }
  return $html;
}


/**
Prints the results of get_instructor_list to the page
 */
function print_instructor_list($l){
  print get_instructor_list ($l);
}

/** simple function to list the sections of a course */
function full_semester_listings($section_array, $title_string, $numstring){
  $html="";
 if(! empty($section_array)) { 
   $html .='<table class="nozebra>"';
   $html .= sprintf( "<tr><td colspan=3><b>%s</b></td></tr>",$title_string);
   //   print_f("<b>Instructors</b>";
    foreach ($section_array as $s) {
      if (! empty($s['coursesection_secnum'])) {
         $secnum .= sprintf('-%s', $s['coursesection_secnum']);
      }
      else {
        $secnum="";
      }
      $t = $numstring . $s['coursesection_semester'] . $secnum ;
      $html .= sprintf( '<tr><td><b>%s</b></td>', $t);
      $html .= sprintf('<td>%s %s</td><td style="color:red"><b>%s</b></td></tr>', $s['coursesection_subtitle'],  get_instructor_list($s['coursesection_instructors']), $s['coursesection_alertnotice']);
      $html .= sprintf( '<tr><td></td><td colspan="2">%s</td></tr>', $s['coursesection_furtherdesc']);
    }
        $html .= '</table>';
 }
 return $html;
}

/**
 * Prints a bulleted list of items.  
 *
 * If it's courses, it will check 
 * for sections first and report back if the course is NOT offered
 * this is for use in search and archive pages
 * @param posts The list of posts
 * @param headline the Heading for the outputted list
 * @param anchor the final portion of the id tag for the results list (for building
 * tables of contents)
  */
function arch_list($posts, $headline="", $anchor="") {
  if (! empty($posts)) {
    printf('<div id="uot-%s"><h4 id="results-%s">%s</h4><ul>', $anchor, $anchor, $headline);
    foreach ($posts as $p) {
      // here I'm trying to add info to the course listings.
      // not working at present
      if ($p['post_type'] == "courses"){
        $final="";
        $s = get_sections($p);
        if (empty($s)){
          $final .= "(Not Offered This Year)";
        }
      }
      printf('<li><a href="%s">%s</a>%s</li>',$p['permalink'], $p['post_title'], $final);
    }
    print '</ul></div>';
  }
}


/**
 * return the sections for a course
  */
function get_sections($course) {
  $C = new GetPostsQuery();
  $cargs = array();
  $cargs['post_type'] = 'coursesection';
  $cargs['orderby']='coursesection_semcode';
  $cargs['order']='ASC';
  // using search_columns and search_terms b/c meta_key/meta_value doesn't seem to be working
  // actually can't do that, need to avoid false positives
  $cargs['meta_key'] = 'coursesection_parent';
  $cargs['meta_value'] = $course['ID'] ;
  return $C->get_posts($cargs);
}


function get_all_people(){
    $C = new GetPostsQuery();
  $cargs = array();
  $cargs['post_type'] = 'people';
  $cargs['orderby']='people_lastname';
  $cargs['order']='ASC';
  // using search_columns and search_terms b/c meta_key/meta_value doesn't seem to be working
  // actually can't do that, need to avoid false positives
  return $C->get_posts($cargs);
}
/** 
 * Print a table of courses, ordered by course number, and then 
 * saves it in the db.  curently creates v. static html, this should be 
 * extracted to a set of variables that customize the output better.  
 * @param courses: an array of course objects provided by GetPostsQuery
 * @return html: a long string containing the html for the desired table
 */
function course_table($courses, $header="Course Listing", $long=0, $name='') {
  // extract the parameters for the coursetable
  extract (unserialize($name));

  // initialize table html string
  $html =  '<table width="100%" id="coursetable" class="coursetable">
    <tr><th colspan="5" class="header">' . $header . '</td></tr>
    <tr class="heading" valign="bottom">
		<td style="width:28px"></td>
		<td colspan="2">Course, Section, Instructor</td>
		<td  style="width:50px">Day/Time</td>
		<td style="width:70px">Location</td>
	</tr>';
    $print_empty="1";
    $sections = array();
    switch ($season) {
    case "both":
      $filtered_seclist = "sections";
      break;
    case "summer":
      $filtered_seclist = "summer_sections";
      $print_empty = "0";
      break;
    default:
      $filtered_seclist = "year_sections";
      break;
    }  
    $table_is_empty=1;

  foreach ($courses as $c) {
    $summer_sections = array();
    $year_sections = array();
    $sections = get_sections($c);
    $cn = $c['course_department'] . $c['course_number'] . $c['course_semcode'];
    foreach ($sections as $s) {
      if ($s['coursesection_summer']) {
        $summer_sections[] = $s;
      } else {
        $year_sections[] = $s;
      }
    }
    // create the rows of the table, one by one    
    // now add either "not offered" or the actual course info, depending on whether there's a real section
    if (empty(${$filtered_seclist})) { 
      if ($print_empty) {
        // the course info row is italicized if not offered
        $table_is_empty = 0;
        $html .= sprintf('<tr class="coursename"><td valign="top" colspan = 5><i>%s<a href="%s"> %s</a></i></td></tr>' . "\n", $cn,$c['permalink'], strip_nums($c['post_title']) );
        $html .= '<tr class="section"><td></td><td colspan=4><i>Not Offered this year</i></td></tr>'; }
    }
    else {
      $table_is_empty = 0;
      $html .= sprintf('<tr class="coursename"><td valign="top" colspan = 5><b>%s</b><a href="%s"> %s</a></td></tr>' . "\n", $cn,$c['permalink'], strip_nums($c['post_title']) );
       foreach (${$filtered_seclist} as $s) {
        // create the coursesection section text
        $sec_text="";
        if (isset($s['coursesection_secnum'])) {
          $sec_text = ' ' . $s['coursesection_secnum'] . '';
          }  
        // 
        $html .= sprintf('<tr class="sectioninfo"><td></td><td	class="semster" style="width:50px" valign="top"><b>%s</b%s</td>', $s['coursesection_semester'], $sec_text);
        $html .= sprintf('<td valign="top">%s</td>', get_instructor_list($s['coursesection_instructors']) );
        $html .= sprintf('<td valign="top" >%s</td><td valign="top">%s</td></tr>', $s['coursesection_daytime'], $s['coursesection_room']);
        /* if (!empty ($long) && !empty ($s['coursesection_furtherdesc']) ) {
         * $html .= sprintf('<tr class="section"><td colspan="2"></td><td colspan="3" valign="top">%s</td></tr>', $s['coursesection_furtherdesc']);
         * } */
      }
    }
  }
  if (isset($table_is_empty)) {
  $html .=  '
    <tr class="heading" valign="bottom">
		<td style="width:28px"></td>
		<td colspan="2"><i>No Current Listings</i></td>
	</tr>
  </table>';
  return $html;
  }

  $html .= '</table>';

  global $wpdb;

  global $coursecachetable;
  $row_array = array( 'time' => current_time('mysql'), 'id' => $name, 'html' => $html );

  $q = "select * from " . $coursecachetable . ' WHERE id = \'' . $name . '\'';
  // check to see if the data is already stored
  $already_there = $wpdb->get_row($q, ARRAY_A);
  if ($already_there) {
    $rows_affected = $wpdb->update( $coursecachetable, $row_array, array('id' => $name) );
  } else {
    $rows_affected = $wpdb->insert( $coursecachetable, $row_array);
  }    
  return $html;
}

//uot_updatecoursetables();
/**
 * Handler function for coursetable shortcode
 *
 * Queries the db for all courses w/ course # btwn min and max, and 
 * returns a table listing them all.  
 */
add_shortcode('coursetable', 'coursetable_sc');

function coursetable_sc ($atts) {
  //print "loading table";
  global $wpdb;
  global $coursecachetable;
  $real_atts = shortcode_atts( array(
                                 'min' => 0,
                                 'max' => 10000,
                                 'header' => 'Course Listings',
                                 'long' => 0,
                                 'taxonomy' => '',
                                 'term' => '',
                                 'season' => 'year',
                                 'onlypremodern' => 0,
                                 'division' => 'all'
                                 ), $atts );
  extract ($atts);

  $name = serialize($real_atts);
  $q = "select * from " . $coursecachetable . ' WHERE id = \'' . $name . '\'';

  // check to see if the data is already stored
  $already_there = $wpdb->get_row($q, ARRAY_A);
  if ($already_there)
    {
      return $already_there['html'];
    }
  else {
    return uot_maketable($name);
  }
}

function uot_maketable($name) {
  $atts = unserialize($name);
  extract($atts);
  $Q = new GetPostsQuery();
  $args = array();
  $args['post_type'] = 'courses';
  $args['orderby'] = 'course_number';
  $args['order'] = 'ASC';
  $args['post_status'] = 'publish';
  if (isset($taxonomy)) {
    $args['taxonomy'] = $taxonomy;
    $args['taxonomy_term'] = $term;
  }
  $courses = $Q->get_posts($args);
  
  $filtered_courses = array();
  // note our logic in this loop relies on the query being ordered by course number!
  foreach ($courses as $c){
    if ( (int)$c['course_number'] >= $min && (int)$c['course_number'] <= $max) {
      switch ($onlypremodern) {
        case TRUE:
          if ($c['course_premodern'] > 0) {
            array_push($filtered_courses, $c);            
          }
          break;

      default:
        array_push($filtered_courses, $c);
      }
    }
  }

  return course_table($filtered_courses, $header, $long, $name);

}

function uot_updatecoursetables( ) {
  global $coursecachetable;
  global $wpdb;
  $msg = '';
  $sql = "select id from " . $coursecachetable;
  $all_the_ids = $wpdb->get_results($sql, ARRAY_A);
  if($all_the_ids) {
    foreach ($all_the_ids  as $i) {
      // print "<p><b>ID: </b>" . $i['id'] . '</p>';
      uot_maketable($i['id']);
      // $msg .= "tried to make table with " . var_export($i, true) . "\n";
    }
  }
  // only while debugging!
  // wp_mail( 'some.one@utoronto.ca', 'updating course schedules', 'Updating course schedules. Automatic scheduled email from WordPress.' . $msg);
}


function get_all_term_lists ($an_ID, $format = 'long') {
  $taxonomies = array(
                      array('geographical-areas', 'Geographical Areas'),
                      array('periods', "Periods"),
                      array('thematic-areas', 'Thematic Areas')
                      // array('role', 'Roles in Department')
                      );
  $html = '';
  foreach ($taxonomies as $t) {

    if ($format =='long') {
      $html .= get_the_term_list( $an_ID, $t[0], "<p><strong>". $t[1] . ": </strong>", ", ", "</p>" ) ;
    } 
    else {
      $html .= get_the_term_list( $an_ID, $t[0], "", ", ", "; " ) ;
    }
      
  }
  return $html;
}

  function print_all_term_lists ($an_ID, $format = 'long') {
  print get_all_term_lists ($an_ID, $format);
}

function get_person_status ($this_person) {
  //$status_text = "";
    if ($this_person['people_emeritus'] == 1) {
        $em = ' Emeritus';
      }
      else {
        $em = '';
      }
    if ($this_person['people_onleave'] == 1) {
      $ol = ', <i>Currently On Leave</i>';
        }
    else {
      $ol = '';
    }
    //  print 
    return $this_person['people_rank'] . $em . $ol;
}

function person_summary ($these_people) {
  $html = '<div class="post"><table class="zebra">';
    $html .= "\n";
  //$alt = TRUE;
  foreach ($these_people as $p) {
    $html .= '<tr>';
    $html .= "\n";
    $html .= sprintf ('<td width="78" valign="top"><div class="photo">%s</div></td>', CCTM::filter($p['photo'], 'to_image_tag', array(75,65)) );
    $html .= "\n";
    $status = get_person_status($p);
    if (!empty($status) ) {
      $extratext = "(" . $status . ")";
    }
    else {
      $extratext = '';
    }
    $html .= sprintf ('<td><div class="name"><b><a href="%s">%s %s</a></b> %s</div> ', $p['permalink'], CCTM::filter($p['name_first'], 'raw'), CCTM::filter($p['name_last'], 'raw'), $extratext );
    if ( !empty($p['post_content']) ) {
        $html .= sprintf ('<div class="description">%s%s</div>', CCTM::filter($p['post_content'], 'excerpt', 44), CCTM::filter($p['ID'], 'to_link', '[Read More]') );
      }
    $html .=  sprintf('<div class="terms">%s</div></td>', get_all_term_lists ($p['ID'], 'short') ) ;
    $html .= "\n";
    $html .= '</tr>';
    $html .= "\n";
                     
  }
  $html .= "</table></div>";
return $html;
}

function book_summary ($these_books) {
  $html = '<div class="post"><table class="zebra">';
  $html .= "\n";
  foreach ($these_books as $b) {
    // print_r($b['ID']);
    $html .= '<tr>';
    $html .= "\n";
    $pubinfo='(' . $b['book_city'] . ": " . $b['book_publisher'] . ", " . $b['book_year'] . ')';

    $html .= sprintf ('<td width="78" valign="top"><div class="photo">%s</div></td>', CCTM::filter($b['book_cover_image'], 'to_image_tag', array(75,65)) );
    $html .= sprintf ('<td valign="top">%s, <b><a href="%s">%s</a></b> %s', CCTM::filter($b['authors'], 'raw'), $b['permalink'], CCTM::filter($b['book_title'], 'raw') , $pubinfo);
    if ( !empty($b['large_blurb']) ) {
        $html .= sprintf ('<div class="description">%s%s</div>', CCTM::filter($b['large_blurb'], 'excerpt', 44), CCTM::filter($b['ID'], 'to_link', '[Read More]') );
      }
    if ( !empty($b['belongs_to']) ) {
      $html .= "<br>More About " . get_instructor_list(CCTM::filter($b['belongs_to']), 'raw') ;
    }
    // $html .=  sprintf('<div class="terms">%s</div></td>', get_all_term_lists ($b['ID'], 'short') ) ;
    $html .=  '</td>';
    $html .= "\n";
    $html .= '</tr>';
    $html .= "\n";
                     
  }
  $html .= "</table></div>";
return $html;
}

  function uot_get_my_courses ($person_id) {
  $html = "";
  $C = new GetPostsQuery();
  $cargs = array();
  $cargs['post_type'] = 'coursesection';
  // using search_columns and search_terms b/c meta_key/meta_value doesn't seem to be working
  $cargs['search_columns'] = array('coursesection_instructors');
  $cargs['search_term'] = '"' . $person_id . '"';
  $my_sections = $C->get_posts($cargs);
  $html .= "<p>";
  // print $my_courses;
  if (! empty($my_sections)) {
    $html .= '<b>Courses</b><ul>';
    foreach ($my_sections as $this_section) {
      $p = $this_section['coursesection_parent'];
      $l = get_permalink($p);
      $t = get_the_title($p);
      // $l = $this_section['permalink'];
      // $t = $this_section['post_title'];
      $html .= sprintf('<li><a href="%s">%s</a></li>',$l, $t);
    }
    $html .= '</ul></p>';
  }
  return $html;
}

function uot_get_my_advisees($person_id) {
 $html .= "";
  $A = new GetPostsQuery();
  $args = array();
  $args['post_type'] = 'people';
  $args['orderby'] = 'name_last';
  $args['search_columns'] = array('belongs_to');
  $args['search_term'] = '"' . $person_id . '"';
  $my_advisees = $A->get_posts($args);
  //  print_r ($B->debug());

  if (! empty($my_advisees)) {
   $html .= '<p><b>Graduate Student Advisees</b><ul>';

  foreach ($my_advisees as $a) {
    $l = $a['permalink'];
    $t = $a['post_title'];
    $html .= sprintf('<li><a href="%s">%s</a></li>',$l, $t);
  }
  $html .= '</ul></p>';
}
  return $html;
}

function uot_get_my_books ($person_id) {
  $html .= "";
  $B = new GetPostsQuery();
  $args = array();
  $args['post_type'] = 'book';
  $args['orderby'] = 'book_author';
  $args['search_columns'] = array('belongs_to');
  $args['search_term'] = '"' . $person_id . '"';
  $my_books = $B->get_posts($args);
  //  print_r ($B->debug());

  if (! empty($my_books)) {
   $html .= '<p><b>Books</b><ul>';

  foreach ($my_books as $this_book) {
    $l = $this_book['permalink'];
    $t = $this_book['post_title'];
    $html .= sprintf('<li><a href="%s">%s</a></li>',$l, $t);
  }
  $html .= '</ul></p>';
}
  return $html;
}

/* print a quick list of terms, pretty-LIKE   */
function uot_get_parents_and_kids($me, $sep=', ') {
  $termchildren = get_term_children( $me->term_id, $me->taxonomy );
  /* print_r ($me->term_id);
   * print_r ($me->taxonomy);
   * print '<br>';
   * print_r($termchildren);
   * print '<br>'; */
  if (!empty($me->parent)) { 
    array_unshift($termchildren,$me->parent);
  /* print '<br>';
   * print_r($termchildren);
   * print '<br>'; */
  }
  $length = count($termchildren);

  if (!empty($termchildren) ) {
   $html .= "<p>(<i><b>See Also:  </b>";
    foreach ($termchildren as $child) {
      /* print "child"; */
      $length = $length - 1;
      if ($length < 1 ) { $sep = ''; }
      $term = get_term_by( 'id', $child, $me->taxonomy );
       /* print "here";
        * print_r($term);
        * print_r(get_term_link ( $term->slug, $me->taxonomy )); */
      $html .= '<a href="' . get_term_link ( $term->slug, $me->taxonomy ) . '">' . $term->name . '</a>' . $sep;
     }
    $html .= ")</i></p>";
    }
  return $html;
}

/**
 * Prints all the taxonomy terms associated with a particular post or CCT
 * to use properly in a function it has to be converted from 'get_the_term_list'
 * @param an_ID the post ID to be queried
 * @param format long or short format
*/

  add_action('admin_bar_menu', 'customize_admin_bar', 11 );
  function customize_admin_bar( $wp_admin_bar ) {
  uot_admin_bar_my_account($wp_admin_bar);
  uot_admin_bar_help($wp_admin_bar);
  /*
    Removing the "W" menu
    I have nothing against it,
    but I *never* use it
  */
  $wp_admin_bar->remove_menu( 'wp-logo' );

}

  function uot_admin_bar_my_account ($wp_admin_bar) {
  global $wpdb;
  $user_id      = get_current_user_id();
  $current_user = wp_get_current_user();
  // now a tricky query to get the person's public profile:
  $query = "SELECT post_id from $wpdb->postmeta where meta_key = 'email' and meta_value LIKE '" . $current_user->user_email . "'";
  $public_profile_id = $wpdb->get_row($query)->post_id;

  $public_profile_url =  get_permalink($public_profile_id );

  /*
    Change "Howdy"
  */
  //get the node that contains "howdy"
  $my_account = $wp_admin_bar->get_node('my-account');
  //change the "howdy"
  $my_account->title = str_replace( 'Howdy,', "Hello,", $my_account->title );
  //remove the original node
  $wp_admin_bar->remove_node('my-account');
  //add back our modified version
  $wp_admin_bar->add_node( $my_account );

  // now get the original subnodes
  //$user_actions_node = $wp_admin_bar->get_node('user-actions');
  //  $user_info_node = $wp_admin_bar->get_node('user-info');
  $logout_node = $wp_admin_bar->get_node('logout');
  $edit_login_node = $wp_admin_bar->get_node('edit-profile');

  // and delete them all
  //$wp_admin_bar->remove_menu( 'user-actions' );  
  //  $wp_admin_bar->remove_menu( 'user-info' );  
  $wp_admin_bar->remove_menu( 'logout' );  
  $wp_admin_bar->remove_menu( 'edit-profile' );  

  // debug print statements
  /* print_r($public_profile_url);
   * print_r ($public_profile_id);
   * print_r($current_user);
   * print_r($my_account);
   * print_r($wp_admin_bar->get_nodes()); */

  // retitle the sub-node that lets you edit your profile
  $edit_login_node->title = "Edit My Login Information";
  $edit_login_node->group = 0;

  
    
  // create two new subnodes that let you view and edit your public profile
  // put them on top of the logout & edit nodes
  if (! empty ($public_profile_id) ) {
    $view_public_profile = array (
                                  'parent' => 'user-actions',
                                  'id'     => 'view-public-profile',
                                  'title'  => __( 'View My Public Profile'),
                                  'href'   => $public_profile_url,
                                  );
    $wp_admin_bar->add_node($view_public_profile);

    $edit_public_profile = array (
                                  'parent' => 'user-actions',
                                  'id'     => 'edit-public-profile',
                                  'title'  => __( 'Edit My Public Profile'),
                                  'href'   => "/wp-admin/post.php?post=" . $public_profile_id . "&action=edit",
                                  );
    $wp_admin_bar->add_node($edit_public_profile);

  } 

  // now add back the edit login and logout buttons
  $wp_admin_bar->add_node( $edit_login_node );
  $wp_admin_bar->add_node( $logout_node );        

  // if user has privileges, give a link to the 'all options' page
  if ( current_user_can( 'manage_options' ) )
    $wp_admin_bar->add_menu( array(
                                   'id' => 'all-settings',
                                   'parent'    => 'user-actions',
                                   'title' => 'All Settings',
                                   'href' => admin_url('options.php')
                                   ) );


}

  function uot_admin_bar_help($wp_admin_bar) {
  $wp_admin_bar->add_menu( array(
                                 'id' => 'uot-help',
                                 'parent'    => 'top-secondary', //puts it on the right-hand side
                                 'title' => 'Get Help',
                                 ) );
  //  Then add links to it
  //  this just goes to the help page, which is hidden from view if users aren't logged in.
  $wp_admin_bar->add_menu( array(
                                 'id' => 'uot-basic-help',
                                 'parent'    => 'uot-help',
                                 'title' => 'Basic Help Page',
                                 'href' => '/site-help/',
                                 )
                           );
  // to the advanced help
  $wp_admin_bar->add_menu( array(
                                 'id' => 'uot-advanced-help',
                                 'parent'    => 'uot-help',
                                 'title' => 'Advanced Help Page',
                                 'href' => '/advanced-help/',
                                 )
                           );
	// Add codex link
	$wp_admin_bar->add_menu( array(
		'parent'    => 'uot-help',
		'id'        => 'wordpress-codex',
		'title'     => __('Learn about Wordpress'),
		'href'      => __('http://codex.wordpress.org'),
	) );

  }


//add_filter('wp_head', 'multi_columns');

function uot_enqueue_scripts() {
wp_enqueue_script ("jquery.columnizer",
                   plugins_url('/columnizer-jquery-plugin/src/jquery.columnizer.js', __FILE__), // where the this file is in /someplugin/
                   array("jquery"), "1.3.1",1); 
wp_enqueue_script("jquery.columnizersetup", 
                  plugins_url('/columnizer-jquery-plugin/jquery.columnizer.wpsetup.js', __FILE__), // where the this file is in /someplugin/
                  array("jquery","jquery.columnizer"), "",1);
  
}
add_action('wp_enqueue_scripts', 'uot_enqueue_scripts'); // For use on the Front end (ie. Theme)

/* function uot_get_posts_by_taxonomy($taxonomy,$term,$post_type, $orderby, $order='ASC', $depth=3) {
 *   $Q = new GetPostsQuery();
 *   $args = array();
 *   $args['post_type'] = $post_type;
 *   $args['orderby'] = $orderby;
 *   $args['order'] = $order;
 *   $args['post_status'] = 'publish';
 *   $args['taxonomy'] = $taxonomy;
 *   $args['taxonomy_term'] = $term;
 *   $args['taxonomy_depth'] = 3;
 *   $results = $Q->get_posts($args);
 *   return $results;
 * } */

/* EOF */


// adding new funcitons that add a column to admin interface for courses

// function that adds the actual column to the interface 
// (doesn't put anything IN the column, except the title)
function add_courses_columns($columns) {
    unset($columns['author']);
    return array_merge($columns, 
              array('uot_course_section_col' => __('Sections'),));
}

// the filter calls the above function
add_filter('manage_courses_posts_columns' , 'add_courses_columns');

// This action will control what actually gets put into the sections
add_action('manage_courses_posts_custom_column', 'uot_display_sections_column', 5, 2);

// display some very basic info about the sections that belong to each course
function uot_display_sections_column($col, $id){
  switch($col){
    case 'uot_course_section_col':
      //echo "sections go here";
      $secs = find_course_sections ($id);
      foreach ($secs as $s) {
        //print_r($s);
        $linktext = "/wp-admin/post.php?post=" . $s['ID'] . "&action=edit";
        echo '<a href="' . $linktext . '">';
        echo $s['coursesection_semester'] . $s['coursesection_secnum'];
        if ($s['coursesection_summer'] == 1) {
            echo "(SUM)";
          }
        echo  '</a>, ';
      }
      //echo get_post_meta( $post_id , '' , true ); 
      echo '<a href="/wp-admin/post-new.php?post_type=coursesection">Add Section</a> ';
        break;

  }
}

// helper function to get all sections of a Course
function find_course_sections ($id) {
   $Q = new GetPostsQuery();
   $args = array();
   $args['post_type'] = 'coursesection';
   $args['orderby'] = 'coursesection_secnum';
   $args['meta_key'] = 'coursesection_parent';
   $args['post_status'] =  array('draft','publish');
   $args['meta_value'] = $id;
   $all_sections = $Q->get_posts($args);
   //return "hello";
   return $all_sections;
}