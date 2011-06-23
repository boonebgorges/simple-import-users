<?php

/**
 * AD Integration functions for Simple Import Users.
 *
 * It's extremely frustrating to have to do this. If all of the methods and properties in the AD
 * integration plugin were not marked 'protected', I wouldn't have to reproduce all of this stuff
 * here.
 */
 
if ( !method_exists( $AD_Integration_plugin, 'create_user' ) ) :
	
	if ( class_exists( 'BLSCI_AD_Fix' ) ) {
		class SIU_AD_Integration extends BLSCI_AD_Fix {
			public function create_user( $username, $userinfo = false, $display_name = false, $role = '', $password = '', $bulkimport = false ) {
				$ad_settings = get_site_option( 'siu_ad_integration_settings' );
				
				if ( empty( $ad_settings['username'] ) || empty( $ad_settings['password'] ) )
					return false;
				
				// Connect to Active Directory
				try {
					$this->_adldap = @new adLDAP( array(
						"base_dn" => $this->_base_dn, 
						"domain_controllers" => explode(';', $this->_domain_controllers),
						"ad_port" => $this->_port, // AD port
						"use_tls" => $this->_use_tls, // secure?
						"network_timeout" => $this->_network_timeout, // network timeout
						'ad_username' => $ad_settings['username'], 'ad_password' => $ad_settings['password']
					) );
				} catch (Exception $e) {
					$this->_log(ADI_LOG_ERROR,'adLDAP exception: ' . $e->getMessage());
					return false;
				}
				
				// This is where the action is.
				$account_suffixes = explode(";",$this->_account_suffix);
				foreach($account_suffixes AS $account_suffix) {
					$account_suffix = trim($account_suffix);
					$this->_log(ADI_LOG_NOTICE,'trying account suffix "'.$account_suffix.'"');			
					$this->_adldap->set_account_suffix($account_suffix);
										
					$userinfo = $this->_adldap->user_info($username, $this->_all_user_attributes);
				}
				
				return $this->_create_user( $username, $userinfo );
			}
		}
	} else {		
		class SIU_AD_Integration extends ADIntegrationPlugin {
			public function create_user( $username, $userinfo, $display_name, $role = '', $password = '', $bulkimport = false ) {
				return $this->_create_user( $username, $userinfo, $display_name, $role, $password, $bulkimport);
			}
		}
	}
	
	$AD_Integration_plugin = new SIU_AD_Integration;
endif;
 
 
 

/**
 * Create a new WordPress account for the specified username.
 * @param string $username
 * @param array $userinfo
 * @param string $display_name
 * @param string $role
 * @param string $password
 * @return integer user_id
 */
function siu_ad_create_user( $username, $userinfo, $display_name, $role = '', $password = '', $bulkimport = false )
{
	global $wp_version;
	
	
	$info = $this->_create_info_array($userinfo);
	
	// get UPN suffix
	$parts = explode('@',$info['userprincipalname']);
	if (isset($parts[1])) {
		$account_suffix = '@'.$parts[1];
	} else {
		$account_suffix = '';
	}
	
	
	if (isset($info['mail'])) {
		$email = $info['mail'];
	} else {
		$email = '';
	}
	
	if ( $info['mail'] == '' ) 
	{
		if (trim($this->_default_email_domain) != '') {
			$email = $username . '@' . $this->_default_email_domain;
		} else {
			if (strpos($username, '@') !== false) {
				$email = $username;
			}
		}
	}
			
	// append account suffix to new users? 
	if ($this->_append_suffix_to_new_users) {
		$username .= $account_suffix;
	}
	
	$this->_log(ADI_LOG_NOTICE,"Creating user '$username' with following data:\n".
				  "- email         : ".$email."\n".
				  "- first name    : ".$info['givenname']."\n".
				  "- last name     : ".$info['sn']."\n".
				  "- display name  : $display_name\n".
				  "- account suffix: $account_suffix\n".
				  "- role          : $role");
	

	// set local password if needed or on Bulk Import
	if (!$this->_no_random_password || ($bulkimport === true)) {
		$password = $this->_get_password();
		$this->_log(ADI_LOG_DEBUG,'Setting random password.');
	} else {
		$this->_log(ADI_LOG_DEBUG,'Setting local password to the used for this login.');
	}
	
	if (version_compare($wp_version, '3.1', '<')) {
		require_once(ABSPATH . WPINC . DIRECTORY_SEPARATOR . 'registration.php');
	}
	
	if ($this->_duplicate_email_prevention == ADI_DUPLICATE_EMAIL_ADDRESS_ALLOW) {
		if (!defined('WP_IMPORTING')) {
			define('WP_IMPORTING',true); // This is a dirty hack. See wp-includes/registration.php
		}
	}
	
	if ($this->_duplicate_email_prevention == ADI_DUPLICATE_EMAIL_ADDRESS_CREATE) {
		$new_email = $this->_create_non_duplicate_email($email);
		if ($new_email !== $email) {
			$this->_log(ADI_LOG_NOTICE, "Duplicate email address prevention: Email changed from $email to $new_email.");
		}
		$email = $new_email;
	}
	
	// Here we go!
	$return = wp_create_user($username, $password, $email);

	// log errors
	if (is_wp_error($return)) {
		$this->_log(ADI_LOG_ERROR, $return->get_error_message());
	}
	
	$user_id = username_exists($username);
	$this->_log(ADI_LOG_NOTICE,'- user_id       : '.$user_id);
	if ( !$user_id ) {
		// do not die on bulk import
		if (!$bulkimport) {
			$this->_log(ADI_LOG_FATAL,'Error creating user.');
			die("Error creating user!");
		} else {
			$this->_log(ADI_LOG_ERROR,'Error creating user.');
			return false;
		}
	} else {
		if (version_compare($wp_version, '3', '>=')) {
			// WP 3.0 and above
			update_user_meta($user_id, 'first_name', $info['givenname']);
			update_user_meta($user_id, 'last_name', $info['sn']);
			if ($this->_auto_update_description) {
				update_user_meta($user_id, 'description', $info['description']);
			}
		} else {
			// WP 2.x
			update_usermeta($user_id, 'first_name', $info['givenname']);
			update_usermeta($user_id, 'last_name', $info['sn']);
			if ($this->_auto_update_description) {
				update_usermeta($user_id, 'description', $info['description']);
			}
		}
		
		// set display_name
		if ($display_name != '') {
			$return = wp_update_user(array('ID' => $user_id, 'display_name' => $display_name));
		}
		
		// set role
		if ( $role != '' ) 
		{
			$return = wp_update_user(array("ID" => $user_id, "role" => $role));
		}
		
		// Important for SyncBack: store account suffix in user meta
		if (version_compare($wp_version, '3', '>=')) {
			// WP 3.0 and above
			update_user_meta($user_id, 'ad_integration_account_suffix', $account_suffix);
		} else {
			// WP 2.x
			update_usermeta($user_id, 'ad_integration_account_suffix', $account_suffix);
		}

		
		// Update User Meta
		if ($this->_write_usermeta === true) {
			$attributes = $this->_get_attributes_array(); // load attribute informations: type, metakey, description
			foreach($info AS $attribute => $value) {
				// conversion/formatting
				$type = $attributes[$attribute]['type'];
				$metakey = $attributes[$attribute]['metakey'];
				$value = $this->_format_attribute_value($type, $value);
				
				if ((trim($value) != '') || ($this->_usermeta_empty_overwrite == true)) {
					$this->_log(ADI_LOG_DEBUG,"$attribute = $value / type = $type / meta key = $metakey");
					
					// store it
					if (version_compare($wp_version, '3', '>=')) {
						// WP 3.0 and above
						update_user_meta($user_id, $metakey, $value);
					} else {
						// WP 2.x
						update_usermeta($user_id, $metakey, $value);
					}
				} else {
					$this->_log(ADI_LOG_DEBUG,"$attribute is empty. Local value of meta key $metakey left unchanged.");
				}
			}
		}
	}

	
	return $user_id;
}

?>