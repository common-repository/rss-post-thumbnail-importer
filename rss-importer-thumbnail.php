<?php
/*
Plugin Name: RSS Post & Thumbnail Importer
Plugin URI: http://wordpress.org/plugins/rss-post-and-thumbnail-importer/
Description: Import posts and featured images from an RSS feed.
Author: easyvideoplayer
Author URI: https://webactix.com/
Version: 0.1
Stable tag: 0.1
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load image APIs
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * RSS Post & Thumbnail Importer
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * RSS Importer
 *
 * Will process a RSS feed for importing posts into WordPress. This is a very
 * limited importer and should only be used as the last resort, when no other
 * importer is available.
 *
 * @since unknown
 */
if ( class_exists( 'WP_Importer' ) ) {
class RSS_Import_Thumbnail extends WP_Importer {

	var $posts = array();
	var $thumbnails = array();
	var $file;

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import RSS & Thumbnails', 'rss-importer-thumbnail').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! This importer allows you to extract posts from an RSS 2.0 file into your WordPress site. This is useful if you want to import your posts from a system that is not handled by a custom import tool. Pick an RSS file to upload and click Import. Any posts containing a media enclosure will have that image copied to your media library and set as the featured image for that post.', 'rss-importer-thumbnail').'</p>';
		wp_import_upload_form("admin.php?import=rss&amp;step=1");
		echo '</div>';
	}

	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function _download_image ($file, $post_id) {
		if(!empty($file)){
			$file_array['tmp_name'] = download_url($file);
			if(is_wp_error($file_array['tmp_name'])) return;
			$file_array['name'] = basename($file);

			$pathparts = pathinfo($file_array['tmp_name']);
			if (''==$pathparts['extension']) {
				$ext = bml_it_getext($file_array['tmp_name']);
				rename($file_array['tmp_name'], $file_array['tmp_name'] . $ext);
				$file_array['name'] = basename($file_array['tmp_name']) . $ext;
				$file_array['tmp_name'] .= $ext;
			}

			$id = media_handle_sideload($file_array, $post_id, '');
			$src = $id;

			if(is_wp_error($id)) {
				@unlink($file_array['tmp_name']);
				return $id;
			}
		}

		if (!empty($src)) return $src;

		return false;
	}

	function get_posts() {
		global $wpdb;

		set_magic_quotes_runtime(0);
		$datalines = file($this->file); // Read the file into an array
		$importdata = implode('', $datalines); // squish it
		$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);

		preg_match_all('|<item>(.*?)</item>|is', $importdata, $this->posts);
		$this->posts = $this->posts[1];
		$index = 0;
		foreach ($this->posts as $post) {
			preg_match('|<title>(.*?)</title>|is', $post, $post_title);
			$post_title = str_replace(array('<![CDATA[', ']]>'), '', $wpdb->escape( trim($post_title[1]) ));

			preg_match('|<pubdate>(.*?)</pubdate>|is', $post, $post_date_gmt);

			if ($post_date_gmt) {
				$post_date_gmt = strtotime($post_date_gmt[1]);
			} else {
				// if we don't already have something from pubDate
				preg_match('|<dc:date>(.*?)</dc:date>|is', $post, $post_date_gmt);
				$post_date_gmt = preg_replace('|([-+])([0-9]+):([0-9]+)$|', '\1\2\3', $post_date_gmt[1]);
				$post_date_gmt = str_replace('T', ' ', $post_date_gmt);
				$post_date_gmt = strtotime($post_date_gmt);
			}

			$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
			$post_date = get_date_from_gmt( $post_date_gmt );

			preg_match_all('|<category>(.*?)</category>|is', $post, $categories);
			$categories = $categories[1];

			if (!$categories) {
				preg_match_all('|<dc:subject>(.*?)</dc:subject>|is', $post, $categories);
				$categories = $categories[1];
			}

			$cat_index = 0;
			foreach ($categories as $category) {
				$categories[$cat_index] = $wpdb->escape( html_entity_decode( $category ) );
				$cat_index++;
			}

			preg_match('|<guid.*?>(.*?)</guid>|is', $post, $guid);
			if ($guid)
				$guid = $wpdb->escape(trim($guid[1]));
			else
				$guid = '';

			preg_match('|<content:encoded>(.*?)</content:encoded>|is', $post, $post_content);
			$post_content = str_replace(array ('<![CDATA[', ']]>'), '', $wpdb->escape(trim($post_content[1])));

			if (!$post_content) {
				// This is for feeds that put content in description
				preg_match('|<description>(.*?)</description>|is', $post, $post_content);
				$post_content = $wpdb->escape( html_entity_decode( trim( $post_content[1] ) ) );
			}

			// Thumbnail to bring in?
			preg_match('|<media:content url="(.+?)"|', $post, $post_thumbnail);
			if(!empty($post_thumbnail[1])) {
				$this->thumbnails[$index] = $post_thumbnail[1];
			}

			// Clean up content
			$post_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $post_content);
			$post_content = str_replace('<br>', '<br />', $post_content);
			$post_content = str_replace('<hr>', '<hr />', $post_content);

			$post_author = 1;
			$post_status = 'publish';
			$this->posts[$index] = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'guid', 'categories');
			$index++;
		}
	}

	function import_posts() {
		echo '<ol>';

		foreach ($this->posts as $index => $post) {
			echo "<li>".__('Importing post...', 'rss-importer-thumbnail');

			extract($post);

			if ($post_id = post_exists($post_title, $post_content, $post_date)) {
				_e('Post already imported', 'rss-importer-thumbnail');
			} else {
				$post_id = wp_insert_post($post);
				if ( is_wp_error( $post_id ) )
					return $post_id;
				if (!$post_id) {
					_e('Couldn&#8217;t get post ID', 'rss-importer-thumbnail');
					return;
				}

				if (0 != count($categories))
					wp_create_categories($categories, $post_id);

				if(!empty($this->thumbnails[$index])) {
					_e('Downloading thumbnail...', 'rss-importer-thumbnail');
					$imgid = $this->_download_image($this->thumbnails[$index], $post_id);

					if($imgid !== false) {
						_e('Downloaded thumbnail successfully...', 'rss-importer-thumbnail');
						set_post_thumbnail($post_id, $imgid);
					}
				}

				_e('Done!', 'rss-importer-thumbnail');
			}
			echo '</li>';
		}

		echo '</ol>';

	}

	function import() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}

		$this->file = $file['file'];
		$this->get_posts();
		$result = $this->import_posts();
		if ( is_wp_error( $result ) )
			return $result;
		wp_import_cleanup($file['id']);
		do_action('import_done', 'rss');

		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>', 'rss-importer-thumbnail'), get_option('home'));
		echo '</h3>';
	}

	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

		$this->header();

		switch ($step) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$result = $this->import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		$this->footer();
	}

	function RSS_Import_Thumbnail() {
		// Nothing.
	}
}

$rss_import_thumbnail = new RSS_Import_Thumbnail();

register_importer('rss-thumbnail', __('RSS & Thumbnails', 'rss-importer-thumbnail'), __('Import posts and thumbnails from an RSS feed.', 'rss-importer-thumbnail'), array ($rss_import_thumbnail, 'dispatch'));

} // class_exists( 'WP_Importer' )

function rss_importer_thumbnail_init() {
    load_plugin_textdomain( 'rss-importer-thumbnail', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'rss_importer_init' );
