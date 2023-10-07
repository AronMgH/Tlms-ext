<?php
/*
Plugin Name: TLMSExt
Plugin URI: https://github.com/AronMgH
Description: This is a plugin to manually enroll studnets into Tutorl LMS courses. 
Version: 1.0
Author: Aron Meresa
Author URI: https://github.com/AronMgH
License: GPL2
*/


add_action('admin_menu', 'add_admin_page', 999);
function add_admin_page(){
    add_menu_page('Course Enrollment Control', 'TLMS Control', 'manage_options', 'course_access', 'display_page',    'dashicons-admin-plugins', // Icon URL or dashicon class
    888);
}

function display_page(){
    $args = array(
        'post_type' => 'courses',
        'posts_per_page' => -1, 
    );

    $courses = get_posts($args);

    echo '<div id="tlms-container">';
    echo '<div><h1>TLMS Access Control Panel </h1></div>';
    echo '<div> <span id="error-text">Please Select a course.</span> </div>';

    ob_start();
    echo '<div class="flex">';
    echo '<div class="flex">';
    echo 'Course:  <select name="course" id="course-select">';
    echo '<option value="">Select Course</option>';
    foreach ($courses as $course) {
        echo '<option value="' . $course->ID . '">' . $course->post_title . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="flex">
        <input type="text" name="search-user" id="search-user" placeholder="Filter using name"/>
        <button id="get-users-button"> Get Users </button> 
    </div>';
    echo '<div class="ml-auto"><button>Waitlist</button></div>';
    echo '</div>';
    echo '<div id="loading"><div>Loading...</div></div>'; // Add this line
    echo '<div id="student-table"></div>';
    $select_box = ob_get_clean();
    echo $select_box;

    echo '</div>';
}

add_action( 'wp_ajax_get_students', 'get_students' );
function get_students() {
    global $wpdb;
    
    $courseId = $_POST['course_id'];
    $filter = $_POST['filter'];
    $table_name = $wpdb->prefix.'access_control';


    $meta_query = array('relation' =>  'OR');
    
    array_push($meta_query, array('key'=>'display_name', 'value' => $filter, 'compare' => 'LIKE'));
    array_push($meta_query, array('key'=>'first_name', 'value' => $filter, 'compare' => 'LIKE'));
    array_push($meta_query, array('key'=>'last_name', 'value' => $filter, 'compare' => 'LIKE'));


    $students = get_users(array('meta_query' => $meta_query));

    if(empty($students)){
        echo '<div class="empty-div">No students found</div>';
        wp_die();
    }
    //start output buffering
    ob_start();

    //start the form
    echo '<form id="access-control-form" method="post" action="'.admin_url('admin-post.php').'">';
    echo '<input type="hidden" name="action" value="update_access_control">';
    echo '<input type="hidden" name="course_id" value="'.esc_attr( $courseId ).'">';
    // Start the table
    echo '<table>';
    echo '<tr><th>#</th><th>Full Name </th><th>Display Name </th> <th>Role></th><th>Switch </th></tr>';

    $counter = 1;

    foreach($students as $student) {

        $canAccess = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT canAccess FROM $table_name WHERE userid = %d AND courseid = %d",
                array($student->ID, $courseId)
            )
        );

        $checked = ($canAccess == 1) ? 'checked' : '';

        echo '<tr>';
        echo '<td>'.$counter.'</td>';
        echo '<td>'.$student->first_name.' '.$student->last_name.'</td>';
        echo '<td>'.$student->display_name.'</td>';
        echo '<td>'. implode(', ',$student->roles).'</td>';
        echo '<td><input type="hidden" name="access['.$student->ID.']" value="0">
        <label class="switch">
            <input type="checkbox" name="access['.$student->ID.']" value="1"'.$checked.'>
            <span class="slider round"></span>
        </label></td>';       
        echo '</tr>';
        $counter++;
    }

    echo '</table>';

    $table = ob_get_clean();

    echo $table;

    echo '<div id="submit-div"><button id="submit-btn" type="submit">Save</button></div>';
    echo '</form>';

    wp_die( );

}

register_activation_hook(__DIR__.'/tlms-ext.php', 'create_access_control_table' );
function create_access_control_table(){
    global $wpdb;

    $table_name = $wpdb->prefix.'access_control';
    
    if($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            userid mediumint(9) NOT NULL,
            courseid mediumint(9) NOT NULL,
            canAccess boolean DEFAULT 0 NOT NULL,
            requestAccess boolean DEFAULT 0 NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        dbDelta( $sql );
    }
}

add_action( 'admin_post_update_access_control', 'update_access_control');
function update_access_control() {
    global $wpdb;

    $table_name = $wpdb->prefix.'access_control';

    $access_data = $_POST['access'];
    $course_id = $_POST['course_id'];

    // Loop through the submitted data
    foreach($access_data as $userid=>$canAccess) {
        
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * from $table_name WHERE userid = %d  AND courseid = %d",
                array($userid, $course_id)
            )
            );

        
        if($record) {
            $wpdb->update(
                $table_name,
                array('canAccess' => $canAccess),
                array('id' => $record->id)
            );
        } else {
            $wpdb->insert(
                $table_name,
                array('userid' => $userid, 'courseid' => $course_id, 'canAccess' => $canAccess)
            );
        }
    }

    wp_redirect( admin_url( 'admin.php?page=course_access'));
    exit;
}

add_action( 'template_redirect', 'intercept_lesson_request' );
function intercept_lesson_request(){
    global $wpdb;
    //parse the current url
    $url_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Check if the URL matches the pattern for a lesson page
    if(preg_match('#^/courses/.+/lesson/.+$#', $url_path )) {
        $user_id = get_current_user_id();
        $path_parts = explode('/', trim($url_path,'/'));
        $course_title = $path_parts[1];
    
        if(!user_can_access_course($user_id, $course_title)){
            $table_name = $wpdb->prefix . 'access_control';
            
            $course_id = get_course_id_from_title($course_title);
            $wpdb->insert($table_name, array(
                'userid'=>$user_id,
                'courseid'=>$course_id,
                'canAccess'=> 0,
                'requestAccess' => 1,
                )
            );
            wp_redirect( '/request-access?course='.urlencode( str_replace('-', ' ', $course_title)));
            exit;
        }
    }
}

function user_can_access_course($user_id, $course_title) {
    global $wpdb;


    $table_name = $wpdb->prefix.'access_control';

    $course_id = get_course_id_from_title($course_title);
    $canAccess = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT canAccess FROM $table_name WHERE userid = %d  AND courseid = %d", array($user_id, $course_id)
        )
    );
    return $canAccess == 1;
}

function get_course_id_from_title($course_title){
    $args = array(
        'post_type' => 'courses',
        'posts_per_page' => -1,
    );

    $courses = get_posts($args);

    foreach($courses as $course){
        $course_post_title = strtolower(str_replace(' ', '-', $course->post_title ));
        if($course_post_title == $course_title){
            return $course->ID;
        }
    }

    return 0;
}

add_action( 'wp_dashboard_setup', 'register_access_request_widget' );
function register_access_request_widget() {
    wp_add_dashboard_widget( 'access_request_widget', 'Course Access Request ', 'display_access_request_widget' );
}

function display_access_request_widget(){
    global $wpdb;

    $table_name = $wpdb->prefix . 'access_control';

    $requests = $wpdb->get_results("SELECT * FROM $table_name WHERE requestAccess = 1");

    if($requests){
        echo "<table>";
        echo "<tr><th>Fullname</th><th>Display Name</th><th>Course</th><th>Options</th></tr>";
        foreach ($requests as $request) {
            $user_info = get_user_data($request->userid);
            $fullname = $user_info->first_name . " " . $user_info->last_name;
            $display_name = $user_info->display_name;
            $course_id = $request->courseid

            $course_title = get_the_title($course_id);

            echo "<tr><td>{$fullname}</td><td>{$display_name}</td><td>{$course_title}</td><td><button class='close_request' data-request-id=\"{$request->id}\">Close Request</button></td></tr>";
        }
        echo "</table>";
    } else {
        echo '<div class="empty-div"> No requests found.</div>';
    }


}

add_action('wp_ajax_close_course_access_request', 'close_course_access_request');
function close_course_access_request(){
    global $wpdb;

    $table_name = $wpdb->prefix . 'access_control';
    $request_id = $_POST['request_id'];

    $wpdb->update($table_name, array('requestAccess' => 0), array('id'  => $request_id));
    wp_die();
}
function enqueue_my_plugin_scripts(){
    wp_enqueue_script( 'tlms-ext-plugin-script', plugins_url( 'fetch-users.js', __FILE__ ), array('jquery'), '1.0', true);
    wp_localize_script( 'tlms-ext-plugin-script', 'Tlms-Ext', array('ajaxurl' => admin_url( 'admin-ajax.php' )) );
}

add_action( 'admin_enqueue_scripts', 'enqueue_my_plugin_scripts');
function enqueue_my_plugin_styles(){
    wp_enqueue_style( 'tlm-ext-plugin-style', plugins_url( 'tlms-ext.css', __FILE__ ) );
}

add_action( 'admin_enqueue_scripts', 'enqueue_my_plugin_styles' );

?>