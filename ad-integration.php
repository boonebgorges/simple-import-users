<?php

/**
 * AD Integration functions for Simple Import Users.
 *
 * It's extremely frustrating to have to do this. If all of the methods and properties in the AD
 * integration plugin were not marked 'protected', I wouldn't have to reproduce all of this stuff
 * here.
 *
 * The strategy is this. I extend the base class to include a public creation method, which uses
 * AD connection information that must be entered by the admin.
 *
 * There are two different cases. One is if you're using the BLSCI AD plugin fixer. The other
 * extends the regular base class.
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
					$this->_adldap = @new SIU_adLDAP( array(
						"base_dn" => $this->_base_dn, 
						"domain_controllers" => explode(';', $this->_domain_controllers),
						"ad_port" => $this->_port, // AD port
						"use_tls" => $this->_use_tls, // secure?
						"network_timeout" => $this->_network_timeout, // network timeout
						"ad_username" => $ad_settings['username'],
						"ad_password" => $ad_settings['password']
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
					
					// Find user by email address
					$un = $this->_adldap->find_user_by_email( $username );

					if ( empty( $un ) )
						return false;

					// Get all userdata
					$userinfo = $this->_adldap->user_info( $un[0], $this->_all_user_attributes );

				}
				
				
				return $this->_create_user( $username, $userinfo[0], false, false, false, true );
			}
		}
	} else {		
		class SIU_AD_Integration extends ADIntegrationPlugin {
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
				
				return $this->_create_user( $username, $userinfo[0], false, false, false, true );
			}
		}
	}
	
	$AD_Integration_plugin = new SIU_AD_Integration;
endif;

class SIU_adLDAP extends adLDAP {
	public function find_user_by_email( $email ) {
		 // Perform the search and grab all their details
		$filter = "(&(objectClass=user)(samaccounttype=". ADLDAP_NORMAL_ACCOUNT .")(objectCategory=person)(mail=".$email."))";
		$fields = array( 
			"samaccountname",
			"displayname",
			'mail',
			'sn',
			'cn'
		);
		
		$sr = ldap_search( $this->_conn, $this->_base_dn, $filter, $fields );
		$entries = ldap_get_entries( $this->_conn, $sr );
	
		$users_array = array();
		for ($i=0; $i<$entries["count"]; $i++){
		    if ($include_desc && strlen($entries[$i]["displayname"][0])>0){
			$users_array[ $entries[$i]["samaccountname"][0] ] = $entries[$i]["displayname"][0];
		    } elseif ($include_desc){
			$users_array[ $entries[$i]["samaccountname"][0] ] = $entries[$i]["samaccountname"][0];
		    } else {
			array_push($users_array, $entries[$i]["samaccountname"][0]);
		    }
		}
		if ($sorted){ asort($users_array); }
		return ($users_array);
	}
}

?>