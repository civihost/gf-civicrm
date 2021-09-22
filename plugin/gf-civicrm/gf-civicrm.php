<?php
/**
* Plugin Name: Gf Civicrm
* Plugin URI: http://www.giovannidalmas.it/
* Description: Gravity Forms sends payment to Civicrm
* Version: 1.0
* Author: Giovanni Dal Mas
* Author URI: http://www.giovannidalmas.it/
* Text Domain: gf-civicrm
**/

function civicrm_stripe_session_data( $entry, $form ) {
	$entry=(array) $entry;
	//print_r($entry);
	if(strtolower($entry[14])=="carta di credito"){
		$name=$entry['1.3']." ".$entry['1.6'];
		$periodicita=$entry[16];
		$tipo_pagamento=$entry[14];
		$tipo_carta=$entry['11.4'];
		$num_carta=$entry['11.1'];
		$importo=$entry[5];
		$email=$entry[2];

		$civicrm=array(
			'name'=>$name,
			'email'=>$email,
			'periodicita'=>$periodicita,
			'tipo_pagamento'=>$tipo_pagamento,
			'tipo_carta'=>$tipo_carta,
			'num_carta'=>$num_carta,
			'importo'=>$importo
		);

		print_r($civicrm);

		// CIVICRM stuff
		echo "<br>Stripe<br>";
	}
	echo "Eccomi";

	die;
}
add_filter( 'gform_entry_post_save', 'civicrm_stripe_session_data',10,2);