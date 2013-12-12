<?php
/*
Plugin Name: Prizm Image
Plugin URI: http://wordpress.org/extend/plugins/wp-prizmimage/
Description: Prizm Image can be used to significantly reduce the size of your image files, leading to improved performance. Files are reduced without any loss of visual quality.
Author: Accusoft
Version: 1.0
License: GPL2
Author URI: http://www.accusoft.com/
Textdomain: PrizmImage
*/

/* 
Copyright 2013 Accusoft Corporation

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/



if ( !function_exists( 'download_url' ) ) {
  require_once( ABSPATH . 'wp-admin/includes/file.php' );
}

if ( !class_exists( 'AccusoftImageService' ) ) {

class AccusoftImageService {

  var $version = "1.0";

  /**
     * Constructor
     */
  function AccusoftImageService( ) {
    $this->__construct( );
  }
  function __construct( ) {

    /**
     * Constants
     */
    define( 'IMAGE_SERVICE_BASE_URL',    'http://api.prizmimage.com:80/' );
    define( 'IMAGE_SERVICE_UPLOAD_URL',   IMAGE_SERVICE_BASE_URL . 'V0/Document/%s' );
    define( 'IMAGE_SERVICE_DOWNLOAD_URL', IMAGE_SERVICE_BASE_URL . 'V0/Document/%s' );
    define( 'IMAGE_SERVICE_PROGRESS_URL', IMAGE_SERVICE_BASE_URL . 'V0/Progress/%s' );
    define( 'IMAGE_SERVICE_COMPRESS_URL', IMAGE_SERVICE_BASE_URL . 'V0/ReduceSize/%s?qualityFactor=%s&removeMetadata=%s&jpegMode=%s' );   

    // The domain is used for text translation.
    define( 'WP_IMAGE_SERVICE_DOMAIN', 'PrizmImage' );
    
    // User Agent used in HTTP messages.
    define( 'WP_IMAGE_SERVICE_UA', "WP PrizmImage/{$this->version} (+http://wordpress.org/extend/plugins/prizmimage/)" );
    
    // This value is a return code from the Prizm Image service that indicates the API has been deprecated.
    // If an API request is issued to the service, that uses a deprecated URL, then this code is returned.
    define( 'WP_IMAGE_SERVICE_DEPRECATED_API_CODE', 999);
    
    // The maximum number of status request messages to send before giving up.
    // This is essentially a time out mechanism.  If an image reduce operation does not complete before we give up, then
    // the image will not be reduced. 
    // We wait 1 second before each retry attempt.  So the number of retry attempts is roughly equal to the number of
    // elapsed seconds before timing out.
    define('WP_IMAGE_SERVICE_MAX_STATUS_ATTEMPTS', 25);
    
    // Three quality settings are exposed as user configurable options.
    define('WP_IMAGE_SERVICE_QUALITY_LOW', 30);
    define('WP_IMAGE_SERVICE_QUALITY_MED', 14);
    define('WP_IMAGE_SERVICE_QUALITY_HIGH', 5);
    
    // JPEG Mode settings exposed as user configurable options.
    define('WP_IMAGE_SERVICE_JPEG_MODE_SEQUENTIAL',  1); 
    define('WP_IMAGE_SERVICE_JPEG_MODE_PROGRESSIVE', 2);
    define('WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE',    3);

    // The values for these are retrieved from the specified settings fields.
    define( 'WP_IMAGE_SERVICE_AUTO', intval( get_option( 'wp_image_service_service_auto', 0) ) );
    define( 'WP_IMAGE_SERVICE_TIMEOUT', intval( get_option( 'wp_image_service_service_timeout', 120) ) );
    define( 'WP_IMAGE_SERVICE_DEBUG', get_option( 'wp_image_service_service_debug', '') );
    define( 'WP_IMAGE_SERVICE_QUALITY', get_option( 'wp_image_service_service_quality', WP_IMAGE_SERVICE_QUALITY_MED) );  
    define( 'WP_IMAGE_SERVICE_REMOVE_METADATA', get_option( 'wp_image_service_service_metadata', 'on'));
    define( 'WP_IMAGE_SERVICE_JPEG_MODE', get_option( 'wp_image_service_service_jpeg_mode', WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE));
    
    if ((!isset($_GET['action'])) || ($_GET['action'] != "wp_image_service_manual")) {
      define( 'WP_IMAGE_SERVICE_DEBUG', get_option( 'wp_image_service_service_debug', '') );
    } else {
      // Comment the following line, then uncomment the next line to allow debugging when manual reduction is invoked.
      // Also, comment the call to wp_redirect in the function image_service_manual to see all of the debug info when doing a manual operation.
      //define( 'WP_IMAGE_SERVICE_DEBUG', '' );
      define( 'WP_IMAGE_SERVICE_DEBUG', get_option( 'wp_image_service_service_debug', '') );
    }
    
    /*
    Settings that specify whether the Prizm Image service should be used automatically on image upload.
    Values are:
      -1  Don't use (until manually enabled via Media > Settings)
      0   Use automatically
      n   Any other number is a Unix timestamp indicating when the service can be used again
    */
    define('WP_IMAGE_SERVICE_AUTO_OK', 0);
    define('WP_IMAGE_SERVICE_AUTO_NEVER', -1);
    
    /**
     * Hooks
     */
    if ( WP_IMAGE_SERVICE_AUTO == WP_IMAGE_SERVICE_AUTO_OK ) {
      add_filter( 'wp_generate_attachment_metadata', array( &$this, 'resize_from_meta_data' ), 10, 2 );
    }
    
    add_filter( 'manage_media_columns', array( &$this, 'columns' ) );
    add_action( 'manage_media_custom_column', array( &$this, 'custom_column' ), 10, 2 );
    add_action( 'admin_init', array( &$this, 'admin_init' ) );
    add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
    add_action( 'admin_action_wp_image_service_manual', array( &$this, 'image_service_manual' ) );
    add_action( 'admin_head-upload.php', array( &$this, 'add_bulk_actions_via_javascript' ) );
    add_action( 'admin_action_bulk_image_service', array( &$this, 'bulk_action_handler' ) );
    add_action( 'admin_init', array( &$this, 'register_settings' ) );
  }
  
  /**
   * Plugin setting functions
   */
  function register_settings( ) {
    add_settings_section( 'wp_image_service_settings', 'WP Prizm Image', array( &$this, 'settings_cb' ), 'media' );

    add_settings_field( 'wp_image_service_service_quality', __( 'Select quality of reduced images', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_quality_opts' ),  'media', 'wp_image_service_settings' );
      
    add_settings_field( 'wp_image_service_service_metadata', __( 'Remove JPEG metadata', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_metadata_opts' ), 'media', 'wp_image_service_settings' );
      
    add_settings_field( 'wp_image_service_service_jpeg_mode', __( 'Select JPEG Mode', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_jpeg_mode_opts' ),  'media', 'wp_image_service_settings' );

    add_settings_field( 'wp_image_service_service_auto', __( 'Use Prizm Image on upload?', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_auto_opts' ),  'media', 'wp_image_service_settings' );

    add_settings_field( 'wp_image_service_service_timeout', __( 'How many seconds should we wait for a response from Prizm Image?', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_timeout_opts' ), 'media', 'wp_image_service_settings' );

    add_settings_field( 'wp_image_service_service_debug', __( 'Enable debug processing', WP_IMAGE_SERVICE_DOMAIN ), 
      array( &$this, 'render_debug_opts' ), 'media', 'wp_image_service_settings' );
      

    register_setting( 'media', 'wp_image_service_service_quality' );
    register_setting( 'media', 'wp_image_service_service_metadata' );
    register_setting( 'media', 'wp_image_service_service_jpeg_mode' );
    register_setting( 'media', 'wp_image_service_service_auto' );
    register_setting( 'media', 'wp_image_service_service_timeout' );
    register_setting( 'media', 'wp_image_service_service_debug' );
  }

  function settings_cb( ) {
  }
  
  /**
   *These functions handle setting up the screen to set various user configurable options.
   */
  function render_jpeg_mode_opts( ) {
    $key = 'wp_image_service_service_jpeg_mode';
    $val = intval( get_option( $key, WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE ) );
    printf( "<select name='%1\$s' id='%1\$s'>",  esc_attr( $key ) );
    echo '<option value=' . WP_IMAGE_SERVICE_JPEG_MODE_PROGRESSIVE . ' ' . selected( WP_IMAGE_SERVICE_JPEG_MODE_PROGRESSIVE, $val ) . '>'. __( 'Convert sequential JPEGs to progressive JPEGs', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '<option value=' . WP_IMAGE_SERVICE_JPEG_MODE_SEQUENTIAL . ' ' . selected( WP_IMAGE_SERVICE_JPEG_MODE_SEQUENTIAL, $val ) . '>'. __( 'Convert progressive JPEGs to sequential JPEGs', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '<option value=' . WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE . ' ' . selected( WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE, $val ) . '>'. __( 'Do not change the JPEG mode', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '</select>';
  }
  
  function render_quality_opts( ) {
    $key = 'wp_image_service_service_quality';
    $val = intval( get_option( $key, WP_IMAGE_SERVICE_QUALITY_MED ) );
    printf( "<select name='%1\$s' id='%1\$s'>",  esc_attr( $key ) );
    echo '<option value=' . WP_IMAGE_SERVICE_QUALITY_LOW . ' ' . selected( WP_IMAGE_SERVICE_QUALITY_LOW, $val ) . '>'. __( 'Lower Quality - Smaller File Size', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '<option value=' . WP_IMAGE_SERVICE_QUALITY_MED . ' ' . selected( WP_IMAGE_SERVICE_QUALITY_MED, $val ) . '>'. __( 'Balanced Quality and File Size', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '<option value=' . WP_IMAGE_SERVICE_QUALITY_HIGH . ' ' . selected( WP_IMAGE_SERVICE_QUALITY_HIGH, $val ) . '>'. __( 'HIgher Quality - Larger File Size', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '</select>';
  }

  function render_auto_opts( ) {
    $key = 'wp_image_service_service_auto';
    $val = intval( get_option( $key, WP_IMAGE_SERVICE_AUTO_OK ) );
    printf( "<select name='%1\$s' id='%1\$s'>",  esc_attr( $key ) );
    echo '<option value=' . WP_IMAGE_SERVICE_AUTO_OK . ' ' . selected( WP_IMAGE_SERVICE_AUTO_OK, $val ) . '>'. __( 'Automatically process on upload', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';
    echo '<option value=' . WP_IMAGE_SERVICE_AUTO_NEVER . ' ' . selected( WP_IMAGE_SERVICE_AUTO_NEVER, $val ) . '>'. __( 'Do not process on upload', WP_IMAGE_SERVICE_DOMAIN ) . '</option>';

    if ( $val > 0 ) {
      printf( '<option value="%d" selected="selected">', $val ) . 
      printf( __( 'Temporarily disabled until %s', WP_IMAGE_SERVICE_DOMAIN ), date( 'M j, Y \a\t H:i', $val ) ).'</option>';
    }
    echo '</select>';
  }

  function render_timeout_opts( $key ) {
    $key = 'wp_image_service_service_timeout';
    printf( "<input type='text' name='%1\$s' id='%1\$s' value='%2\%d'>",  esc_attr( $key ), intval( get_option( $key, 120 ) ) );
  }

  function render_debug_opts(  ) {
    $key = 'wp_image_service_service_debug';
    $val = get_option( $key );
    ?><input type="checkbox" name="<?php echo $key ?>" <?php if ($val) { echo ' checked="checked" '; } ?>/> <?php _e( 'If you are having trouble with the plugin, enable this option to display additional troubleshooting information.', WP_IMAGE_SERVICE_DOMAIN );
  }
  
  function render_metadata_opts(  ) {
    $key = 'wp_image_service_service_metadata';
    $val = get_option( $key, 'on' );
    ?><input type="checkbox" name="<?php echo $key ?>" <?php if ($val == 'on') { echo ' checked="checked" '; } ?>/> <?php _e( 'By default, JPEG metadata (except for copyright data) is removed when reducing images.  Uncheck this option to preserve all metadata.', WP_IMAGE_SERVICE_DOMAIN );
  }
  
  function admin_init( ) {
    load_plugin_textdomain(WP_IMAGE_SERVICE_DOMAIN, false, dirname(plugin_basename(__FILE__)).'/languages/');
    wp_enqueue_script( 'common' );
  }

  function admin_menu( ) {
    add_media_page( 'Bulk Prizm Image', 'Bulk Prizm Image', 'edit_others_posts', 'wp-image_service-bulk', array( &$this, 'bulk_preview' ) );
    
    // This could be used to add a Prizm Image settings page that is separate fromt the Prizm Image section of the Media settings.
    // Currently all configuration options are set in the Prizm Image section of the Media settings page.
    //add_menu_page('Prizm Image', 'Prizm Image', 'administrator', 'image_service_settings', array( &$this, 'image_service_menu_settings' ) );
  }

  // This would be used to implement a  Prizm Image specific settings page.
  //function image_service_menu_settings() {  
  //}
  
  // This function handles bulk processing.
  function bulk_preview( ) {
    if ( function_exists( 'apache_setenv' ) ) {
      @apache_setenv('no-gzip', 1);
    }
    @ini_set('output_buffering','on');
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);

    $attachments = null;
    $auto_start = false;

    if ( isset($_REQUEST['ids'] ) ) {
      $attachments = get_posts( array(
        'numberposts' => -1,
        'include' => explode(',', $_REQUEST['ids']),
        'post_type' => 'attachment',
        'post_mime_type' => 'image'
      ));
      $auto_start = true;
    } else {
      $attachments = get_posts( array(
        'numberposts' => -1,
        'post_type' => 'attachment',
        'post_mime_type' => 'image'
      ));
    }
    ?>
    <div class="wrap"> 
      <div id="icon-upload" class="icon32"><br /></div><h2><?php _e( 'Bulk Prizm Image', WP_IMAGE_SERVICE_DOMAIN ) ?></h2>
    <?php 

    if ( sizeof($attachments) < 1 ) {
      _e( "<p>You don't appear to have uploaded any images yet.</p>", WP_IMAGE_SERVICE_DOMAIN );
    } else { 
      if ( empty($_POST) && !$auto_start ){ // instructions page
    
        _e("<p>This tool will run all of the images in your media library through the Prizm Image web service. Any image already processed will not be reprocessed. Any new images or previous unsuccessful attempts will be processed.</p>", WP_IMAGE_SERVICE_DOMAIN );
        _e("<p>As part of the Prizm Image API this plugin will upload your image to the web service. The Prizm Image service will process the image then this plugin will download the new version of the image, which will replace the original image on your server.</p>", WP_IMAGE_SERVICE_DOMAIN );

        _e('<p>Limitations of using the Accusoft Prizm Image API</p>', WP_IMAGE_SERVICE_DOMAIN);
        ?>
        <ol>
          <li><?php _e('The images MUST be local to the site. This plugin cannot update images stored on Content Delivery Networks (CDN)', WP_IMAGE_SERVICE_DOMAIN); ?></li>
        </ol>
        <hr />
        <?php printf( __( "<p>We found %d images in your media library. </p>", WP_IMAGE_SERVICE_DOMAIN ), sizeof($attachments) ); ?>
        <form method="post" action="">
          <?php wp_nonce_field( 'wp-image_service-bulk', 'wp_image_service_nonce'); ?>
          <button type="submit" class="button-secondary action"><?php _e( 'Run all my images through Prizm Image right now', WP_IMAGE_SERVICE_DOMAIN ) ?></button>
          <?php 
            if (WP_IMAGE_SERVICE_DEBUG) {
              _e( "<p>DEBUG mode is currently enabled. To disable see the Settings > Media page.</p>", WP_IMAGE_SERVICE_DOMAIN ); 
            }
          ?>
        </form>
        <?php
      } else { // run the script

        if ( !wp_verify_nonce( $_REQUEST['wp_image_service_nonce'], 'wp-image_service-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
          wp_die( __( 'Sorry, the nonce did not verify.' ) );
        }


        @ob_implicit_flush( true );
        @ob_end_flush();
        foreach( $attachments as $attachment ) {
          printf( __("<p>Processing <strong>%s</strong>&hellip;<br />", WP_IMAGE_SERVICE_DOMAIN), esc_html( $attachment->post_name ) );
          $original_meta = wp_get_attachment_metadata( $attachment->ID, true );
          
          $meta = $this->resize_from_meta_data( $original_meta, $attachment->ID, false );
          printf( "&mdash; [original] %d x %d: ", intval($meta['width']), intval($meta['height']) );

          if ((isset( $original_meta['wp_image_service'] )) 
           && ( $original_meta['wp_image_service'] == $meta['wp_image_service']) 
           && (stripos( $meta['wp_image_service'], 'PrizmImage error' ) === false ) ) {
            if ((stripos( $meta['wp_image_service'], '<a' ) === false)
             && (stripos( $meta['wp_image_service'], __('No savings', WP_IMAGE_SERVICE_DOMAIN )) === false))
              echo $meta['wp_image_service'] .' '. __('<strong>already reduced</strong>', WP_IMAGE_SERVICE_DOMAIN);
            else  
              echo $meta['wp_image_service'];
          } else {
            echo $meta['wp_image_service'];
          }
          echo '<br />';

          if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach( $meta['sizes'] as $size_name => $size  ) {
              printf( "&mdash; [%s] %d x %d: ", $size_name, intval($size['width']), intval($size['height']) );
              if ( $original_meta['sizes'][$size_name]['wp_image_service'] == $size['wp_image_service'] && stripos( $meta['sizes'][$size_name]['wp_image_service'], 'PrizmImage error' ) === false ) {
                echo $size['wp_image_service'] .' '. __('<strong>already reduced</strong>', WP_IMAGE_SERVICE_DOMAIN);
              } else {
                echo $size['wp_image_service'];
              }
              echo '<br />';
            }
          }
          echo "</p>";

          wp_update_attachment_metadata( $attachment->ID, $meta );

          // The following code is supposed to flush the output buffer so that the browswer is updated.
          //
          // If running a Windows system using IIS, the ResponseBufferLimit takes precedence over PHP's output_buffering settings. 
          // So you must also set the ResponseBufferLimit to be something lower than its default value.
          // For IIS versions older than 7, the setting can be found in the %windir%\System32\inetsrv\fcgiext.ini file 
          // (the FastCGI config file). You can set the appropriate line to: ResponseBufferLimit=0
          //
          // For IIS 7+, the settings are stored in %windir%\System32\inetsrv\config. Edit the applicationHost.config file 
          // and search for PHP_via_FastCGI (assuming that you have installed PHP as a FastCGI module, as per the installation instructions,
          // with the name PHP_via_FastCGI). Within the add tag, place the following setting at the end: responseBufferLimit="0"
          // So the entire line will look something like:
          // <add name="PHP_via_FastCGI" path="*.php" verb="*" modules="FastCgiModule" scriptProcessor="C:\PHP\php-cgi.exe" resourceType="Either" responseBufferLimit="0" />
          @ob_flush();
          flush();
        }
        _e('<hr /></p>Prizm Image finished processing.</p>', WP_IMAGE_SERVICE_DOMAIN);
      }
    }
    ?>
    </div>
    <?php
  }

  
  /**
   * Manually process an image from the Media Library
   */
  function image_service_manual( ) {
    if ( !current_user_can('upload_files') ) {
      wp_die( __( "You don't have permission to work with uploaded files.", WP_IMAGE_SERVICE_DOMAIN ) );
    }

    if ( !isset( $_GET['attachment_ID'] ) ) {
      wp_die( __( 'No attachment ID was provided.', WP_IMAGE_SERVICE_DOMAIN ) );
    }

    $attachment_ID = intval( $_GET['attachment_ID'] );
    
    $original_meta = wp_get_attachment_metadata( $attachment_ID );
    
    $new_meta = $this->resize_from_meta_data( $original_meta, $attachment_ID );
    
    if (WP_IMAGE_SERVICE_DEBUG) {   
        echo "DEBUG: new_meta returned from resize_from_meta: data<pre>"; print_r($new_meta); echo "</pre>";
     }
    
    wp_update_attachment_metadata( $attachment_ID, $new_meta ); 

    wp_redirect( preg_replace( '|[^a-z0-9-~+_.?#=&;,/:]|i', '', wp_get_referer( ) ) );
    exit();
  }
  
  /**
   * Process an image with the Image Service
   *
   * Returns an array of the $file $results.
   *
   * @param   string $file_path       Full absolute path to the image file to be reduced
   * @returns array
   */
  function do_image_service( $file_path = '') {

    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: entered do_image_service $file_path  <br />";
    }
    
    if (empty($file_path)) {
      return __( "File path is empty", WP_IMAGE_SERVICE_DOMAIN );
    }

    // check that the file exists
    if ( !file_exists( $file_path ) || !is_file( $file_path ) ) {
      echo "DEBUG: file does not exist <br />";
      return sprintf( __( "ERROR: Could not find <span class='code'>%s</span>", WP_IMAGE_SERVICE_DOMAIN ), $file_path );
    }

    // check that the file is writable
    if ( !is_writable( dirname( $file_path)) ) {
      return sprintf( __("ERROR: <span class='code'>%s</span> is not writable", WP_IMAGE_SERVICE_DOMAIN ), dirname($file_path) );
    }

    $in_file_size = filesize( $file_path );
    
    // Upload the file to the image service
    $data = $this->_post( $file_path  );
    if ( false === $data || !isset($data) ){
      if (WP_IMAGE_SERVICE_DEBUG) {   
        echo "DEBUG: invalid/empty data returned from _post: data<pre>"; print_r($data); echo "</pre>";
      }
      return __( 'ERROR: posting file to Prizm Image', WP_IMAGE_SERVICE_DOMAIN );
    }
    
    // Parse the JSON response
    $data = $this->json_decode_message($data);
    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: return from _post: data<pre>"; print_r($data); echo "</pre>";
    }
    // Check to see if the API used in this plugin has been deprecated.
    if ($data == WP_IMAGE_SERVICE_DEPRECATED_API_CODE) {
      return __('Please upgrade to latest version of the Prizm Image plugin', WP_IMAGE_SERVICE_DOMAIN );
    }
             
    // The response contains a single data item which is the document id assigned to the uploaded file
    $source_doc_id = $data;
    
    // Call _Compress($document_id) to compress the file
    $data = $this->_compress( $source_doc_id  );
    if ( false === $data || !isset($data) ) {
      return __( 'ERROR: compressing file with Prizm Image', WP_IMAGE_SERVICE_DOMAIN );
    }
    
    // Parse the JSON response
    $data = $this->json_decode_message($data);
    if (WP_IMAGE_SERVICE_DEBUG) { 
        echo "DEBUG: returned from _compress data<pre>"; print_r($data); echo "</pre>";
    }
    // Check to see if the API used in this plugin has been deprecated.
    if ($data == WP_IMAGE_SERVICE_DEPRECATED_API_CODE) {
      return __('Please upgrade to latest version of the Prizm Image plugin', WP_IMAGE_SERVICE_DOMAIN );
    }

    // The response contains a single data item which is the document id assigned to the reduced file.
    $dest_doc_id = $data;
    
    // Check the progress continually until the reduce operation completes, or we try a maximum number of times.
    $resize_complete = false;
    $num_attempts = 1;
    while ( $resize_complete == false && $num_attempts <= WP_IMAGE_SERVICE_MAX_STATUS_ATTEMPTS) {
      $data = $this->_check_progress( $dest_doc_id  );
      if ( false === $data || !isset($data) ) {
        return __( 'ERROR: Getting Progress from Prizm Image', WP_IMAGE_SERVICE_DOMAIN );
      }

      $data = $this->json_decode_message($data);
      if (WP_IMAGE_SERVICE_DEBUG) {   
        echo "DEBUG: JSON data returned from _check_progress: data<pre>"; print_r($data); echo "</pre>";
      }
      // Check to see if the API used in this plugin has been deprecated.
      if ($data == WP_IMAGE_SERVICE_DEPRECATED_API_CODE) {
        return __('Please upgrade to latest version of the Prizm Image plugin', WP_IMAGE_SERVICE_DOMAIN );
      }
      
      // Status Codes:  0 = Not Started, 1 = In Progress, 2 = Completed, 3 = Error, 4 = Progress Not Found,
      // Any other code is unexpected.
      if ( $data->Status == 2 ||  $data->Status == 3 ||  $data->Status > 4 ) {
        $resize_complete = true;
       }
       else {
        // Status is Not Started, In Progress, or Progress can't be found, so retry
        $num_attempts++;
        sleep(1);
       }
     }
        
    // Check for errors in the returned status.  
    // TBD - we need to investigate error handling and make this better.  What if not reduced, but did remove meta-data, etc.
    // Maybe compare before/after size to determine if no savings?
    if ( $data->Status == 0 ||  $data->Status == 1 ) {
      return __('Prizm Image operation did not complete', WP_IMAGE_SERVICE_DOMAIN );
    }
    if ( $data->Status == 3 ||  $data->Status >= 4 ) {
      return __('Bad response from Prizm Image', WP_IMAGE_SERVICE_DOMAIN );
    }
    if ( stripos( $data->Message, 'unable' ) !== false) {
      return __('No savings', WP_IMAGE_SERVICE_DOMAIN );
     } 
    if ( stripos( $data->Message, 'unsupported' ) !== false) {
      return __('Unsupported file type', WP_IMAGE_SERVICE_DOMAIN );
    } 
    
    // Download the reduced file from Prizm Image
    $temp_file = $this->_download($dest_doc_id);
    if ( is_wp_error( $temp_file ) ) {
      @unlink($temp_file);
      return sprintf( __("Error downloading file (%s)", WP_IMAGE_SERVICE_DOMAIN ), $temp_file->get_error_message());
    }

    if (!file_exists($temp_file)) {
      return sprintf( __("Unable to locate Prizm Image downloaded file (%s)", WP_IMAGE_SERVICE_DOMAIN ), $temp_file);
    }
    
    // Replace the original file with the reduced file. 
    //Note: @ suppresses error messages that might be generated by the expression
    @unlink( $file_path );
    $success = @rename( $temp_file, $file_path );
    if (!$success) {
      copy($temp_file, $file_path);
      unlink($temp_file);
    }

    $out_file_size = filesize( $file_path );
    $savings = $in_file_size - $out_file_size;
    if ( $savings == 0) {
      $results_msg =  __('No savings', WP_IMAGE_SERVICE_DOMAIN );
    } else {
      $percent_savings = ($savings/$in_file_size)*100;
      $savings_str = $this->format_bytes( $savings, 1 );
      $savings_str = str_replace( ' ', '&nbsp;', $savings_str );

      $results_msg = sprintf( __("Reduced by %01.2f%% (%s)", WP_IMAGE_SERVICE_DOMAIN ),
              $percent_savings,
              $savings_str );
    }

    return $results_msg;
  }
  
  // This function makes sure json processing code is present and calls it.
  function json_decode_message($data) {
    if ( function_exists('json_decode') ) {
        $data = json_decode( $data );
    } else {
        require_once( 'JSON/JSON.php' );
        $json = new Services_JSON( );
        $data = $json->decode( $data );
    }
    return $data;
  }
  
  
  // This function determines if an image should be processed again, based up the previous processing results.
  function should_reprocess($previous_status) {
  
    // The image was not previously processed, so it should be processed.
    if ( !$previous_status || empty($previous_status ) ) {
      return true;
    }

    // The image was previously processed, with either no savings or successfully reduced, so don't re-process.
    if ( stripos( $previous_status, 'no savings' ) !== false || stripos( $previous_status, 'reduced' ) !== false ) {
      return false;
    }

    // otherwise re-process the image
    return true;
  }

  
    /**
   * Read the image paths from an attachment's meta data and process each image with the image service.
   *
   * This method also adds a `wp_image_service` meta key for use in the media library.
   *
   * Called after `wp_generate_attachment_metadata` is completed.
   */
  function resize_from_meta_data( $meta, $ID = null, $force_reprocess = true ) {
    if ( $ID && wp_attachment_is_image( $ID ) === false ) {
      return $meta;
    }

    $attachment_file_path = get_attached_file($ID);
    if (WP_IMAGE_SERVICE_DEBUG) {
      echo "DEBUG: attachment_file_path=[". $attachment_file_path ."]<br />";
    }
    
    if ( $force_reprocess || $this->should_reprocess(  @$meta['wp_image_service'] ) ) {
      if (WP_IMAGE_SERVICE_DEBUG) {
        echo "DEBUG: Calling do_image_service <br />";
      }
      $meta['wp_image_service'] = $this->do_image_service($attachment_file_path);
    }
    
    // no resized versions, so we can exit
    if ( !isset( $meta['sizes'] ) ) {
        return $meta;
    }

    foreach($meta['sizes'] as $size_key => $size_data) {
      if ( !$force_reprocess && $this->should_reprocess( @$meta['sizes'][$size_key]['wp_image_service'] ) === false ) {
        continue;
      }

      // We take the original image. The 'sizes' will all match the same path. 
      // So just get the dirname and replace the filename.
      $attachment_file_path_size  = trailingslashit(dirname($attachment_file_path)) . $size_data['file'];
      if (WP_IMAGE_SERVICE_DEBUG) {
        echo "DEBUG: attachment_file_path_size=[". $attachment_file_path_size ."]<br />";
      }

      $meta['sizes'][$size_key]['wp_image_service'] = $this->do_image_service( $attachment_file_path_size ) ;
      //echo "size_key[". $size_key ."] wp_image_service<pre>"; print_r($meta['sizes'][$size_key]['wp_image_service']); echo "</pre>";
    }
    
    return $meta;
  }

/**
   * Post (upload) an image to the image service.
   *
   * @param   string          $file_path    full path of the file to send to the image service
   * @return  string|boolean  Returns the JSON response on success or else false
   */
  function _post( $file_path  ) {
    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: entered _post: $file_path <br />";
    }

    // Our API requires that the file name be appended to the request.
    $req = sprintf( IMAGE_SERVICE_UPLOAD_URL,basename($file_path));
    
    // Read the input file.  
    // The function file_get_contents reads the entire file into a string.  It is safe to use on binary files.
    $file_contents = file_get_contents($file_path);
    
    if ($file_contents === FALSE) {
      $data = false;
      if (WP_IMAGE_SERVICE_DEBUG) {   
        echo "Error reading file to be reduced <br />";
      }
    } else {
 
      if (WP_IMAGE_SERVICE_DEBUG) {   
        echo "DEBUG: Calling wp_remote_post: [". $req."]<br />";
      }
      if ( function_exists( 'wp_remote_post' ) ) {
        $response = wp_remote_post( $req, array('user-agent' => WP_IMAGE_SERVICE_UA,
                                                'headers' => array( 'Content-Type' => 'application/octet-stream' ),
                                                'body' => $file_contents,
                                                'timeout' => WP_IMAGE_SERVICE_TIMEOUT ) );
        if ( !$response || is_wp_error( $response ) ) {
          $data = false;
          
          if (WP_IMAGE_SERVICE_DEBUG) {   
            echo "response from post is null or error: <br />";
            print_r($response);
            echo "<br />";
          }
        } else {
          if (WP_IMAGE_SERVICE_DEBUG) {   
            echo "response from post is ok: <br />";
            print_r($response);
            echo "<br />";
           }
          $data = wp_remote_retrieve_body( $response );
 
          // The server has an idle timer that shuts down the service when it is idle for a specified amount of time.
          // When the server wakes up due to an incoming request, the response contains additional data in the body of
          // the response message.  This additional data is HTML data that needs to be stripped out of the response.
          $position = strpos( $data, '<!DOCTYPE' );
          if ( $position !== false ) {
            $data = substr( $data, 0, $position);
          }
        }
      } else {
          $data = false;
          wp_die( __('WP Prizm Image requires WordPress 2.8 or greater', WP_IMAGE_SERVICE_DOMAIN) );
      }
    }

    return $data;
  }
  
  /**
   * Compress an image with the image service.
   *
   * @param   string          $file_id     ID of the file to compress
   * @return  string|boolean  Returns the JSON response on success or else false
   */
  function _compress( $file_id  ) {
  
    // Is the remove metadata user option set?
    if ( WP_IMAGE_SERVICE_REMOVE_METADATA == 'on' ) {
      $remove_metadata = 'true';
    } else {
      $remove_metadata = 'false';
    }
    
    // Determine the jpeg mode setting.
    switch (WP_IMAGE_SERVICE_JPEG_MODE) {
      case WP_IMAGE_SERVICE_JPEG_MODE_SEQUENTIAL:
        $jpeg_mode = 'sequential';
        break;
      case WP_IMAGE_SERVICE_JPEG_MODE_PROGRESSIVE;
        $jpeg_mode = 'progressive';
        break;
      case WP_IMAGE_SERVICE_JPEG_MODE_PRESERVE;
        $jpeg_mode = 'preserve';
        break;
    }

    // Format the request message.
    $req = sprintf( IMAGE_SERVICE_COMPRESS_URL, $file_id, strval(WP_IMAGE_SERVICE_QUALITY), $remove_metadata, $jpeg_mode);
    
    $data = false;
    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: Calling wp_remote_get to compress file: [". $req."]<br />";
    }
    if ( function_exists( 'wp_remote_get' ) ) {
      $response = wp_remote_get( $req, array( 'user-agent' => WP_IMAGE_SERVICE_UA,
                                               'timeout' => WP_IMAGE_SERVICE_TIMEOUT ) );
      if ( !$response || is_wp_error( $response ) ) {
        $data = false;
      } else {
        $data = wp_remote_retrieve_body( $response );
      }
    } else {
      wp_die( __('WP Prizm Image requires WordPress 2.8 or greater', WP_IMAGE_SERVICE_DOMAIN) );
    }
    return $data;
  }
  
    /**
   * Download a compressed image from the image service.
   *
   * @param   string             $file_id     ID of the compressed file
   * @return  filename|WP_Error  Returns the JSON response on success or else a WP Error
   */
  function _download( $file_id  ) {
    $req = sprintf( IMAGE_SERVICE_DOWNLOAD_URL, $file_id);
    
    // Create a temp file to contain the reduced file retrieved from the web service.
    $tmpfname = wp_tempnam($file_id );
    if ( ! $tmpfname ) {
      return new WP_Error('http_no_file', __('Could not create Temporary file.'));
    }

    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: Calling wp_remote_get to download file: [". $req."]<br />";
    }

    if ( function_exists( 'wp_remote_get' ) ) {                                         
      $response = wp_remote_get( $req, array( 'user-agent' => WP_IMAGE_SERVICE_UA,
                                               'timeout'    => WP_IMAGE_SERVICE_TIMEOUT,  
                                               'stream'     => true, 
                                               'filename'   => $tmpfname ) );
                                               
      if ( !$response || is_wp_error( $response ) ) {
        unlink( $tmpfname );
        $tmpfname = $response;
      } else {
          if ( 200 != wp_remote_retrieve_response_code( $response ) ){
            unlink( $tmpfname );
            $tmpfname =  new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
          }
      }
    } else {
      unlink( $tmpfname );
      wp_die( __('WP Prizm Image requires WordPress 2.8 or greater', WP_IMAGE_SERVICE_DOMAIN) );
    }

    return $tmpfname;
  }
  
  
  /**
   * Check the progress of an image service operation.
   *
   * @param   string          $file_id    ID of the file to be checked
   * @return  string|boolean  Returns the JSON response on success or else false
   */
  function _check_progress( $file_id  ) {
    $req = sprintf( IMAGE_SERVICE_PROGRESS_URL, $file_id);
    
    $data = false;
    if (WP_IMAGE_SERVICE_DEBUG) {   
      echo "DEBUG: Calling wp_remote_get to check progress [". $req."]<br />";
    }
    if ( function_exists( 'wp_remote_get' ) ) {
      $response = wp_remote_get( $req, array( 'user-agent' => WP_IMAGE_SERVICE_UA,
                                               'timeout' => WP_IMAGE_SERVICE_TIMEOUT ) );
      if ( !$response || is_wp_error( $response ) ) {
        $data = false;
      } else {
        $data = wp_remote_retrieve_body( $response );
      }
    } else {
      wp_die( __('WP Image Service requires WordPress 2.8 or greater', WP_IMAGE_SERVICE_DOMAIN) );
    }

    return $data;
  }
  
    /**
   * Return the filesize in a humanly readable format.
   * Taken from http://www.php.net/manual/en/function.filesize.php#91477
   */
  function format_bytes( $bytes, $precision = 2 ) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
  }
  
  /**
   * Print column header for Prizm Image on the Media Page.  This results in the media library using
   * the `manage_media_columns` hook.
   */
  function columns( $defaults ) {
    $defaults['image_service'] = 'Prizm Image';
    return $defaults;
  }
  
  /**
   * Print column data for Prizm Image results in the media library using
   * the `manage_media_custom_column` hook.
   */
  function custom_column( $column_name, $id ) {
    if( 'image_service' == $column_name ) {
      $data = wp_get_attachment_metadata($id);
      if ( isset( $data['wp_image_service'] ) && !empty( $data['wp_image_service'] ) ) {
        print $data['wp_image_service'];
        printf( "<br><a href=\"admin.php?action=wp_image_service_manual&amp;attachment_ID=%d\">%s</a>",
             $id,
             __( 'Rerun Prizm Image', WP_IMAGE_SERVICE_DOMAIN ) );
      } else {
        if ( wp_attachment_is_image( $id ) ) {
        print __( 'Not processed', WP_IMAGE_SERVICE_DOMAIN );
        printf( "<br><a href=\"admin.php?action=wp_image_service_manual&amp;attachment_ID=%d\">%s</a>",
             $id,
             __('Run Prizm Image', WP_IMAGE_SERVICE_DOMAIN));
        }
      }
    }
  }

  // Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/
  function add_bulk_actions_via_javascript() { ?>
    <script type="text/javascript">
      jQuery(document).ready(function($){
        $('select[name^="action"] option:last-child').before('<option value="bulk_image_service">Bulk Prizm Image</option>');
      });
    </script>
  <?php }


  // Handles the bulk actions POST
  // Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/
  function bulk_action_handler() {
    check_admin_referer( 'bulk-media' );

    if ( empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] ) )
      return;

    $ids = implode( ',', array_map( 'intval', $_REQUEST['media'] ) );

    // Can't use wp_nonce_url() as it escapes HTML entities
    wp_redirect( add_query_arg( 'wp_image_service_nonce', wp_create_nonce( 'wp-image_service-bulk' ), admin_url( 'upload.php?page=wp-image_service-bulk&goback=1&ids=' . $ids ) ) );
    exit();
  }
  
}

$WpImageService = new AccusoftImageService();
global $WpImageService;

}

?>
