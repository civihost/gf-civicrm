<?php

/**
 * Plugin Name: GF CiviCRM
 * Description: CiviCRM integration for Gravity Forms and Elementor PRO.
 * Version: 0.2.0
 * Author: Samuele Masetto
 * Author URI: https://github.com/civihost
 * Plugin URI: https://github.com/civihost/gf-civicrm
 * GitHub Plugin URI: civihost/gf-civicrm
 * Text Domain: gf-civicrm
 * Domain Path: /languages
 */

namespace GFCiviCRM;

require_once  __DIR__  . '/vendor/autoload.php';

use \Symfony\Component\Yaml\Parser;

$yaml = new Parser();
$settings = $yaml->parse(file_get_contents(__DIR__  . '/settings.yaml'));

$civicrm = new CiviCRM($settings);

/**
 * Elementor Pro webohooks
 */

/**
 * Elementor Pro new record webhook
 */
add_action('elementor_pro/forms/new_record', function ($record, $handler) use ($settings, $civicrm) {
    //make sure its our form
    $form_name = $record->get_form_settings('form_name');
    $civicrm::log('elementor ' . $form_name);

    if (!isset($settings['elementor'][$form_name])) {
        return;
    }

    $config = $settings['elementor'][$form_name];


    $raw_fields = $record->get('fields');
    $entry = [];
    foreach ($raw_fields as $id => $field) {
        $entry[$id] = $field['value'];
    }

    $params = $civicrm->createContactParams($entry, $config);
    $civicrm::log('elementor ' . $form_name . ' ' . print_r($params, true));

    try {
        $contacts = $civicrm->apiWrapper->civicrm_api3('Contact', 'create', $params);
    } catch (\Exception $e) {
        $error = $e->getMessage();
        $civicrm::log('gf-civicrm plugin error: ' . print_r($error, true));
    }
}, 10, 2);

/**
 * GravityForms filters and actions
 */

/**
 * GravityForms Standard After submission
 */
add_action('gform_after_submission', function ($entry, $form) use ($settings, $civicrm) {
    if (!isset($settings['gravity_forms'][$form['id']])) {
        return;
    }
    $config = $settings['gravity_forms'][$form['id']];

    $params = $civicrm->createContactParams($entry, $config);
    $civicrm::log('gform_after_submission id form' . $form['id'] . ' ' . print_r($params, true));

    try {
        $contacts = $civicrm->apiWrapper->civicrm_api3('Contact', 'create', $params);
    } catch (\Exception $e) {
        $error = $e->getMessage();
        $civicrm::log('gf-civicrm plugin error: ' . print_r($error, true));
    }
}, 10, 2);

/**
 * GravityForms Paypal filter to change IPN and create contact and contribution in CiviCRM
 */
add_filter('gform_paypal_request', function ($url, $form, $entry) use ($settings, $civicrm) {

    if (!isset($settings['gravity_forms'][$form['id']])) {
        return $url;
    }
    $form_settings = $settings['gravity_forms'][$form['id']];

    //parse url into its individual pieces (host, path, querystring, etc.)
    $url_array = parse_url($url);
    //start rebuilding url
    $new_url = $url_array['scheme'] . '://' . $url_array['host'] . $url_array['path'] . '?';
    $query = $url_array['query']; //get querystring
    //parse querystring into pieces
    parse_str($query, $qs_param);
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

    $civicrm::log('PayPal parameters:' . print_r($qs_param, true));

    $new_qs = http_build_query($qs_param); //rebuild querystring
    $new_url .= $new_qs; //add querystring to url
    return $new_url;
}, 10, 3);

/**
 * Triggers when a payment has been completed through the form
 * Gravity Forms Stripe filter fired when **single payment was performed**
 * Creates CiviCRM contact and contribution
 */
add_action('gform_post_payment_completed', function ($entry, $action) use ($settings, $civicrm) {
    if (!isset($settings['gravity_forms'][$entry['form_id']])) {
        return;
    }
    $form_settings = $settings['gravity_forms'][$entry['form_id']];

    $contribution = $civicrm->createContactFromGF($entry, $form_settings, 'stripe', $action);
}, 10, 2);


add_action('gform_post_subscription_started', function ($entry, $subscription) use ($settings, $civicrm) {
    $civicrm::log('gform_post_add_subscription_payment entry: ' . print_r($entry, true));
    $civicrm::log('gform_post_add_subscription_payment action:' . print_r($subscription, true));
    $form_settings = $settings['gravity_forms'][$entry['form_id']];
    $params = $civicrm->createContactParams($entry, $form_settings);
    try {
        $contact = $civicrm->apiWrapper->civicrm_api3('Contact', 'create', $params);
        $contact = array_values($contact['values'])[0];
        $params = [
            'subscription' => $subscription['subscription_id'],
            'contact_id' => $contact['id'],
            'ppid' => $form_settings['contribution']['stripe']['payment_processor_id'],
            'financial_type_id' => $form_settings['contribution']['financial_type_id'],
            'payment_instrument_id' => $form_settings['contribution']['stripe']['payment_instrument_id'],
            'contribution_source' => $form_settings['contribution']['source'],
        ];
        $civicrm::log('Stripe import subscription: ' . print_r($params, true));

        $civicrm->apiWrapper->civicrm_api3('Stripe', 'importsubscription', $params);
    } catch (\Exception $e) {
        $error = $e->getMessage();
        $civicrm::log('gf-civicrm plugin error: ' . print_r($error, true));
    }
}, 10, 2);
