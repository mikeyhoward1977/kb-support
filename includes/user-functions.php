<?php
/**
 * User Functions
 *
 * Functions related to users / customers
 *
 * @package     KBS
 * @subpackage  Functions
 * @copyright   Copyright (c) 2016, Mike Howard
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Retrieve customer tickets.
 *
 * @since	1.0
 * @param	int|obj		$customer	The customer ID or a KBS_Customer object.
 * @param	arr			$args		Args that can be passed to kbs_get_tickets()
 * @param	bool		$can_select	True to only return selectable status. False for all.
 * @return	obj			Array of customer ticket objects.
 */
function kbs_get_customer_tickets( $customer, $args = array(), $can_select = true, $pagination = false )	{

	$customer_id = $customer;

	if ( is_object( $customer ) )	{
		$customer_id = $customer->id;
	}

	if ( empty( $customer_id ) )	{
		return false;
	}

	$ticket_statuses = kbs_get_ticket_statuses( $can_select );

	if ( $pagination )	{
		if ( get_query_var( 'paged' ) )	{
			$paged = get_query_var('paged');
		} else if ( get_query_var( 'page' ) )	{
			$paged = get_query_var( 'page' );
		} else	{
			$paged = 1;
		}
	}

	$defaults = array(
		'customer' => $customer_id,
		'status'   => array_keys( $ticket_statuses ),
		'number'   => 10
	);

	$args = wp_parse_args( $args, $defaults );

	if ( $pagination )	{
		$args['page'] = $paged;
	} else	{
		$args['nopaging'] = true;
	}

	return kbs_get_tickets( $args );

} // kbs_get_customer_tickets

/**
 * Retrieve customer ticket count.
 *
 * @since	1.0
 * @param	int|obj		$customer	The customer ID or a KBS_Customer object.
 * @return	int			Array of customer ticket objects.
 */
function kbs_get_customer_ticket_count( $customer )	{

	$customer_id = $customer;

	if ( is_object( $customer ) )	{
		$customer_id = $customer->id;
	}

	if ( empty( $customer_id ) )	{
		return false;
	}

	$tickets = kbs_count_tickets( array( 'customer' => $customer_id ) );
	$count   = 0;

	if ( ! empty( $tickets ) )	{
		foreach( $tickets as $status )	{
			if ( ! empty( $status ) )	{
				$count += $status;
			}
		}
	}

	return $count;
} // kbs_get_customer_ticket_count

/**
 * Retrieve users by role.
 *
 * @since	1.0
 * @param	str	$role	Name of the role to retrieve.
 * @return	mixed
 */
function kbs_get_users_by_role( $role = array( 'support_agent', 'support_manager' ) )	{
	global $wpdb;

	$user_query = new WP_User_Query( array(
		'orderby'    => 'display_name',
		'role__in' => $role
	) );
	
	$users = $user_query->get_results();
	
	return $users;
} // kbs_get_users_by_role

/**
 * Retrieve all customers.
 *
 * @since	1.0
 * @param
 * @return	mixed
 */
function kbs_get_customers()	{
	$role = array( 'support_customer' );
	$role = apply_filters( 'kbs_customer_roles', $role );

	$customers = kbs_get_users_by_role( $role );
	
	return apply_filters( 'kbs_customers', $customers );
} // kbs_get_customers

/**
 * Counts the total number of customers.
 *
 * @since	1.0
 * @return	int		The total number of customers.
 */
function kbs_count_total_customers( $args = array() ) {
	return KBS()->customers->count( $args );
} // kbs_count_total_customers

/**
 * Retrieve all agents.
 *
 * @since	1.0
 * @param
 * @return	mixed
 */
function kbs_get_agents()	{
	$role  = array( 'support_agent', 'support_manager' );

	if ( kbs_get_option( 'admin_agents', false ) )	{
		$role[] = 'administrator';
	}

	$users = kbs_get_users_by_role( $role );
	
	return $users;
} // kbs_get_agents

/**
 * Validate a potential username
 *
 * @access      public
 * @since       1.0
 * @param       str		$username	The username to validate
 * @return      bool
 */
function kbs_validate_username( $username ) {
	$sanitized = sanitize_user( $username, false );
	$valid     = ( $sanitized == $username );

	return (bool) apply_filters( 'kbs_validate_username', $valid, $username );
} // kbs_validate_username

/**
 * Attach the newly created user_id to a customer, if one exists
 *
 * @since	1.0
 * @param 	int		$user_id	The User ID that was created
 * @return	void
 */
function kbs_connect_existing_customer_to_new_user( $user_id ) {
	$email = get_the_author_meta( 'user_email', $user_id );

	// Update the user ID on the customer
	$customer = new KBS_Customer( $email );

	if( $customer->id > 0 ) {
		$customer->update( array( 'user_id' => $user_id ) );
	}
}
add_action( 'user_register', 'kbs_connect_existing_customer_to_new_user', 10, 1 );

/**
 * Process Profile Updates from the Editor Form
 *
 * @since	1.0
 * @param	arr		$data	Data sent from the profile editor
 * @return void
 */
function kbs_process_profile_editor_updates( $data ) {

	if ( empty( $_POST['kbs_profile_editor_submit'] ) && ! is_user_logged_in() ) {
		return false;
	}

	// Nonce security
	if ( ! wp_verify_nonce( $data['kbs_profile_editor_nonce'], 'kbs-profile-editor-nonce' ) ) {
		return false;
	}

	$user_id       = get_current_user_id();
	$old_user_data = get_userdata( $user_id );

	$display_name = isset( $data['kbs_display_name'] )    ? sanitize_text_field( $data['kbs_display_name'] )    : $old_user_data->display_name;
	$first_name   = isset( $data['kbs_first_name'] )      ? sanitize_text_field( $data['kbs_first_name'] )      : $old_user_data->first_name;
	$last_name    = isset( $data['kbs_last_name'] )       ? sanitize_text_field( $data['kbs_last_name'] )       : $old_user_data->last_name;
	$email        = isset( $data['kbs_email'] )           ? sanitize_email( $data['kbs_email'] )                : $old_user_data->user_email;
	$line1        = isset( $data['kbs_address_line1'] )   ? sanitize_text_field( $data['kbs_address_line1'] )   : '';
	$line2        = isset( $data['kbs_address_line2'] )   ? sanitize_text_field( $data['kbs_address_line2'] )   : '';
	$city         = isset( $data['kbs_address_city'] )    ? sanitize_text_field( $data['kbs_address_city'] )    : '';
	$state        = isset( $data['kbs_address_state'] )   ? sanitize_text_field( $data['kbs_address_state'] )   : '';
	$zip          = isset( $data['kbs_address_zip'] )     ? sanitize_text_field( $data['kbs_address_zip'] )     : '';
	$country      = isset( $data['kbs_address_country'] ) ? sanitize_text_field( $data['kbs_address_country'] ) : '';

	$error    = false;
	$userdata = array(
		'ID'           => $user_id,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => $display_name,
		'user_email'   => $email
	);

	$address = array(
		'line1'    => $line1,
		'line2'    => $line2,
		'city'     => $city,
		'state'    => $state,
		'zip'      => $zip,
		'country'  => $country
	);

	do_action( 'kbs_pre_update_user_profile', $user_id, $userdata );

	if ( ! empty( $data['kbs_new_user_pass1'] ) ) {
		if ( $data['kbs_new_user_pass1'] !== $data['kbs_new_user_pass2'] ) {
			$error = 'password_mismatch';
		} else {
			$userdata['user_pass'] = $data['kbs_new_user_pass1'];
		}
	}

	if ( ! $error && $email != $old_user_data->user_email ) {

		if ( ! is_email( $email ) ) {
			$error = 'email_invalid';
		}

		if ( email_exists( $email ) ) {
			$error = 'email_unavailable';
		}

	}

	$url = remove_query_arg( 'kbs_notice', $data['kbs_redirect'] );

	if ( $error ) {
		$url = add_query_arg( 'kbs_notice', $error, $url );
		wp_safe_redirect( $url );
		die();
	}

	// Process updates
	$updated = wp_update_user( $userdata );

	$customer = new KBS_Customer( $user_id, true );

	if ( ! empty( $address ) )	{
		$meta     = update_user_meta( $user_id, '_kbs_user_address', $address );
		$customer->update_meta( 'address', $address );
	}

	if ( $customer->email === $email || ( is_array( $customer->emails ) && in_array( $email, $customer->emails ) ) ) {
		$customer->set_primary_email( $email );
	};

	if ( $customer->id > 0 ) {
		$update_args = array(
			'name'  => $first_name . ' ' . $last_name,
		);

		$customer->update( $update_args );
	}

	if ( $updated ) {
		do_action( 'kbs_user_profile_updated', $user_id, $userdata );
		wp_safe_redirect( add_query_arg( 'kbs_notice', 'profile_updated', $url ) );
		die();
	}

} // kbs_process_profile_editor_updates
add_action( 'kbs_edit_user_profile', 'kbs_process_profile_editor_updates' );

/**
 * Process the 'remove' email address action on the profile editor form.
 *
 * @since	1.0
 * @return	void
 */
function kbs_process_profile_editor_remove_email() {

	if ( ! is_user_logged_in() ) {
		return false;
	}

	// Nonce security
	if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'kbs-remove-customer-email' ) ) {
		return false;
	}

	if ( empty( $_GET['email'] ) || ! is_email( $_GET['email'] ) ) {
		return false;
	}

	$customer = new KBS_Customer( get_current_user_id(), true );
	$url      = remove_query_arg( 'kbs_notice', $_GET['redirect'] );

	if ( $customer->remove_email( $_GET['email'] ) ) {

		$url = add_query_arg( 'kbs_notice', 'profile_updated', $_GET['redirect'] );

		$user          = wp_get_current_user();
		$user_login    = ! empty( $user->user_login ) ? $user->user_login : 'KBSBot';
		$customer_note = __( sprintf( 'Email address %s removed by %s', $_GET['email'], $user_login ), 'kb-support' );
		$customer->add_note( $customer_note );

		$url = add_query_arg( 'kbs_notice', 'email_removed', $url );

	} else {
		$url = add_query_arg( 'kbs_notice', 'email_remove_failed', $url );
	}

	wp_safe_redirect( $url );
	exit;
} // kbs_process_profile_editor_remove_email
add_action( 'kbs_profile-remove-email', 'kbs_process_profile_editor_remove_email' );
