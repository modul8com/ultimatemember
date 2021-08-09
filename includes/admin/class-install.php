<?php namespace um\admin;


if ( ! defined( 'ABSPATH' ) ) exit;


if ( ! class_exists( 'um\admin\Install' ) ) {


	/**
	 * Class Install
	 *
	 * @since 3.0
	 *
	 * @package um\admin
	 */
	class Install {


		/**
		 * @var bool
		 */
		var $install_process = false;


		/**
		 * Install constructor.
		 */
		function __construct() {
		}


		/**
		 * Plugin Activation
		 *
		 * @since 3.0
		 */
		function activation() {
			$this->install_process = true;

			$this->single_site_activation();
			if ( is_multisite() ) {
				update_network_option( get_current_network_id(), 'um_maybe_network_wide_activation', 1 );
			}

			$this->install_process = false;
		}


		/**
		 * Check if plugin is network activated make the first installation on all blogs
		 *
		 * @since 3.0
		 */
		function maybe_network_activation() {
			$maybe_activation = get_network_option( get_current_network_id(), 'um_maybe_network_wide_activation' );

			if ( $maybe_activation ) {

				delete_network_option( get_current_network_id(), 'um_maybe_network_wide_activation' );

				if ( is_plugin_active_for_network( um_plugin ) ) {
					// get all blogs
					$blogs = get_sites();
					if ( ! empty( $blogs ) ) {
						foreach( $blogs as $blog ) {
							switch_to_blog( $blog->blog_id );
							//make activation script for each sites blog
							$this->single_site_activation();
							restore_current_blog();
						}
					}
				}
			}
		}


		/**
		 * Single site plugin activation handler
		 *
		 * @since 3.0
		 */
		function single_site_activation() {
			//first install
			$version = get_option( 'um_version' );
			if ( ! $version ) {
				update_option( 'um_last_version_upgrade', ultimatemember_version );
				add_option( 'um_first_activation_date', time() );
			} else {
				UM()->options()->update( 'rest_api_version', '1.0' ); // legacy for old customers
			}

			if ( $version !== ultimatemember_version ) {
				update_option( 'um_version', ultimatemember_version );
			}

			//run setup
			UM()->common()->create_post_types();

			$this->create_db();
			$this->set_default_settings();
			$this->set_default_roles_meta();
			$this->set_default_user_status();

			if ( ! get_option( 'um_is_installed' ) ) {
				$this->create_forms();
				$this->create_member_directory();

				update_option( 'um_is_installed', 1 );
			}
		}


		/**
		 * Create custom DB tables
		 *
		 * @since 3.0
		 */
		function create_db() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$wpdb->prefix}um_metadata (
umeta_id bigint(20) unsigned NOT NULL auto_increment,
user_id bigint(20) unsigned NOT NULL default '0',
um_key varchar(255) default NULL,
um_value longtext default NULL,
PRIMARY KEY  (umeta_id),
KEY user_id_indx (user_id),
KEY meta_key_indx (um_key),
KEY meta_value_indx (um_value(191))
) $charset_collate;";

			/** @noinspection PhpIncludeInspection */
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}


		/**
		 * Set default UM settings
		 *
		 * @since 3.0
		 */
		function set_default_settings() {
			$options = get_option( 'um_options', array() );

			foreach ( UM()->config()->get( 'default_settings' ) as $key => $value ) {
				//set new options to default
				if ( ! isset( $options[ $key ] ) ) {
					$options[ $key ] = $value;
				}
			}

			update_option( 'um_options', $options );
		}


		/**
		 * Set default UM role settings
		 * for existed WP native roles
		 *
		 * @since 3.0
		 */
		function set_default_roles_meta() {
			foreach ( UM()->config()->get( 'roles_meta' ) as $role => $meta ) {
				add_option( "um_role_{$role}_meta", $meta );
			}
		}


		/**
		 * Set accounts without account_status meta to 'approved' status
		 *
		 * @since 3.0
		 */
		function set_default_user_status() {
			$args = array(
				'fields'     => 'ids',
				'number'     => 0,
				'meta_query' => array(
					array(
						'key'     => 'account_status',
						'compare' => 'NOT EXISTS',
					),
				),
			);

			$users = new \WP_User_Query( $args );
			if ( empty( $users ) || is_wp_error( $users ) ) {
				return;
			}

			$result = $users->get_results();
			if ( empty( $result ) ) {
				return;
			}

			foreach ( $result as $user_id ) {
				update_user_meta( $user_id, 'account_status', 'approved' );
			}
		}


		/**
		 * Install Default Core Forms
		 *
		 * @since 3.0
		 */
		function create_forms() {
			foreach ( UM()->config()->get( 'form_meta' ) as $id => $meta ) {
				/**
				If page does not exist
				Create it
				 **/
				$page_exists = UM()->query()->find_post_id( 'um_form', '_um_core', $id );
				if ( $page_exists ) {
					continue;
				}

				$title = array_key_exists( 'title', $meta ) ? $meta['title'] : '';
				unset( $meta['title'] );

				$form = array(
					'post_type'   => 'um_form',
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_author' => get_current_user_id(),
					'meta_input'  => $meta,
				);

				$form_id = wp_insert_post( $form );
				if ( is_wp_error( $form_id ) ) {
					continue;
				}

				$core_forms[ $id ] = $form_id;
			}

			if ( ! isset( $core_forms ) ) {
				return;
			}

			update_option( 'um_core_forms', $core_forms );
		}


		/**
		 * Create first install member directory
		 */
		function create_member_directory() {
			/**
			If page does not exist
			Create it
			 **/
			$page_exists = UM()->query()->find_post_id( 'um_directory', '_um_core', 'members' );
			if ( $page_exists ) {
				return;
			}

			$form = array(
				'post_type'   => 'um_directory',
				'post_title'  => __( 'Members', 'ultimate-member' ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
				'meta_input'  => UM()->config()->get( 'default_member_directory_meta' ),
			);

			$form_id = wp_insert_post( $form );
			if ( is_wp_error( $form_id ) ) {
				return;
			}

			update_option( 'um_core_directories', array( $form_id ) );
		}

		/**
		 * Install Core Pages
		 *
		 * @since 3.0
		 */
		function predefined_pages() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			//Install Core Pages
			$predefined_pages = array();
			foreach ( UM()->config()->get( 'predefined_pages' ) as $slug => $data ) {

				$page_exists = UM()->query()->find_post_id( 'page', '_um_core', $slug );
				if ( $page_exists ) {
					$predefined_pages[ $slug ] = $page_exists;
					continue;
				}

				$content = apply_filters( 'um_setup_predefined_page_content', $data['content'], $slug );

				$user_page = array(
					'post_title'     => $data['title'],
					'post_content'   => $content,
					'post_name'      => $slug,
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_author'    => get_current_user_id(),
					'comment_status' => 'closed',
				);

				$post_id = wp_insert_post( $user_page );
				if ( empty( $post_id ) || is_wp_error( $post_id ) ) {
					continue;
				}

				update_post_meta( $post_id, '_um_core', $slug );

				UM()->options()->update( UM()->options()->get_predefined_page_option_key( $slug ), $post_id );
			}

			// reset rewrite rules after first install of core pages
			UM()->rewrite()->reset_rules();
		}

	}
}