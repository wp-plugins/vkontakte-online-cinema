<?php

if (!function_exists('evc_vk_video_get')) :
function evc_vk_video_get($params = array()) {
  $options = apply_filters('evc_vk_video_get_options', '');
  $options = get_option('vkwpv_vk_api');
  $default = array(
    'access_token' => $options['site_access_token'],
    //'owner_id' => ,
    //'videos' => '-1234_136089719,112455770_137352259',
    //'album_id' => '',
    'width' => 160, // 160 // 130, 160, 320    
    'extended' => 1, // 0
    //'count' => 10, // 100 // 200
    //'offset' => 0, //0 // 1
    'v' => '5.20'
  );
  $params = wp_parse_args($params, $default);
  $params = apply_filters('evc_vk_video_get_query', $params);     
 
  $query = http_build_query($params);
  
  // VK API REQUEST
  $data = wp_remote_get(VK_API_URL.'video.get?'.$query, array(
    'sslverify' => false
  ));  
  
  //print__r($data); //
  if (is_wp_error($data)) {
    vkwpv_add_log('evc_vk_video_get: WP ERROR. ' . $data->get_error_code() . ' '. $data->get_error_message());
    return false;
  }
  
  $resp = json_decode($data['body'],true);
  
  if (isset($resp['error'])) {    
    if (isset($resp['error']['error_code']))
      vkwpv_add_log('evc_vk_video_get: VK Error. ' . $resp['error']['error_code'] . ' '. $resp['error']['error_msg']); 
    else
      vkwpv_add_log('evc_vk_video_get: VK Error. ' . $resp['error']);           
    return false; 
  } 
  
  return $resp['response']; 
}
endif;

if (!function_exists('evc_vk_groups_get')) :
function evc_vk_groups_get($params = array(), $option_name = null) {
  $options = get_option($option_name);
  if (!isset($options['access_token'])){
    if (isset($options['site_access_token']) && !empty($options['site_access_token']))
      $options['access_token'] = $options['site_access_token'];
    else
      return false;
  }
    
  $default = array(
    'access_token' => $options['access_token'],
    //'user_id' => , // current user
    'extended' => 1, // 0 // 0, 1
    //'filter' => '', // admin, editor, moder, groups, publics, events
    //'fields' => '', // city, country, place, description, wiki_page, members_count, counters, start_date, end_date, can_post, can_see_all_posts, activity, status, contacts, links, fixed_post, verified, site, can_create_topic
    
    'count' => 100, // 1000
    //'offset' => 0, //0 // 1
    'v' => '5.20'
  );
  $params = wp_parse_args($params, $default);
  $params = apply_filters('evc_vk_groups_get', $params);     
 
  $query = http_build_query($params);
  
  // VK API REQUEST
  $data = wp_remote_get(VK_API_URL.'groups.get?'.$query, array(
    'sslverify' => false
  ));  
  
  //print__r($data); //
  if (is_wp_error($data)) {
    vkwpv_add_log('evc_vk_groups_get: WP ERROR. ' . $data->get_error_code() . ' '. $data->get_error_message());
    return false;
  }
  
  $resp = json_decode($data['body'],true);
  
  if (isset($resp['error'])) { 
    
    if (isset($resp['error']['error_code']))
      vkwpv_add_log('evc_vk_groups_get: VK Error. ' . $resp['error']['error_code'] . ' '. $resp['error']['error_msg']); 
    else
      vkwpv_add_log('evc_vk_groups_get: VK Error. ' . $resp['error']);           
    return false; 
  } 
  return $resp['response']; 
}
endif;

if (!function_exists('evc_get_wpid_by_vkid')) :
function evc_get_wpid_by_vkid ($id, $type = 'post') {
  global $wpdb;
  
  $id_s = implode("','", (array)$id);  

  $res = $wpdb->get_results("
    SELECT ".$type."_id, meta_value
    FROM ".$wpdb->prefix.$type."meta
    WHERE meta_key = 'vk_item_id' AND meta_value IN ('$id_s')
  ");    

  if (empty($res))
    return false;
  
  $postfix = '_id';
  foreach($res as $r)
    $out[$r->meta_value] = $r->{$type . $postfix}; 
  
  return $out;
}
endif;

if (!function_exists('vkwpv_get_all_options')) :
function vkwpv_get_all_options ($options) {
  $options = apply_filters('vkwpv_get_all_options', $options);
  if (empty($options))
    return array();
  $out = array();
  foreach($options as $option) {
    $values = get_option($option);
    if ($values && !empty($values))
      $out += $values;
  }
  return $out;
}
endif;

if (!function_exists('evc_update_post_metas')) :
function evc_update_post_metas ($pm, $post_id) {
  if (!isset($pm) || empty($pm) )
    return false;
    
  foreach($pm as $pm_key => $pm_value)
    update_post_meta($post_id, $pm_key, $pm_value);  
}
endif;

if (!function_exists('print__r')) :
  function print__r ($data) {
    print '<pre>' . print_r($data, 1) . '</pre>';
  }
endif;

if (!function_exists('evc_video_vk_url_filter')) :
function evc_video_vk_url_filter ($url) {
  $url = trim($url);
  
  $urla = explode ('album', $url);

   if (!isset($urla[1]) || empty($urla[1]))
    return false;
  
  $data = explode('_', $urla[1]);

  if (count($data) < 2)
    return false;
  
  $out = array('owner_id' => $data[0], 'album_id' => $data[1]);
  
  return $out;
}
endif;


if (!function_exists('vkwpv_add_log')) :
function vkwpv_add_log ($event = '') {
  
  $gmt = current_time('timestamp', 1);
  // local time
  $date = gmdate('Y-m-d H:i:s', current_time('timestamp'));
  
  if (false === ($evc_log = get_transient('vkwpv_log')))
    $evc_log = array();

  $out = $date . ' ' . $event;
  
 if (count($evc_log) > 100)
    $evc_log = array_slice($evc_log, -99, 99);  
  
  array_push($evc_log, $out);
  set_transient('vkwpv_log', $evc_log, YEAR_IN_SECONDS);  
}
endif;

if (!function_exists('vkwpv_get_log')) :
function vkwpv_get_log ($lines = 50) {
  if (false === ( $logs = get_transient('vkwpv_log')) )
    return 'No logs yet.';

  if (is_array($logs)) {
    krsort($logs);    
    $logs = array_slice($logs, 0, $lines);
  }
  
  return print_r($logs,1);
}
endif;

if (!function_exists('vkwpv_the_log')):
function vkwpv_the_log ($lines = 50, $separator = '<br/>') {
  if (false === ( $logs = get_transient('vkwpv_log')) )
    return 'No logs yet.';
  
  if (is_array($logs)) {
    krsort($logs);    
    $logs = array_slice($logs, 0, $lines);
  }
  
  $out = array();
  $i = 0;
  foreach($logs as $log) {
    if ($i%10 == 0)
      $out[] = '';
      
    $out[] = $log;
    $i++;
  }
   
  if (!empty($out))
    $out = implode($separator, $out);
  
  return $out;
}
endif;
/*
*   Images
*/

/*
'post' => array()$post, post_date

'file_name' => $file_name[$k],
'url' => $image_url[$k]
*/

if (!function_exists('evc_fetch_remote_file')) :
function evc_fetch_remote_file($args) {
  if (!empty($args))
    extract($args);

  //$post_date = date('Y-m-d H:i:s');
  $upload = wp_upload_dir();
  $upload = wp_upload_bits( $file_name, 0, '');

  if ( $upload['error'] ) {
    vkwpv_add_log('evc_fetch_remote_file #1');
    return new WP_Error( 'upload_dir_error', $upload['error'] );
  }
  //vkwpv_get_log($url);
  $headers = wp_get_http($url, $upload['file']);

  if ( !$headers ) {
    vkwpv_add_log('import_file_error: May be https...');
    $url = str_replace('http', 'https', $url);
    $headers = wp_get_http($url, $upload['file']);
       
    if ( !$headers ) {
      @unlink($upload['file']);
      vkwpv_add_log('evc_fetch_remote_file #2');
      return new WP_Error( 'import_file_error', __('Remote server did not respond', 'evc') );
    }
  }

  if ( $headers['response'] != '200' ) {
    @unlink($upload['file']);
    vkwpv_add_log('evc_fetch_remote_file #3');
    return new WP_Error( 'import_file_error', sprintf(__('Remote server says: %1$d %2$s', 'evc'), $headers['response'], get_status_header_desc($headers['response']) ) );
  }
  elseif ( isset($headers['content-length']) && filesize($upload['file']) != $headers['content-length'] ) {
    @unlink($upload['file']);
    vkwpv_add_log('evc_fetch_remote_file #4');
    return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'evc') );
  }

  $max_size = (int)get_site_option('fileupload_maxk')*1024;

  // fileupload_maxk for wpmu compatibility 
  $file_size= filesize($upload['file']);

  if ( !empty($max_size) && $file_size > $max_size ) {
    @unlink($upload['file']);
    vkwpv_add_log('evc_fetch_remote_file #5');
    return new WP_Error( 'import_file_error', sprintf(__('Remote file is %1$d KB but limit is %2$d', 'evc'), $file_size/1024, $max_size/1024) );
  }

  // This check is for wpmu compatibility
  if ( function_exists('get_space_allowed') ) {
    $space_allowed = 1048576 * get_space_allowed();
    $space_used = get_dirsize( BLOGUPLOADDIR );
    $space_left = $space_allowed - $space_used;

    if ( $space_left < 0 ) {
      @unlink($upload['file']);
      vkwpv_add_log('evc_fetch_remote_file #6');
      return new WP_Error( 'not_enough_diskspace', sprintf(__('You have %1$d KB diskspace used but %2$d allowed.', 'evc'), $space_used/1024, $space_allowed/1024) );
    }
  }

  $upload['content-type'] = $headers['content-type'];
  return $upload;
}
endif;

// array($url, $title, $post_parent)
/*
$a = array(
  'img' => ''
);

*/
if (!function_exists('evc_save_remote_attachment')) :
function evc_save_remote_attachment ($a, $post_parent= null, $title = '', $obj = false) {    
  $options = get_option('vkwpv'); 
  
  // Create Img Filename
  $pi = pathinfo($a['img']);
  $filename = $pi['basename'];
  // print__r($pi);  
  // Create Img
  $params = array(
    //'post_date' => $post_date, 
    'file_name' => $filename,
    'url' => $a['img']
  );
  $img = evc_fetch_remote_file($params);   
  if ( is_wp_error($img)) {
    // print '<p>'. $img->get_error_message() . '</p>';
    return false;
  }
    
  $url = $img['url'];
  $type = $img['content-type'];
  $file = $img['file'];  

  $att= array(
    'post_author' => $options['vkwpv_user_id'],
    'post_status'=>'publish', 
    'ping_status' => 'closed', 
    'guid'=> $url, 
    'post_mime_type'=>$type
  );
  
  if (isset($post_parent) && $obj != 'user') 
    $att['post_parent'] = $post_parent;  
  
  if (isset($a['title'])) 
    $att['post_title'] = $a['title'];
  else
    $att['post_title'] = $title;
  
  if (isset($a['text'])) 
    $att['post_content'] = $a['text'];
    
  if (isset($a['description']))
    $att['post_content'] = $a['description'];
        
  $att = apply_filters('evc_save_remote_attachment', $att);  
  
  $att_ID= wp_insert_attachment($att);

  if ( !$att_ID ) {
    //print "<p>Can not create attachment for $img[file]</p>";
    return false;
  }
  
  if (!function_exists('wp_generate_attachment_metadata'))
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
  $attachment_metadata = wp_generate_attachment_metadata($att_ID, $file);
  
  wp_update_attachment_metadata($att_ID, $attachment_metadata);
  update_attached_file($att_ID, $file);  
  
  // Update Attachment Meta For POsts And Comments
  if (isset($a['type'])) {
    $meta = array(
      //'vk_item_id' => $a['id'],
      'vk_type' => $a['type'],
      'vk_owner_id' => $a['owner_id'],
      'vk_access_key' => $a['access_key'],
    );
  }
  if (isset($a['vk_player']))
    $meta['vk_player'] = $a['vk_player'];
  if (isset($a['duration']))
    $meta['vk_duration'] = $a['duration'];    
  
  if ( $obj != 'user') 
    $meta['vk_item_id'] = $a['vk_item_id'];
      
  evc_update_post_metas($meta, $att_ID);     
  
  // Update Attachment Meta For Users
  if ($obj == 'user')
    update_user_meta($post_parent, $a['key'], $att_ID);    
  
  if (isset($a['key']) && $obj != 'user')
    update_post_meta($post_parent, $a['key'], $att_ID);    
  
  do_action('evc_save_remote_attachment_action', $a, $att_ID, $att, $obj);      
    
  return $att_ID;  
}
endif;

add_action( 'evc_save_remote_attachment_action', 'evc_set_post_thumbnail', 10, 4 );
if (!function_exists('evc_set_post_thumbnail')) :
function evc_set_post_thumbnail ($a, $att_id, $att, $obj) {
  $options = get_option('vkwpv'); // apply_filters('evc_set_post_thumbnail_options', array());//get_option('vkwpv'); 
  if (isset($options['post_thumbnail']['on']) && $obj != 'user' && isset($att['post_parent']) && !has_post_thumbnail($att['post_parent'])) {
    set_post_thumbnail( $att['post_parent'], $att_id );
  }
}
endif;

if (!function_exists('evc_get_vk_imgs')) :
function evc_get_vk_imgs ($type = 'post', $limit = 10) {
  global $wpdb;
  
  $l = '';
  if ($limit)
    $l = "LIMIT ".$limit;
  
  $res = $wpdb->get_results("
    SELECT ".$type."_id, meta_value
    FROM ".$wpdb->prefix.$type."meta
    WHERE meta_key = 'vk_img'
    ORDER BY ".$type."_id ASC
    ".$l."
  ");    
  
  //vkwpv_get_log('evc_get_vk_imgs:' . print_r($res,1)); 
  if (empty($res))
    return false;
  
  return $res;
}
endif;

add_action('wp_ajax_evc_refresh_vk_img', 'evc_refresh_vk_img_js');
if (!function_exists('evc_refresh_vk_img_js')) :
function evc_refresh_vk_img_js() {
  
  $r = evc_refresh_vk_img_all();
  
  if (isset($r['error']))
    $out['error'] = 'Error';
  else
    $out = $r;

  print json_encode($out);
  exit;    
}
endif;

if (!function_exists('evc_refresh_vk_img')) :
function evc_refresh_vk_img ($type, $limit = 50) {
  $postfix = '_id';
 
  $vk_imgs = evc_get_vk_imgs($type, $limit);
  //print__r ($vk_imgs);
  if (!$vk_imgs)
    return false;
  
  $i = 0;    
  foreach($vk_imgs as $vk_img) {     
    
  //print__r(maybe_unserialize($vk_img->meta_value));
    $att_id = evc_save_remote_attachment( maybe_unserialize($vk_img->meta_value), $vk_img->{$type . $postfix}, '', $type );
    //print '$att_id = ' . $att_id;
    if ($att_id) {
      call_user_func('delete_' . $type .'_meta', $vk_img->{$type . $postfix}, 'vk_img', maybe_unserialize($vk_img->meta_value));
      $i++;
    }
  }
  return $i;  
}
endif;

if (!function_exists('evc_refresh_vk_img_all')) :
function evc_refresh_vk_img_all () {
  $options = get_option('vkwpv'); 
  
  $ipost = evc_get_vk_imgs('post', 0);
  $iuser = evc_get_vk_imgs('user', 0);
  
  $ipost = !$ipost ? 0 : count($ipost);
  $iuser = !$iuser ? 0 : count($iuser);
  
  $r = 0;  
  $out = array();
  $options['img_refresh'] = apply_filters('evc_img_refresh', $options['img_refresh']);

  if ($ipost) {
    $r = evc_refresh_vk_img('post', $options['img_refresh']);
    if (!$r) {
      $out['error'] = 'Error';
      $r = 0;
    }
  }
  else
    $ipost = 0;
  
  if ( $iuser && ( ($r && $r < $options['img_refresh']) || !$r ) ) {
    if ( ($r && $r < $options['img_refresh'])  )
      $r += evc_refresh_vk_img('user', $options['img_refresh'] - $r);
    elseif (!$r)
      $r = evc_refresh_vk_img('user', $options['img_refresh']);
    if (!$r) {
      $out['error'] = 'Error';
      $r = 0;
    }      
  }
   
  $out['refresh'] = $r;
  $out['left'] = $ipost + $iuser - $r;
  //$out['left'] = count((array)$ipost) .' ' . count((array)$iuser) . ' ' . $r;
  
  vkwpv_add_log('evc_refresh_vk_img_all: Refresh: '.$out['refresh'].'. Left: ' . $out['left']. '.');
  
  return $out;  
}
endif;

if (!defined('EVC_TOKEN_URL'))
  define('EVC_TOKEN_URL', 'https://oauth.vk.com/access_token');
if (!defined('EVC_AUTORIZATION_URL'))
  define('EVC_AUTORIZATION_URL', 'https://oauth.vk.com/authorize');
if (!function_exists('vkwpv_share_vk_login_url')) :
function vkwpv_share_vk_login_url ($redirect_url = false, $echo = false) {
  //$options = get_option('evc_options');
  $options = vkwpv_get_all_options(array(
    'vkwpv_vk_api'
  ));  
  
  if (!$redirect_url) {
    $redirect_url = remove_query_arg( array('code', 'redirect_uri', 'settings-updated'), $_SERVER['REQUEST_URI'] );
    $redirect_url = site_url($redirect_url);
  }

  $params = array(
    'client_id' => trim($options['site_app_id']),
    'redirect_uri' => $redirect_url,
    'display' => 'page',
    'response_type' => 'code',
    'scope' => apply_filters('vkwpv_app_scope', 'video,friends,offline') //
  );
  $query = http_build_query($params);  
  
  $out = EVC_AUTORIZATION_URL . '?' . $query;
  
  if ($echo)
    echo $out;
  else
    return $out;
}
endif;

add_action('admin_init', 'vkwpv_share_vk_autorization');  
if (!function_exists('vkwpv_share_vk_autorization')) :
function vkwpv_share_vk_autorization () {
  
  if ( false !== ( $token = vkwpv_share_get_token() ) ) {
    $options = get_option('vkwpv_vk_api');
    
    if (isset($token['access_token']) && !empty($token['access_token'])) {
      $options['site_access_token'] = $token['access_token'];
      update_option('vkwpv_vk_api', $options);
    }
    $redirect = remove_query_arg( array('code'), $_SERVER['REQUEST_URI'] );  
    //print__r($redirect);
    wp_redirect(site_url($redirect));
    exit;
  }
   
}  
endif;

if (!function_exists('vkwpv_share_get_token')) :
function vkwpv_share_get_token () {
  $options = get_option('vkwpv_vk_api');    
  
  if (isset($_GET['code']) && !empty($_GET['code'])) {
   
    $_SERVER['REQUEST_URI'] = remove_query_arg( array('code'), $_SERVER['REQUEST_URI'] );   
      
    $params = array(
      'client_id' => trim($options['site_app_id']),
      'redirect_uri' =>  site_url($_SERVER['REQUEST_URI']),
      'client_secret' => $options['site_app_secret'],
      'code' => $_GET['code']
    );
    $query = http_build_query($params);      
    //print__r($query); //
    
    $data = wp_remote_get(EVC_TOKEN_URL.'?'.$query, array(
      'sslverify' => false
    ));
    //print__r($data); //
    //exit; 
    if (is_wp_error($data)) {
      //print__r($data); //
      //exit;
      return false;
    }
  
    $resp = json_decode($data['body'],true);
    //print__r($resp);
    //exit;
    if (isset($resp['error'])) {
      return false; 
    }
      
    return $resp;  
  }
  return false;  
}
endif;