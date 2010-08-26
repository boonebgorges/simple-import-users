<?php

function ddiu_bp_profile_link( $message, $user_id ) {
	
	$profile_edit_url = bp_core_get_user_domain( $user_id ) . 'profile/edit/';
	
	$p_message .= sprintf( 'Customize your Blogs@Baruch profile at %s

', $profile_edit_url );
			
	return $message . $p_message;	
	
}
add_filter( 'ddiu_bp_filter', 'ddiu_bp_profile_link', 10, 2 );

?>