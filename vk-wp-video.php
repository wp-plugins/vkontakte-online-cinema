<?php
/*
Plugin Name: VKontakte Online Cinema
Description: Импорт видеозаписей из групп пользователя.
Plugin URI: http://ukraya.ru/
Version: 1.0.5
Author: Aleksej Solovjov
Author URI: http://ukraya.ru
*/

// 2014-09-17

// Constants
if (!defined('VK_API_URL'))
  define('VK_API_URL','https://api.vk.com/method/');
include_once('inc/evc-api.php'); 

function vkwpv_get_vk_new_posts ($new) {
  
  $old = get_transient('vk_videos');

  $out = array();
  foreach($new['items'] as $post) {   
    if (!$old || ($old && !isset($old['items'][$post['id']])) )
      $out[] = $post['id'];
  }  
    
  return $out;  
}

function vkwpv_refresh ($data = array()) {
  $options = get_option('vkwpv'); 

  evc_add_log('vkwpv_refresh: Start Refresh...');
  
  $options['refresh_count'] = $options['refresh_count']  > 200 ? 200 : $options['refresh_count'];
  
  // VK API    
  $defaults = array(
    'count' => $options['refresh_count'], 
    'offset' => $options['refresh_offset_count']
  ); 
  
  if (isset($data['owner_id'])) {
    if ($data['owner_id'] == 'my')
      unset ($data['owner_id']);
  }
  else {
    if (isset($options['owner_id']) && !empty($options['owner_id']))
      $data['owner_id'] = $options['owner_id'];
    else {
      return false;
    }
  }
  $data = apply_filters('vkwpv_refresh_data', $data, $options);
  if ($data === false)
    return false;
  
  $args = wp_parse_args($data, $defaults);
  $res = evc_vk_video_get($args);
  //print__r($res); //
  if (!$res) {
    return false;
  }
  //return;
  $added = vkwpv_add_posts($res);
  
  // Upload Images
  evc_refresh_vk_img_all();
  
  //evc_bridge_add_log('wall' . print_r($wall, 1));   //
  set_transient( 'vk_videos', $res, 26*HOUR_IN_SECONDS );  
  
  evc_add_log('vkwpv_refresh: Refresh End.');
  return $added;
}

add_action('wp_ajax_vkwpv_refresh_js', 'vkwpv_refresh_js');
function vkwpv_refresh_js() {
  
  if(!empty($_POST))
    extract($_POST);  
  
  if (!isset($owner_id))  
    $out['error'] = 'Error';
  else {
    $args['owner_id'] = $owner_id;

    if (isset($refresh_count))
      $args['count'] = $refresh_count;
    if (isset($refresh_offset_count))
      $args['offset'] = $refresh_offset_count;
      
    $args = apply_filters('vkwpv_refresh_js_args', $args);
    $posts = vkwpv_refresh($args);
    if ($posts === false)
      $out['error'] = 'Error';

    else {
      $out = array('success' => true, 'posts' => $posts);
    }
  }
  print json_encode($out);
  exit;    
}

function vkwpv_get_vk_items_ids ($data) {
  
  $out = array();
  foreach($data['items'] as $post) {
    $out['vk'][] = $post['owner_id'] . '_' . $post['id'];
  }  
  
  $out['vk'] = isset($out['vk']) ? $out['vk'] : array();
  // Check WP post_id for VK post_id   
  $out['wp'] = evc_get_wpid_by_vkid($out['vk'], 'post');
  if (!$out['wp'])
    return $out;
  
  $out['vk'] = array_diff($out['vk'], array_keys($out['wp']));
  
  return $out;   
}

// $d = evc_vk_get_wall();
function vkwpv_add_posts ($d) {
  $options = get_option('vkwpv');   
  
  // get post_id from each post
  $ids = vkwpv_get_vk_items_ids($d);
  //evc_add_log('vkwpv_get_vk_items_ids: ' . print_r($ids,1));//
  $i = 0;
  foreach($d['items'] as $post) {   
    // If New Post
    if (isset($ids['vk']) && in_array($post['owner_id'] . '_' . $post['id'], $ids['vk'])) {
      
      $filters = apply_filters('vkwpv_add_posts_filters', false, $options, $post);
      if ($filters)
        continue;
              
      vkwpv_add_post($post);
      $i++;
    }
    
    // Update Post
    if (isset($ids['wp']) && isset($ids['wp'][$post['owner_id'] . '_' . $post['id']])) {
      $pm['vk_likes'] = $post['likes']['count'];
      $pm['vk_comments'] = $post['comments'];  
      evc_update_post_metas($pm, $ids['wp'][$post['owner_id'] . '_' . $post['id']]);
    }
  }
  
  if ($i)
    evc_add_log('vkwpv_add_posts: ' . $i . ' new posts added');
  else
    evc_add_log('vkwpv_add_posts: No new posts added');    
  return $i; 
}

function vkwpv_add_post($post) {
  global $user_ID;
  $options = get_option('vkwpv');
     
  $post_date = date('Y-m-d H:i:s', $post['date'] + (get_option('gmt_offset') * 3600));
  
  if (!isset($options['vkwpv_user_id']) || empty($options['vkwpv_user_id']))
    $options['vkwpv_user_id'] = 1;
  
  if (!isset($post['title']) || empty($post['title'])) 
    $post['title'] = $post['id'];
      
  $new_post = array(
    'post_title' => $post['title'],
    'post_content' => $post['description'],
    'post_date' => $post_date,
    'post_author' => $options['vkwpv_user_id']
  ); 
  
  if (in_array($options['post_status'], array('publish', 'draft')))
    $new_post['post_status'] = $options['post_status'];
  else
    $new_post['post_status'] = 'publish';
    
  // Filter
  $new_post = apply_filters('vkwpv_add_post', $new_post, $post);
  
  if (empty($new_post))
    return false;
  
  $post_ID = wp_insert_post($new_post);
  if (!$post_ID)
    return false;
  
  do_action('vkwpv_after_post_add', $post_ID, $post);
  
  update_post_meta($post_ID, 'vk_item_id', $post['owner_id'] . '_' . $post['id']);  
  
  $meta = array();
      
  $meta['img'] = $post['photo_' . $options['width']];
  $meta['vk_id'] = $post['id'];
  $meta['vk_item_id'] = 'video'.$post['owner_id'] . '_' . $post['id'];  
  
  $meta['title'] = $post['title'];
  $meta['description'] = trim($post['description']);  
  $meta['duration'] = $post['duration'];
  $meta['vk_player'] = $post['player'];  
  
  $meta = apply_filters('vkwpv_add_post_image_meta', $meta, $post, $post_ID);
  if (isset($meta) && !empty($meta))
    add_post_meta($post_ID, 'vk_img', $meta );
      
  add_post_meta($post_ID, 'vk_img', $meta );     
    
  return $post_ID;
}

add_filter ('vkwpv_add_post', 'vkwpv_add_post_insert_terms', 10, 2 );
function vkwpv_add_post_insert_terms($data, $w) {
  $options = get_option('vkwpv'); 
   
  if ($options['parent_category']) {
    $new_post['tax_input'] = array(
      'category' => $options['parent_category']
    );    
  }  
  
  $new_post = wp_parse_args($new_post, $data);      
  //vkwpa_add_log('vkwpb_albums_add_post_insert_terms: after' . print_r($new_post,1 )); 
  return $new_post;  
}

function vkwpv_get_groups () {
  $out['my'] = 'Мои видеозаписи'; 
  
  if (false === ($groups = get_transient('vkwpv_my_groups'))) {
    $groups = evc_vk_groups_get(array('filter' => 'admin'), 'vkwpv_vk_api');
    
    if ($groups && isset($groups['count']) && $groups['count'] > 0)
      set_transient('vkwpv_my_groups', $groups, 6 * HOUR_IN_SECONDS); 
  }

  if (!$groups || !$groups['count']) {
    $out = array('0' => 'Групп нет или не удалось получить список');
  }
  else {
    
    foreach($groups['items'] as $group) {
      $dots = (mb_strlen($group['name']) > 50) ? '...' : '';
      $out[-1 * $group['id']] = trim(mb_substr($group['name'], 0, 50)) . $dots;
    }
  }

  return $out;
}




/*
*   ADMIN AREA
*/

if (!class_exists('VKWPV_Settings_API_Class'))
  include_once('inc/wp-settings-api-class.php'); 

function vkwpv_init() {
  global $vkwpv;
  
  $vkwpv = new VKWPV_Settings_API_Class;
  $options = vkwpv_get_all_options(array(
    'vkwpv',
    'vkwpv_vk_api'
  ));
  
  $tabs = array(
    'vkwpv_vk_api' => array(
      'id' => 'vkwpv_vk_api',
      'name' => 'vkwpv_vk_api',
      'title' => __( 'VK API', 'evc' ),
      'desc' => __( '', 'evc' ),
      'sections' => array(
        'vkwpv_vk_api_section' => array(
          'id' => 'vkwpv_vk_api_section',
          'name' => 'vkwpv_vk_api_section',
          'title' => __( 'Настройки VK API', 'evc' ),
          'desc' => __( 'Настройки, необходимые для взаимодействия с API ВКонтакте.', 'evc' ),      
        )
      )
    ),     
    'vkwpv' => array(
      'id' => 'vkwpv',
      'name' => 'vkwpv',
      'title' => __( 'Импорт видео', 'vkwpv' ),
      'desc' => __( '', 'vkwpv' ),
      'sections' => array(
        'vkwpv_section' => array(
          'id' => 'vkwpv_section',
          'name' => 'vkwpv_section',
          'title' => __( 'Настройки импорта видео из ВКонтакте', 'vkwpb' ),
          'desc' => __( 'Укажите урл страницы с видеозаписями и нажмите кнопку "Импортировать сейчас". После создания постов, необходимо нажать кнопку "Загрузить фото", чтобы сохранить на сервере обложки видео. Чтобы изменить настройки по умолчанию, внесите необходимые изменения и нажмите "Сохранить".', 'vkwpv' ),          
        )
      )
    ),
    'vkwpv_log' => array(
      'id' => 'vkwpv_log',
      'name' => 'vkwpv_log',
      'title' => __( 'Лог', 'vkwpv' ),
      'desc' => __( 'Log Settings', 'vkwpv' ),
      'submit_button' => false,
      'sections' => array(
        
        'vkwpv_log_section' => array(
          'id' => 'vkwpv_log_section',
          'name' => 'vkwpv_log_section',
          'title' => __( 'Лог действий плагина', 'vkwpv' ),
          'desc' => __( '<pre>' . evc_get_log(100) . '</pre>', 'vkwpv' ),          
        )
      )
    )
  );  
  
  $tabs = apply_filters('vkwpv_tabs', $tabs, $tabs);
  
   
  $url = site_url();
  $url_arr = explode(".", basename($url));
  $domain = $url_arr[count($url_arr)-2] . "." . $url_arr[count($url_arr)-1];
  
  $vkwpv_site_app_id_desc = '<p>Чтобы получить доступ к <b>API ВКонтакте</b>, вам нужно <a href="http://vk.com/editapp?act=create" target="_blank">создать приложение</a> со следующими настройками:</p>
  <ol>
    <li><strong>Название:</strong> любое</li>
    <li><strong>Тип:</strong> Веб-сайт</li>
    <li><strong>Адрес сайта:</strong> ' . $url .'</li>
    <li><strong>Базовый домен:</strong> '. $domain .'</li>
  </ol>
  <p>Если приложение с этими настройками у вас было создано ранее, вы можете найти его на <a href="http://vk.com/apps?act=settings" target="_blank">странице приложений</a> и, затем нажмите "Редактировать", чтобы открылись его параметры.</p>
  <p>В полях ниже вам нужно указать: <b>ID приложения</b> и его <b>Защищенный ключ</b>.</p>';   
   
  $vkwpv_site_get_access_token_url = (!empty($options['site_app_id'])) ? vkwpv_share_vk_login_url() : 'javascript:void(0);';
        
  $vkwpv_site_access_token_desc = '<p>Чтобы получить <strong>Access Token</strong>:</p>
  <ol>
    <li>Пройдите по <a href="'.$vkwpv_site_get_access_token_url.'" id = "getaccesstokenurl">ссылке</a></li>
    <li>Подтвердите уровень доступа.</li>
  </ol>';     
  
   
  $fields = array(
    'vkwpv_vk_api_section' => array(
      array(
        'name' => 'site_app_id_desc',
        'desc' => __( $vkwpv_site_app_id_desc, 'vkwpv' ),
        'type' => 'html',
      ), 
      array(
        'name' => 'site_app_id',
        'label' => __( 'ID приложения', 'vkwpv' ),
        'desc' => __( 'ID вашего приложения VK.', 'vkwpv' ),
        'type' => 'text'
      ), 
      array(
        'name' => 'site_app_secret',
        'label' => __( 'Защищенный ключ', 'vkwpv' ),
        'desc' => __( 'Защищенный ключ вашего приложения VK.', 'vkwpv' ),
        'type' => 'text'
      ),       
    ),    
   'vkwpv_section' => array(
     array(
        'name' => 'owner_id',
        'label' => __( 'Источник видео', 'vkwpv' ),
        'desc' => __( 'Выберите группу из которой хотите импортировать видео. При выборе "Мои видеозаписи" - будут импортированы видеозаписи, которые вы добавили себе на стену.', 'vkwpv' ),
        'type' => 'select',
        'default' => 'my',
        'options' => vkwpv_get_groups()         
      ),       
      array( 
        'name' => 'url',
        'label' => __( 'Урл страницы', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Например: <code>http://vk.com/videos-#####</code> или <code>http://vk.com/videos-####?section=album_####</code>. Вместо #### идут цифры.
        <br/>Если указан урл, то опция "Источник видео" игнорируется.', 'vkwpv' ),
        'type' => 'text',
        'readonly' => true 
      ),
      array(
        'name' => 'refresh_count',
        'label' => __( 'Загружать видео', 'vkwpv' ),
        'desc' => __( 'Сколько видеозаписей загружать, но не более <code>200</code>.', 'vkwpv' ),
        'type' => 'text',
        'default' => '10',
      ),       
      array(
        'name' => 'refresh_offset_count',
        'label' => __( 'Пропустить видео', 'vkwpv' ),
        'desc' => __( 'Сколько видеозаписей от начала пропустить.', 'vkwpv' ),
        'type' => 'text',
        'default' => '0',
      ),             
      array(
        'name' => 'vkwpv_refresh_button',
        'desc' => __( '<p>' . get_submit_button('', 'primary', '', false) .  '</p><p>' . get_submit_button('Импортировать сейчас', 'secondary', 'vkwpv_refresh', false) . '&nbsp;&nbsp;' . get_submit_button('Загрузить фото', 'secondary', 'vkwpv_refresh_vk_img', false) . '&nbsp;&nbsp;' . get_submit_button('Очистить кэш', 'secondary', 'vkwpv_clear_cache', false) .  '<span id="vkwpv_refresh[spinner]" style="display: none; float:none !important; margin: 0 5px !important;" class="spinner"></span></p>', 'vkwpv' ),
        'type' => 'html'
      ),      
     array(
        'name' => 'img_refresh',
        'label' => __( 'Загружать изображений', 'vkwpv' ),
        'desc' => __( 'Сколько изображений (обложки видео) загружать в одном пакете. Пакет изображений загружается на сайт при нажатии кнопки "Загрузить фото".
        <br/>Для слабых серверов установите значение от <code>10</code>. Для сильных можно увеличить до любого значения начиная со <code>100</code> и более.', 'vkwpv' ),
        'type' => 'text',
        'default' => '30',
      ),        
      
      
      array(
        'name' => 'duration_min',
        'label' => __( 'Длительность видео', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/><b>Минимальная</b> продолжительность видео в секундах. Видеозаписи, которые длятся <b>меньше</b> добавлены не будут. Например: <code>30</code>.
        Оставьте пустым, чтобы не учитывать.', 'vkwpv' ),
        'type' => 'text',
        'readonly' => true 
      ),    
      array(
        'name' => 'duration_max',
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/><b>Максимальная</b> продолжительность видео в секундах. Видеозаписи, которые длятся <b>больше</b> добавлены не будут. Например: <code>3600</code>.
        Оставьте пустым, чтобы не учитывать.', 'vkwpv' ),
        'type' => 'text',
        'readonly' => true 
      ),         
             
      array(
        'name' => 'vkwpv_post_characters',
        'label' => __( 'Описание видео', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Минимальное количество знаков в описании видео. Все видеозаписи с меньшим количеством знаков в описании добавлены не будут. Например: <code>500</code>.
        Оставьте пустым, чтобы не учитывать.', 'vkwpv' ),
        'type' => 'text',
        'readonly' => true 
      ),  
              
     array(
        'name' => 'vkwpv_skip_videos_wt',
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Если опция включена, не будут добавлены видеозаписи, которые не содержат описаний.', 'vkwpv' ),
        'type' => 'multicheck',
        'options' => array(
          'on' => 'Пропускать видеозаписи у которых нет описаний.'
        ),
        'readonly' => true  
      ),    

     array(
        'name' => 'vkwpv_skip_description',
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Если опция включена, не будет добавлено описание видеозаписей.', 'vkwpv' ),
        'type' => 'multicheck',
        'options' => array(
          'on' => 'Не добавлять описание видеозаписей.'
        ),
        'readonly' => true  
      ), 
      
     array(
        'name' => 'vkwpv_hd',
        'label' => __( 'Качество видео', 'vkwpv' ),        
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Если опция включена, будут добавлены только видео с указанным качеством. Внимание! Если видеофайл размещен не вконтакте - видео добавлено не будет.', 'vkwpv' ),
        'readonly' => true,
        'type' => 'select',
        'default' => 'off',
        'options' => array(
          'off' => 'off',
          '240' => '240',
          '360' => '360',
          '480' => '480',
          '720' => '720'                    
        )            
      ),        
      
      array(
        'name' => 'vkwpv_user_id',
        'label' => __( 'ID Пользователя', 'vkwpv' ),
        'desc' => __( 'ID пользователя от имени которого будут опубликованы записи.', 'vkwpv' ),
        'type' => 'text',
        'default' => '1'
      ),       
             
      array(
        'name' => 'parent_category',
        'label' => __( 'Поместить в рубрику', 'vkwpv' ),
        'desc' => __( 'Рубрика, в которую будут размещаться записи из ВКонтакте.<br/>Можно выбрать из существующих или <a href = "'.site_url('/wp-admin/edit-tags.php?taxonomy=category').'">создать новую</a>.', 'vkwpv' ),
        'type' => 'select_category',
        'default' => 1
      ),     
      
      array(
        'name' => 'video_embed',
        'label' => __( 'Встраивать видео', 'vkwpv' ),
        'desc' => __( 'Как видео будет отображаться на странице записи.', 'vkwpv' ),
        'type' => 'radio',
        'default' => 'after',
        'options' => array(
          'before' => '<b>До</b> содержания записи',
          'after' => '<b>После</b> содержания записи',
          'manually' => '<b>Вручную</b> / используйте шорткод <code>[vk_video]</code>'
        )
      ),        
      array(
        'name' => 'post_status',
        'label' => __( 'Новые записи', 'vkwpv' ),
        'desc' => __( 'Новые записи могут быть опубликованы сразу или сохранены как черновик.', 'vkwpv' ),
        'type' => 'radio',
        'default' => 'publish',
        'options' => array(
          'publish' => 'Публиковать сразу',
          'draft' => 'Сохранять в черновик'
        )
      ),  
          
     array(
        'name' => 'post_thumbnail',
        'label' => __( 'Featured Image', 'vkwpa' ),
        'desc' => __( 'Featured Image профессиональные темы используют как изображение для анонса записи.', 'vkwpa' ),
        'type' => 'multicheck',
        'default' => array('on' => 'on'),
        'options' => array(
          'on' => 'Устанавливать обложку видео как Featured Image.'
        ) 
      ),
      
      array(
        'name' => 'post_thumbnail_type',
        'label' => __( 'Обложки для видео', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Сохранять ли обложки видео на сайте (как attachment) или загружать их из ВКонтакте (<em>не рекомендуется</em>).', 'vkwpv' ),
        'type' => 'radio',
        'default' => 'int',
        'options' => array(
          'int' => 'Сохранять на сайте',
          'ext' => 'Не сохранять на сайте / <small>Не рекомендуется. Опция Featured Image работать не будет.</small>'
        ),
        'readonly' => true
      ),  
           
     array(
        'name' => 'width',
        'label' => __( 'Ширина видеозаписей', 'vkwpv' ),
        'desc' => __( 'Начальная ширина видеозаписей (в пикселях). Эта опция влияет только на дизайн сайта, так как любое видео можно увеличить "На весь экран".', 'vkwpv' ),
        'type' => 'select',
        'default' => '320',
        'options' => array(
          '130' => '130',
          '160' => '160',
          '320' => '320'
        )                   
      ),       
      array(
        'name' => 'player_width',
        'label' => __( 'Ширина видеоплеера', 'vkwpv' ),
        'desc' => __( 'Ширина встраиваемого видеоплеера на странице (для темы Free WP Tube).', 'vkwpv' ),
        'type' => 'text',
        'default' => '650',                  
      ),  
      array(
        'name' => 'autorefresh',
        'label' => __( 'Синхронизация с VK', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Запустить или остановить <b>автоматическую синхронизацию по графику</b> записей на сайте c видеоальбомом ВКонтакте.', 'vkwpv' ),
        'type' => 'radio',
        'default' => 'off',
        'options' => array(
          'on' => 'Запущена',
          'off' => 'Остановлена',
        ),
        'readonly' => true 
      ),        
      array(
        'name' => 'cron_time',
        'label' => __( 'График синхронизации', 'vkwpv' ),
        'desc' => __( '<small>Доступно в <a href = "javascript:void(0);" class = "get-vk-wp-video-pro">PRO версии</a>.</small>
        <br/>Синхронизация будет происходить в указанное время.<br/>Время нужно указывать в формате: <code>ЧЧ:ММ</code> разделяя пробелом.<br/>Крон запускается один раз в 15 минут, поэтому для минут следует устанавливать только значения кратные 15.<br/>Не рекомендуется синхронизировать чаще одного раза в час.', 'vkwpv' ),
        'type' => 'textarea',
        'default' => '09:00 11:00 12:00 13:00 14:00 15:00 16:00 17:00 18:00 19:00 20:00 22:00 00:00',
        'readonly' => true 
      ),                       
       
    )   
  );
  $fields = apply_filters('vkwpv_fields', $fields, $fields);
  
  if (isset($options['site_app_id']) && !empty($options['site_app_id']) && isset($options['site_app_secret']) && !empty($options['site_app_secret'])) {
    
    array_push(
      $fields['vkwpv_vk_api_section'],
      array(
        'name' => 'site_access_token_desc',
        'desc' => __( $vkwpv_site_access_token_desc, 'evc' ),
        'type' => 'html',
      ), 
      array(
        'name' => 'site_access_token',
        'label' => __( 'Access Token', 'evc' ),
        'desc' => __( 'Значение будет подставлено автоматически, как только вы пройдете по указанной выше ссылке.', 'evc' ),
        'type' => 'text',
        'readonly' => true      
      )
    );
  }
   
  
 $vkwpv->set_sections( $tabs );
 $vkwpv->set_fields( $fields );

 $vkwpv->admin_init();
}
add_action( 'admin_init', 'vkwpv_init' );


// Register the plugin page
function vkwpv_menu() {
  global $vkwpv_page; 
  
  add_menu_page( 'VK Cinema', 'VK Cinema', 'activate_plugins', 'vk-wp-video', 'vkwpv_page', '', '99.13' ); 
  $vkwpv_page = add_submenu_page( 'vk-wp-video', 'Импорт видео из ВКонтакте', 'Импорт видео ВК', 'activate_plugins', 'vk-wp-video', 'vkwpv_page' );  

  add_action( 'admin_footer-'. $vkwpv_page, 'vkwpv_page_js' );
}
add_action( 'admin_menu', 'vkwpv_menu', 50 );


add_filter('evc_img_refresh', 'vkwpv_albums_img_refresh');
function vkwpv_albums_img_refresh ($count) {
  $options = get_option('vkwpv');
  if (isset($options['img_refresh']) && !empty($options['img_refresh']))
    return $options['img_refresh'];
}


// Display the plugin settings options page
function vkwpv_page() {
  global $vkwpv;
  $options = get_option('vkwpv');   

  echo '<div class="wrap">';
    echo '<div id="icon-options-general" class="icon32"><br /></div>';
    echo '<h2>Импорт видео из ВКонтакте</h2>';

    
    echo '<div id = "col-container">';  
      echo '<div id = "col-right" class = "evc">';
        echo '<div class = "evc-box">';
        vkwpv_ad();
        echo '</div>';
      echo '</div>';
      echo '<div id = "col-left" class = "evc">';
        settings_errors();
        $vkwpv->show_navigation();
        $vkwpv->show_forms();
      echo '</div>';
    echo '</div>';
    
    
  echo '</div>';
}

function vkwpv_page_js() {
?>
<script type="text/javascript" >
  jQuery(document).ready(function($) {
   
   ajaxData = {}; 
    
    $(window).data('vkwpv_refresh', true);
    $(document).on('click', '#vkwpv_refresh', function(e){
      e.preventDefault();
      if ($("#vkwpv\\[refresh_count\\]").val().length){
        ajaxData.refresh_count = $("#vkwpv\\[refresh_count\\]").val();
      }
      if ($("#vkwpv\\[refresh_offset_count\\]").val().length){
        ajaxData.refresh_offset_count = $("#vkwpv\\[refresh_offset_count\\]").val();
      }      

      if ($("#vkwpv\\[owner_id\\]").val().length) {
        ajaxData.owner_id = $("#vkwpv\\[owner_id\\]").val();
        //console.log(ajaxData); //
      }
 
      <?php do_action('vkwpv_refresh_click'); ?> 
      
      if (typeof ajaxData.owner_id == "undefined" || !ajaxData.owner_id.length)
        return false;
      
      ajaxData.action = 'vkwpv_refresh_js';
      //console.log(ajaxData);
      //return false;
      if( $(window).data('vkwpv_refresh') == true ) {
      $.ajax({
        url: ajaxurl,
        data: ajaxData,
        type:"POST",
        dataType: 'json',  
        beforeSend: function() {
          $(window).data('vkwpv_refresh', false);
          $("#vkwpv_refresh\\[spinner\\]").css({'display': 'inline-block'});
          if ($('#vkwpv_refresh_msg').length)
            $('#vkwpv_refresh_msg').html('<br/>Пожалуйста, подождите. Импорт может занять несколько минут.'); 
          else
            $('<span id = "vkwpv_refresh_msg"><br/>Пожалуйста, подождите. Импорт может занять несколько минут.</span>').insertAfter('#vkwpv_refresh\\[spinner\\]')
        },            
        success: function(data) {
          $("#vkwpv_refresh\\[spinner\\]").hide();
          if (data['success'])
            $('#vkwpv_refresh_msg').html('<br/>Cоздано постов: '+ data['posts'] +'.');
          if (data['error'])
            $('#vkwpv_refresh_msg').html('<br/>Ошибка!');            
          //console.log(data);
          $(window).data('vkwpv_refresh', true);
        }
      });     
      }     
    });
    
    $(document).on('click', '#vkwpv_refresh_vk_img', function(e){
      e.preventDefault();
      
      var data = {
        action: 'evc_refresh_vk_img'
      };      
      
      $.ajax({
        url: ajaxurl,
        data: data,
        type:"POST",
        dataType: 'json',  
        beforeSend: function() {
          $("#vkwpv_refresh\\[spinner\\]").css({'display': 'inline-block'});
          if ($('#vkwpv_refresh_msg').length)
            $('#vkwpv_refresh_msg').html('<br/>Изображения загружаются.'); 
          else
            $('<span id = "vkwpv_refresh_msg"><br/>Изображения загружаются.</span>').insertAfter('#vkwpv_refresh\\[spinner\\]')          
          

        },            
        success: function(data) {
          $("#vkwpv_refresh\\[spinner\\]").hide();
          if (typeof data['error'] != 'undefined' )
            $('#vkwpv_refresh_msg').html('<br/>Ошибка!');            
          else {
            if ( !data['left'] )
              $('#vkwpv_refresh_msg').html('<br/>Все изображения загружены.');
            else
              $('#vkwpv_refresh_msg').html('<br/>Загружено: ' + data['refresh'] + ', осталось: ' + data['left'] + '.');             
          }

          //console.log(data);
        }
      });     
    });     


    $(document).on('click', '#vkwpv_clear_cache', function(e){
      e.preventDefault();
      
      var data = {
        action: 'vkwpv_clear_cache'
      };      
      
      $.ajax({
        url: ajaxurl,
        data: data,
        type:"POST",
        dataType: 'json',  
        beforeSend: function() {
          $("#vkwpv_refresh\\[spinner\\]").css({'display': 'inline-block'});
          if ($('#vkwpv_refresh_msg').length)
            $('#vkwpv_refresh_msg').html('<br/>Кэш очищается.'); 
          else
            $('<span id = "vkwpv_refresh_msg"><br/>Кэш очищается.</span>').insertAfter('#vkwpv_refresh\\[spinner\\]')          
          

        },            
        success: function(data) {
          $("#vkwpv_refresh\\[spinner\\]").hide();
          if (data['success'])
            $('#vkwpv_refresh_msg').html('<br/>Кэш очищен.');
          if (data['error'])
            $('#vkwpv_refresh_msg').html('<br/>Ошибка!');            
          //console.log(data);
        }
      });     
    }); 
    
    $("#vkwpa_vk_api\\[app_id\\]").change( function() {
      if ($(this).val().trim().length) {
        $(this).val($(this).val().trim());
        $('#getaccesstokenurl').attr({'href': 'http://oauth.vk.com/authorize?client_id='+ $(this).val().trim() +'&scope=wall,photos,offline&redirect_uri=http://api.vk.com/blank.html&display=page&response_type=token', 'target': '_blank'});
        
      }
      else {
        $('#getaccesstokenurl').attr({'href': 'javscript:void(0);'});
      }
      
    });      

  });
</script>
<?php
}

add_action('wp_ajax_vkwpv_clear_cache', 'vkwpv_clear_cache');
function vkwpv_clear_cache() {
  
  $vk_videos = get_transient('vk_videos');
  if ($vk_videos && !empty($vk_videos))
    delete_transient('vk_videos');
  
  $out['success'] = true;

  print json_encode($out);
  exit;    
}

add_action('vkwpv_after_post_add', 'vkwpv_update_post_metas', 10, 2);
function vkwpv_update_post_metas ($post_id, $post) {
  $meta = array();
  if (isset($post['duration']))
    $meta['duration'] = fTime($post['duration'] * 1000);   
  
  if (!empty($meta))
    evc_update_post_metas ($meta, $post_id);
}

add_action('evc_save_remote_attachment_action', 'vkwpv_update_post_metas2', 10, 4);
function vkwpv_update_post_metas2 ($a, $att_id, $att, $obj) {
  if (isset($a['vk_player'])) {
    $options = get_option('vkwpv'); 
    if (!isset($options['player_width']) || empty($options['player_width']) )
      $options['player_width'] = 600;
      
    $meta = array();
    
    $src = wp_get_attachment_image_src( $att_id, 'large' );
    if ($src) {
      
      $meta['vk_width'] = $src[1];
      $meta['vk_height'] = $src[2];
      $meta['thumb'] = $src[0];
      $meta['video_code'] = '<iframe src="'.$a['vk_player'].'" width="'.$options['player_width'].'" height="'.($meta['vk_height'] * $options['player_width'] / $meta['vk_width'] ).'" frameborder="0"></iframe>';     
    }
    
    if (!empty($meta))
      evc_update_post_metas ($meta, $att['post_parent']);    
  }
}

function two($x) {
  return (($x>9)?"":"0").$x;
}

function three($x) {
  return (($x>99)?"":"0").(($x>9)?"":"0").$x;
}

function fTime($mls) {
  $t ='';
  $sec = floor($mls/1000);
  $mls = $mls % 1000;
  //$t = ":" . three($mls);

  $min = floor($sec/60);
  $sec = $sec % 60;
  $t = two($sec). $t;

  $hr = floor($min/60);
  $min = $min % 60;
  $t = two($min). ":" . $t;

  $day = floor($hr/60);
  $hr = $hr % 60;
  $t = two($hr) . ":" . $t;
  //$t = $day . ":".  $t;

  return $t;
}



add_shortcode('vk_video', 'vkwpv_shortcode');
function vkwpv_shortcode ($atts = array(), $content = '') {
  global $post;
  //$global_post = $post;
  
  if (!is_single())
    return $content;
    
  if (!empty($atts))
    extract ($atts);
  
  $meta = get_post_meta($post->ID);  
  if (isset($meta['vk_item_id'][0]) && isset($meta['video_code'][0]) ) {    
    $out = '<div class = "vkwpv-video">'.$meta['video_code'][0].'</div>';
  }
    
  return $out;
}


add_filter ('the_content', 'vkwpv_the_content_filter', 1);
function vkwpv_the_content_filter ($content) {
  global $post;
  $meta = get_post_meta($post->ID);
  $options = get_option('vkwpv');
  
  if (is_single() && isset($meta['vk_item_id'][0]) && isset($meta['video_code'][0]) && isset($options['video_embed']) ) {
    
    $pattern = get_shortcode_regex();
    preg_match_all('/'.$pattern.'/s', $post->post_content, $matches);
    if ((!is_array($matches) || !in_array('vk_video', $matches[2]))) {    
      
      if ($options['video_embed'] == 'before')
        $content = do_shortcode('[vk_video]') . $content;
      if ($options['video_embed'] == 'after')
        $content = $content . do_shortcode('[vk_video]');
    }
  }
  
  return $content;
}

add_action('wp_enqueue_scripts', 'vkwpv_enqueue_scripts');
function vkwpv_enqueue_scripts () {
  wp_register_style( 'vkwpv_style', plugins_url('/style.css', __FILE__) );
  wp_enqueue_style( 'vkwpv_style' );  
}

function vkwpv_ad () {

  echo '
    <div class = "evc-boxx">
      <p><a href = "http://ukraya.ru/314/vk-wp-video" target = "_blank">Помощь</a> по настройке плагина.</p>
    </div>   
    ';
    
  echo '
    <h3>Автонаполняемый сайт из группы ВКонтакте в один клик!</h3>
    <p>Плагин <a href = "http://ukraya.ru/162/vk-wp-bridge" target = "_blank">VK-WP Bridge</a> позволяет создать полноценный сайт или раздел на уже действующем сайте, полностью (посты, фото, видео, комментарии, лайки и т.п.) синхронизированный с группой ВКонтакте и автообновляемый по графику.</p>
    <p><i>Хватит работать на ВКонтакте!<br/>Пусть <a href = "http://ukraya.ru/162/vk-wp-bridge" target = "_blank">ВКонтакте поработает на вас</a>!</i></p>
    <p>'.get_submit_button('Узнать больше', 'primary', 'get_vk_wp_bridge', false).'</p>       
    ';        
  
  echo '
    <h3>EVC PRO: грандиозные возможности!</h3>
    <p>Плагин <a href = "http://ukraya.ru/421/evc-pro" target = "_blank">EVC PRO</a> даст вам возможности, которых нет у других пользователей. Вы сможете, не прилагая усилий, получить больше подписчиков в свои группы ВКонтакте, больше лайков, репостов, комментариев к материалам...</p>
    <p>'.get_submit_button('Узнать больше', 'primary', 'get_evc_pro', false).'</p>  
    ';          
}

add_action( 'admin_footer', 'vkwpv_ad_js', 30 );
function vkwpv_ad_js () {
?>
<script type="text/javascript" >
  jQuery(document).ready(function($) {

    $(document).on( 'click', '.get-vk-wp-video-pro', function (e) {    
      e.preventDefault();
      window.open(
        'http://ukraya.ru/316/vk-wp-video-pro',
        '_blank'
      );
    }); 

    <?php if (!function_exists('evc_ad')) { ?>
    $(document).on( 'click', '#get_vk_wp_bridge', function (e) {    
      e.preventDefault();
      window.open(
        'http://ukraya.ru/162/vk-wp-bridge',
        '_blank'
      );
    });       
    
    $(document).on( 'click', '#get_evc_pro, .get-evc-pro', function (e) {    
      e.preventDefault();
      window.open(
        'http://ukraya.ru/421/evc-pro',
        '_blank'
      );
    }); 
        
    <?php } ?>     

  
  }); // jQuery End
</script>
<?php  
}


add_action('admin_head', 'vkwpv_admin_head', 99 );
function vkwpv_admin_head () {
  echo '<style type="text/css">
    #col-right.evc {
      width: 35%;
    }
    #col-left.evc {
      width: 65%;
    }    
    .evc-box{
      padding:0 20px 0 40px;
    }
    .evc-boxx {
      background: none repeat scroll 0 0 #FFFFFF;
      border-left: 4px solid #2EA2CC;
      box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1);
      margin: 5px 0 15px;
      padding: 1px 12px;
    }
    .evc-boxx h3 {
      line-height: 1.5;
    }
    .evc-boxx p {
      margin: 0.5em 0;
      padding: 2px;
    }
  </style>'; 
}