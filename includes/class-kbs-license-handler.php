<?php
/**
 * License handler for KB Support
 *
 * Taken from Easy Digital Downloads.
 *
 * This class should simplify the process of adding license information
 * to new KBS extensions.
 *
 * @version 1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

if ( ! class_exists( 'KBS_License' ) )	{

	/**
	 * KBS_License Class
	 */
	class KBS_License {
		private $file;
		private $license;
		private $item_name;
		private $item_id;
		private $item_shortname;
		private $version;
		private $author;
		private $api_url = 'https://kb-support.com/edd-sl-api/';
	
		/**
		 * Class constructor
		 *
		 * @param	str		$_file
		 * @param	str		$_item
		 * @param	str		$_version
		 * @param	str		$_author
		 * @param	str		$_optname
		 * @param	str		$_api_url
		 */
		function __construct( $_file, $_item, $_version, $_author, $_optname = null, $_api_url = null ) {
	
			$this->file           = $_file;
	
			if( is_numeric( $_item ) )	{
				$this->item_id    = absint( $_item );
			} else {
				$this->item_name  = $_item;
			}
	
			$this->item_shortname = 'kbs_' . preg_replace( '/[^a-zA-Z0-9_\s]/', '', str_replace( ' ', '_', strtolower( $this->item_name ) ) );
			$this->version        = $_version;
			$this->license        = trim( kbs_get_option( $this->item_shortname . '_license_key', '' ) );
			$this->author         = $_author;
			$this->api_url        = is_null( $_api_url ) ? $this->api_url : $_api_url;
	
			/**
			 * Allows for backwards compatibility with old license options,
			 * i.e. if the plugins had license key fields previously, the license
			 * handler will automatically pick these up and use those in lieu of the
			 * user having to reactive their license.
			 */
			if ( ! empty( $_optname ) ) {
				$opt = kbs_get_option( $_optname, false );
	
				if( isset( $opt ) && empty( $this->license ) ) {
					$this->license = trim( $opt );
				}
			}
	
			// Setup hooks
			$this->includes();
			$this->hooks();
	
		} // __construct
	
		/**
		 * Include the updater class
		 *
		 * @access  private
		 * @since	1.0
		 * @return  void
		 */
		private function includes() {
			if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) )  {
				require_once 'EDD_SL_Plugin_Updater.php';
			}
		} // includes
	
		/**
		 * Setup hooks
		 *
		 * @access  private
		 * @since	1.0
		 * @return  void
		 */
		private function hooks() {
	
			// Register settings
			add_filter( 'kbs_settings_licenses', array( $this, 'settings' ), 1 );

			// Remove installed premium extensions from plugin upsells
            add_filter( 'kbs_upsell_extensions_settings', array( $this, 'filter_upsells' ) );

			// Display help text at the top of the Licenses tab
			add_action( 'kbs_settings_tab_top', array( $this, 'license_help_text' ) );
	
			// Activate license key on settings save
			add_action( 'admin_init', array( $this, 'activate_license' ) );
	
			// Deactivate license key
			add_action( 'admin_init', array( $this, 'deactivate_license' ) );
	
			// Check that license is valid once per week
			add_action( 'kbs_weekly_scheduled_events', array( $this, 'weekly_license_check' ) );
	
			// For testing license notices, uncomment this line to force checks on every page load
			//add_action( 'admin_init', array( $this, 'weekly_license_check' ) );
	
			// Updater
			add_action( 'admin_init', array( $this, 'auto_updater' ), 0 );
	
			// Display notices to admins
			add_action( 'admin_notices', array( $this, 'notices' ) );
	
			add_action( 'in_plugin_update_message-' . plugin_basename( $this->file ), array( $this, 'plugin_row_license_missing' ), 10, 2 );
	
		}
	
		/**
		 * Auto updater
		 *
		 * @access  private
		 * @since	1.0
		 * @return  void
		 */
		public function auto_updater() {
	
			$args = array(
				'version'   => $this->version,
				'license'   => $this->license,
				'author'    => $this->author
			);
	
			if ( ! empty( $this->item_id ) )	{
				$args['item_id']   = $this->item_id;
			} else {
				$args['item_name'] = $this->item_name;
			}
	
			// Setup the updater
			$kbs_updater = new EDD_SL_Plugin_Updater(
				$this->api_url,
				$this->file,
				$args
			);
	
		} // auto_updater
	
	
		/**
		 * Add license field to settings
		 *
		 * @access	public
		 * @param	arr		$settings	Array of registered settings
		 * @return	arr		Filtered array of registered settings
		 */
		public function settings( $settings ) {
			$kbs_license_settings = array(
				array(
					'id'      => $this->item_shortname . '_license_key',
					'name'    => sprintf( __( '%1$s', 'kb-support' ), $this->item_name ),
					'desc'    => '',
					'type'    => 'license_key',
					'options' => array( 'is_valid_license_option' => $this->item_shortname . '_license_active' ),
					'size'    => 'regular'
				)
			);
	
			return array_merge( $settings, $kbs_license_settings );
		} // settings

		/**
         * If a premium extension is installed, remove it from the upsells array.
         *
         * @since   1.4.6
         * @param   array   $plugins    Array of available premium extensions
         * @return  array   Array of available premium extensions
         */
        public function filter_upsells( $plugins )  {
            $key = str_replace( 'kbs_', '', $this->item_shortname );

            if ( array_key_exists( $key, $plugins ) )   {
                unset( $plugins[ $key ] );
            }

            return $plugins;
        } // filter_upsells

		/**
		 * Display help text at the top of the Licenses settings tab.
		 *
		 * @access	public
		 * @since   1.0
		 * @param	str		$active_tab		The currently active settings tab
		 * @return	void
		 */
		public function license_help_text( $active_tab = '' ) {
			static $has_ran;

			if ( 'licenses' !== $active_tab ) {
				return;
			}

			if ( ! empty( $has_ran ) ) {
				return;
			}

			$url_args = array(
				'utm_source'   => 'settings',
                'utm_medium'   => 'wp-admin',
                'utm_campaign' => 'licensing',
                'utm_content'  => 'browse_extensions'
			);

            echo '<h1 class="wp-heading-inline">' . __( 'Manage Licenses', 'kb-support' ) . '</h1>';
            printf(
                '<a href="%s" target="_blank" class="page-title-action">%s</a>',
                add_query_arg( $url_args, 'https://kb-support.com/extensions/' ),
                __( 'Visit Extension Store', 'kb-support' )
            );

			printf(
				'<p>' . __( 'Enter your <a href="%s" target="_blank">license keys</a> here to receive updates for extensions you have purchased. If your license key has expired, please <a href="%s" target="_blank">renew your license</a>.', 'kb-support' ) . '</p>',
				'https://kb-support.com/your-account/',
				'https://kb-support.com/articles/software-license-renewals-extensions/'
			);

			$has_ran = true;
		} // license_help_text
	
		/**
		 * Activate the license key
		 *
		 * @access	public
		 * @since	1.0
		 * @return	void
		 */
		public function activate_license() {
	
			if ( ! isset( $_POST['kbs_settings'] ) ) {
				return;
			}
	
			if ( ! isset( $_REQUEST[ $this->item_shortname . '_license_key-nonce'] ) || ! wp_verify_nonce( $_REQUEST[ $this->item_shortname . '_license_key-nonce'], $this->item_shortname . '_license_key-nonce' ) ) {
	
				return;
	
			}
	
			if ( ! current_user_can( 'manage_ticket_settings' ) ) {
				return;
			}
	
			if ( empty( $_POST['kbs_settings'][ $this->item_shortname . '_license_key'] ) ) {
	
				delete_option( $this->item_shortname . '_license_active' );
	
				return;
	
			}
	
			foreach ( $_POST as $key => $value ) {
				if ( false !== strpos( $key, 'license_key_deactivate' ) ) {
					// Don't activate a key when deactivating a different key
					return;
				}
			}
	
			$details = get_option( $this->item_shortname . '_license_active' );
	
			if ( is_object( $details ) && 'valid' === $details->license ) {
				return;
			}
	
			$license = sanitize_text_field( $_POST['kbs_settings'][ $this->item_shortname . '_license_key'] );
	
			if ( empty( $license ) ) {
				return;
			}
	
			// Data to send to the API
			$api_params = array(
				'edd_action' => 'activate_license',
				'license'    => $license,
				'item_name'  => urlencode( $this->item_name ),
				'url'        => home_url()
			);
	
			// Call the API
			$response = wp_remote_post(
				$this->api_url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'body'      => $api_params
				)
			);
	
			// Make sure there are no errors
			if ( is_wp_error( $response ) ) {
				return;
			}
	
			// Tell WordPress to look for updates
			set_site_transient( 'update_plugins', null );
	
			// Decode license data
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
	
			update_option( $this->item_shortname . '_license_active', $license_data );
	
		} // activate_license
	
	
		/**
		 * Deactivate the license key
		 *
		 * @access	public
		 * @since	1.0
		 * @return	void
		 */
		public function deactivate_license() {
	
			if ( ! isset( $_POST['kbs_settings'] ) )
				return;
	
			if ( ! isset( $_POST['kbs_settings'][ $this->item_shortname . '_license_key'] ) )
				return;
	
			if ( ! wp_verify_nonce( $_REQUEST[ $this->item_shortname . '_license_key-nonce'], $this->item_shortname . '_license_key-nonce' ) ) {
	
				wp_die( __( 'Nonce verification failed', 'kb-support' ), __( 'Error', 'kb-support' ), array( 'response' => 403 ) );
	
			}
	
			if ( ! current_user_can( 'manage_ticket_settings' ) ) {
				return;
			}
	
			// Run on deactivate button press
			if ( isset( $_POST[ $this->item_shortname . '_license_key_deactivate'] ) ) {
	
				// Data to send to the API
				$api_params = array(
					'edd_action' => 'deactivate_license',
					'license'    => $this->license,
					'item_name'  => urlencode( $this->item_name ),
					'url'        => home_url()
				);
	
				// Call the API
				$response = wp_remote_post(
					$this->api_url,
					array(
						'timeout'   => 15,
						'sslverify' => false,
						'body'      => $api_params
					)
				);
	
				// Make sure there are no errors
				if ( is_wp_error( $response ) ) {
					return;
				}
	
				// Decode the license data
				$license_data = json_decode( wp_remote_retrieve_body( $response ) );
	
				delete_option( $this->item_shortname . '_license_active' );
	
			}
	
		} // deactivate_license
	
	
		/**
		 * Check if license key is valid once per week
		 *
		 * @access	public
		 * @since	1.0
		 * @return	void
		 */
		public function weekly_license_check()	{
	
			if ( ! empty( $_POST['kbs_settings'] ) ) {
				return; // Don't fire when saving settings
			}
	
			if ( empty( $this->license ) )	{
				return;
			}
	
			// data to send in our API request
			$api_params = array(
				'edd_action' => 'check_license',
				'license' 	=> $this->license,
				'item_name'  => urlencode( $this->item_name ),
				'url'        => home_url()
			);
	
			// Call the API
			$response = wp_remote_post(
				$this->api_url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'body'      => $api_params
				)
			);
	
			// make sure the response came back okay
			if ( is_wp_error( $response ) ) {
				return false;
			}
	
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );
	
			update_option( $this->item_shortname . '_license_active', $license_data );
	
		} // weekly_license_check
	
	
		/**
		 * Admin notices for errors
		 *
		 * @access	public
		 * @since	1.0
		 * @return	void
		 */
		public function notices() {
	
			static $showed_invalid_message;
	
			if ( empty( $this->license ) ) {
				return;
			}
	
			if ( ! current_user_can( 'manage_ticket_settings' ) ) {
				return;
			}
	
			$messages = array();
	
			$license = get_option( $this->item_shortname . '_license_active' );
	
			if ( is_object( $license ) && 'valid' !== $license->license && empty( $showed_invalid_message ) ) {
	
				if ( empty( $_GET['tab'] ) || 'licenses' !== $_GET['tab'] ) {
	
					$messages[] = sprintf(
						__( 'You have invalid or expired license keys for KB Support. Please go to the <a href="%s">Licenses page</a> to correct this issue.', 'kb-support' ),
						admin_url( 'edit.php?post_type=kbs_ticket&page=kbs-settings&tab=licenses' )
					);
	
					$showed_invalid_message = true;
	
				}
	
			}
	
			if ( ! empty( $messages ) ) {
	
				foreach( $messages as $message ) {
	
					echo '<div class="error">';
						echo '<p>' . $message . '</p>';
					echo '</div>';
	
				}
	
			}
	
		} // notices
	
		/**
		 * Displays message inline on plugin row that the license key is missing
		 *
		 * @access	public
		 * @since	1.0
		 * @return	void
		 */
		public function plugin_row_license_missing( $plugin_data, $version_info ) {
	
			static $showed_imissing_key_message;
	
			$license = get_option( $this->item_shortname . '_license_active' );
	
			if ( ( ! is_object( $license ) || 'valid' !== $license->license ) && empty( $showed_imissing_key_message[ $this->item_shortname ] ) ) {
				echo '&nbsp;<strong><a href="' . esc_url( admin_url( 'edit.php?post_type=kbs_ticket&page=kbs-settings&tab=licenses' ) ) . '">' . __( 'Enter a valid license key for automatic updates.', 'kb-support' ) . '</a></strong>';
				$showed_imissing_key_message[ $this->item_shortname ] = true;
			}
	
		} // plugin_row_license_missing
	} // KBS_License

} // end class_exists check
