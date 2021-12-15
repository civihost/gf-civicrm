<?php
/**
 * Plugin Name: GF CiviCRM
 * Description: CiviCRM integration for Gravity Forms and Elementor PRO.
 * Version: 0.1.0
 * Author: Samuele Masetto
 * Author URI: https://github.com/civihost
 * Plugin URI: https://github.com/civihost/gf-civicrm
 * GitHub Plugin URI: civihost/gf-civicrm
 * Text Domain: gf-civicrm
 * Domain Path: /languages
 */
require_once  __DIR__  . '/vendor/autoload.php';

use Symfony\Component\Yaml\Parser;

$yaml = new Symfony\Component\Yaml\Parser();
$settings = $yaml->parse(file_get_contents(__DIR__  . '/settings.yaml'));


/**
 * Elementor Pro webohooks
 */

/**
 * Elementor Pro new record webhook
 */
add_action( 'elementor_pro/forms/new_record', function($record, $handler) use($settings) {
    //make sure its our form
    $form_name = $record->get_form_settings( 'form_name' );
    error_log('elementor ' . $form_name);

    if (! isset($settings['elementor'][$form_name])) {
        return;
    }

    $config = $settings['elementor'][$form_name];


    $raw_fields = $record->get('fields');
    $entry = [];
    foreach ( $raw_fields as $id => $field ) {
        $entry[$id] = $field['value'];
    }

    $params = createContactParams($entry, $config);
    error_log('elementor ' . $form_name . ' ' . print_r($params, true));

    try {
        $contacts = civicrm_api3('Contact', 'create', $params);
    } catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        error_log('gf-civicrm plugin error: ' . print_r($error, true));
    }

}, 10, 2);


/**
 * GravityForms filters and actions
 */

 /**
  * GravityForms Standard After submission
  */
add_action('gform_after_submission', function ($entry, $form) use($settings)  {
    if (! isset($settings['gravity_forms'][$form['id']])) {
        return;
    }
    $config = $settings['gravity_forms'][$form['id']];

    $params = createContactParams($entry, $config);
    error_log('gform_after_submission id form' . $form['id'] . ' ' . print_r($params, true));

    try {
        $contacts = civicrm_api3('Contact', 'create', $params);
    } catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        error_log('gf-civicrm plugin error: ' . print_r($error, true));
    }

}, 10, 2);

/**
 * GravityForms Paypal filter to change IPN and create contact and contribution in CiviCRM
 */
add_filter('gform_paypal_request', function ($url, $form, $entry) use($settings) {

    if (! isset($settings['gravity_forms'][$form['id']])) {
        return $url;
    }
    $form_settings = $settings['gravity_forms'][$form['id']];

    //parse url into its individual pieces (host, path, querystring, etc.)
    $url_array = parse_url( $url );
    //start rebuilding url
    $new_url = $url_array['scheme'] . '://' . $url_array['host'] . $url_array['path'] . '?';
    $query = $url_array['query']; //get querystring
    //parse querystring into pieces
    parse_str( $query, $qs_param );
    $qs_param['notify_url'] = $settings['paypal_ipn']; // update notify_url querystring parameter to new value

    $contribution = createContactFromGF($entry, $form_settings, 'paypal');
    if ($contribution) {
        $qs_param['custom'] = json_encode($contribution);
        $qs_param['invoice'] = $contribution['invoice_id'];
        if (isset($form_settings['contribution']['entries']['frequency_unit'])) {
            $frequency = $entry[$form_settings['contribution']['entries']['frequency_unit']];
            if (in_array($frequency, ['M', 'A', 'D'])) {
                $qs_param['t3'] = $frequency;
            }
        }
    }

    error_log('parametri paypal:' . print_r($qs_param, true));

    $new_qs = http_build_query( $qs_param ); //rebuild querystring
    $new_url .= $new_qs; //add querystring to url
    return $new_url;

}, 10, 3 );

/**
 * Triggers when a payment has been completed through the form
 * Gravity Forms Stripe filter fired when **single payment was performed**
 * Creates CiviCRM contact and contribution
 */
add_action('gform_post_payment_completed', function($entry, $action) use($settings) {
    if (! isset($settings['gravity_forms'][$entry['form_id']])) {
        return;
    }
    $form_settings = $settings['gravity_forms'][$entry['form_id']];

    $contribution = createContactFromGF($entry, $form_settings, 'stripe', $action);

}, 10, 2);


add_action('gform_post_subscription_started', function($entry, $subscription) use($settings) {
    error_log('gform_post_add_subscription_payment entry: ' . print_r($entry, true));
    error_log('gform_post_add_subscription_payment action:' . print_r($subscription, true));
    $form_settings = $settings['gravity_forms'][$entry['form_id']];
    $params = createContactParams($entry, $form_settings);
    try {
        $contact = civicrm_api3('Contact', 'create', $params);
        $contact = array_values($contact['values'])[0];
        $params = [
            'subscription' => $subscription['subscription_id'],
            'contact_id' => $contact['id'],
            'ppid' => $form_settings['contribution']['stripe']['payment_processor_id'],
            'financial_type_id' => $form_settings['contribution']['financial_type_id'],
            'payment_instrument_id' => $form_settings['contribution']['stripe']['payment_instrument_id'],
            'contribution_source' => $form_settings['contribution']['source'],
        ];
        error_log('Stripe import subscription: ' . print_r($params, true));

        civicrm_api3('Stripe', 'importsubscription', $params);

    } catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        error_log('gf-civicrm plugin error: ' . print_r($error, true));
    }
}, 10, 2);

/**
 * Create a CiviCRM contact with a contribution from Gravity Form form payment with Paypal or Stripe
 *
 * @param array $entry Gravity Form entry object
 * @param array $config Form configuration of this plugin
 * @param string $instrument paypal|stripe
 * @param array|void $action The action that occurred
 * @return void
 */
function createContactFromGF($entry, $config, $instrument = 'paypal', $action = null) {

    $params = createContactParams($entry, $config);

    $contribution_status = 'Pending';
    $transaction_id = '';
    if ($action) {
        error_log('gf-civicrm plugin - completed: ' . print_r($action, true));
        error_log('gf-civicrm plugin - completed: ' . print_r($entry, true));

        $invoice_id = $action['transaction_id'];
        if ($action['is_success']) {
            $contribution_status = 'Completed';
            $transaction_id = $invoice_id;
        }
    } else {
        $invoice_id = 'CH-' . substr(md5(uniqid(rand(), true), 0), 0, 24);
    }

    if (isset($config['contribution'])) {
        $amount = $entry[$config['contribution']['entries']['amount']];
        $is_recurrent = false;

        if (isset($config['contribution']['entries']['frequency_unit'])) {
            $frequency_unit = $entry[$config['contribution']['entries']['frequency_unit']];
            $is_recurrent = in_array($frequency_unit, ['M', 'Y', 'D']);
            if ($is_recurrent) {
                switch($frequency_unit) {
                    case 'M':
                        $frequency_unit = 'month';
                        break;
                    case 'Y':
                        $frequency_unit = 'year';
                        break;
                    case 'D':
                        $frequency_unit = 'day';
                        break;
                }
                $params['api.contribution_recur.create'] = [
                    'contact_id' => '$value.id',
                    'amount' => $amount,
                    'contribution_status_id' => $contribution_status,
                    'frequency_unit' => $frequency_unit,
                    'frequency_interval' => 1,
                    'invoice_id' => $invoice_id,
                    'is_test' => $config['contribution']['is_test'],
                    'financial_type_id' =>  $config['contribution']['financial_type_id'],
                    'payment_instrument_id' => $config['contribution'][$instrument]['payment_instrument_id'],
                    'payment_processor_id' => $config['contribution'][$instrument]['payment_processor_id'],
                ];
            }

        }

        $params['api.contribution.create'] = [
            'contact_id' => '$value.id',
            'total_amount' => $amount,
            'contribution_status_id' => $contribution_status,
            'invoice_id' => $invoice_id,
            'is_test' => $config['contribution']['is_test'],
            'financial_type_id' =>  $config['contribution']['financial_type_id'],
            'payment_instrument_id' => $config['contribution'][$instrument]['payment_instrument_id'],
            'skipLineItem' => 1,
            'receive_date' => current_time("YmdHis"),
        ];
        if ($is_recurrent) {
            $params['api.contribution.create']['contribution_recur_id'] = '$value.api.contribution_recur.create.id';
        }
        if ($transaction_id) {
            $params['api.contribution.create']['trxn_id'] = $transaction_id;
        }
        
        /*
        // Receipt
        $params['api.contribution.sendconfirmation'] = [
            'id'=> '$value.api.contribution.create.id',
        ];
        */

    }

    // {"module":"contribute","contactID":"{contactId}","contributionID":{contributionId},"contributionRecurID":{contributionRecurId}}

    error_log('gf-civicrm plugin parameters before API call: ' . print_r($params, true));

    try {
        $contact = civicrm_api3('Contact', 'create', $params);
        error_log('gf-civicrm plugin result: ' . print_r($contact, true));
        $contact = array_values($contact['values'])[0];
        return [
            'module' => 'contribute',
            'contactID' => $contact['id'],
            'contributionID' => $contact['api.contribution.create']['id'],
            'invoice_id' => $invoice_id,
        ];
    } catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        error_log('gf-civicrm plugin error: ' . print_r($error, true));
    }
}

/**
 * Get the array to create a CiviCRM contact from Gravity Forms form
 *
 * @param array $entry Gravity Form entry object
 * @param array $config Form configuration of this plugin
 * @return array
 */

function createContactParams($entry, $config) {
    $email = $entry[$config['email']['entries']['email']];
    $contact_id = getContactIdFromEmail($email);

    $params = [
        'id' => $contact_id,
        'contact_type' => 'Individual',
    ];
    foreach($config['contact']['entries'] as $name => $e) {
        $params[$name] = $entry[$e];
    }

    if (! $contact_id) {
        $params['api.Email.create'] = [
                'email' => $email,
                'contact_id' => '$value.id',
                'is_primary' => 1,
        ];
    }

    if (isset($config['tags'])) {
        $params['api.EntityTag.create'] = [
            'tag_id' => $config['tags'],
            'contact_id' => '$value.id',
        ];
    }
    
    if (isset($config['activity'])) {
        $activity = [
            'source_contact_id' => '$value.id',
            'activity_type_id' => $config['activity']['type'],
            'target_id' => '$value.id',
        ];
        if (isset($config['activity']['entries'])) {
            foreach($config['activity']['entries'] as $name => $e) {
                $activity[$name] = $entry[$e];
            }            
        }
        if (isset($config['activity']['subject'])) {
            $activity['subject'] = $config['activity']['subject'];
        }
        if (isset($config['activity']['assignee'])) {
            $activity['assignee_id'] = $config['activity']['assignee'];
        }
        if (isset($config['activity']['campaign'])) {
            $activity['campaign_id'] = $config['activity']['campaign'];
        }
        if (isset($config['activity']['scheduled'])) {
            $s = $config['activity']['scheduled'];
            $scheduled_activity = [
                'source_contact_id' => '[ID]',
                'activity_type_id' => $s['type'],
                'target_id' => '[ID]',
                'activity_date_time' => date('Y-m-d', strtotime($s['date'])),
                'status_id' => 'Scheduled',
            ];
            if (isset($s['subject'])) {
                $scheduled_activity['subject'] = $config['activity']['subject'];
            }
            if (isset($s['assignee'])) {
                $scheduled_activity['assignee_id'] = $s['assignee'];
            }
            if (isset($s['campaign'])) {
                $scheduled_activity['campaign_id'] = $s['campaign'];
            }
            if (isset($s['entries'])) {
                foreach($s['entries'] as $name => $e) {
                    $scheduled_activity[$name] = $entry[$e];
                }            
            }
        }
        $params['api.activity.create'] = $activity;

    }

    return $params;
}


function getContactIdFromEmail($email) {
    try {
        $contacts = civicrm_api3('Email', 'get', [
          'email' => $email,
        ]);
        if ($contacts && $contacts['count']) {
            $contacts = array_values($contacts['values']);
            $contact_id = $contacts[0]['contact_id'];
        }
        return $contact_id;
    } catch (CiviCRM_API3_Exception $e) {
       $error = $e->getMessage();
    }
    return 0;
}


/*
add_filter( 'gform_entry_post_save', function($entry, $form) {
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
}, 10,2);
*/
