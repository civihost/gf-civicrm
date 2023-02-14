<?php

namespace GFCiviCRM;

class CiviCRM
{
    public $apiWrapper;

    public function __construct($settings)
    {
        $this->apiWrapper = new CiviCRMApiWrapper($settings);
    }

    /**
     * Create a CiviCRM contact with a contribution from Gravity Form form payment with Paypal or Stripe
     *
     * @param array $entry Gravity Form entry object
     * @param array $config Form configuration of this plugin
     * @param string $instrument paypal|stripe
     * @param array|void $action The action that occurred
     * @return void
     */
    function createContactFromGF($entry, $config, $instrument = 'paypal', $action = null)
    {

        $params = $this->createContactParams($entry, $config);

        $contribution_status = 'Pending';
        $transaction_id = '';
        if ($action) {
            self::log('gf-civicrm plugin - completed: ' . print_r($action, true));
            self::log('gf-civicrm plugin - completed: ' . print_r($entry, true));

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
                    switch ($frequency_unit) {
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

        self::log('gf-civicrm plugin parameters before API call: ' . print_r($params, true));

        try {
            $contact = $this->apiWrapper->civicrm_api3('Contact', 'create', $params);
            self::log('gf-civicrm plugin result: ' . print_r($contact, true));
            $contact = array_values($contact['values'])[0];
            return [
                'module' => 'contribute',
                'contactID' => $contact['id'],
                'contributionID' => $contact['api.contribution.create']['id'],
                'invoice_id' => $invoice_id,
            ];
        } catch (\Exception $e) {
            $error = $e->getMessage();
            self::log('gf-civicrm plugin error: ' . print_r($error, true));
        }
    }


    /**
     * Get the array to create a CiviCRM contact from Gravity Forms form
     *
     * @param array $entry Gravity Form entry object
     * @param array $config Form configuration of this plugin
     * @return array
     */
    public function createContactParams($entry, $config)
    {
        $email = $entry[$config['email']['entries']['email']];
        $contact_id = $this->getContactIdFromEmail($email);

        $params = [
            'id' => $contact_id,
            'contact_type' => 'Individual',
        ];
        foreach ($config['contact']['entries'] as $name => $e) {
            $params[$name] = $entry[$e];
        }

        if (!$contact_id) {
            $params['api.Email.create'] = [
                'email' => $email,
                'contact_id' => '$value.id',
                'is_primary' => 1,
            ];
        }

        if (isset($config['tags'])) {
            $params['api.EntityTag.create'] = [
                'tag_id' => [],
                'contact_id' => '$value.id',
            ];
            if (isset($config['tags']['entries'])) {
                $tags = [];
                foreach ($config['grtagsoups']['entries'] as $name => $e) {
                    if ($entry[$e]) {
                      $tags[] = $entry[$e];
                    }
                }
                $params['api.EntityTag.create']['tag_id'] = array_merge($params['api.EntityTag.create']['group_id'], $tags);
            }
            if (isset($config['tags']['values'])) {
                $params['api.EntityTag.create']['tag_id'] = array_merge($params['api.EntityTag.create']['tag_id'], $config['tags']['values']);
            }
        }

        if (isset($config['groups'])) {
            $params['api.GroupContact.create'] = [
                'group_id' => [],
                'contact_id' => '$value.id',
            ];
            if (isset($config['groups']['entries'])) {
                $groups = [];
                foreach ($config['groups']['entries'] as $name => $e) {
                    if ($entry[$e]) {
                      $groups[] = $entry[$e];
                    }
                }
                $params['api.GroupContact.create']['group_id'] = array_merge($params['api.GroupContact.create']['group_id'], $groups);
            }
            if (isset($config['groups']['values'])) {
                $params['api.GroupContact.create']['group_id'] = array_merge($params['api.GroupContact.create']['group_id'], $config['groups']['values']);
            }
        }

        if (isset($config['activity'])) {
            $activity = [
                'source_contact_id' => '$value.id',
                'activity_type_id' => $config['activity']['type'],
                'target_id' => '$value.id',
            ];
            if (isset($config['activity']['entries'])) {
                foreach ($config['activity']['entries'] as $name => $e) {
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
                    foreach ($s['entries'] as $name => $e) {
                        $scheduled_activity[$name] = $entry[$e];
                    }
                }
            }
            $params['api.activity.create'] = $activity;
        }

        return $params;
    }

    public function getContactIdFromEmail($email)
    {
        try {
            $contacts = $this->apiWrapper->civicrm_api3('Email', 'get', [
                'email' => $email,
            ]);
            if ($contacts && $contacts['count']) {
                $contacts = array_values($contacts['values']);
                return $contacts[0]['contact_id'];
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        return 0;
    }

    public static function log($message)
    {
        if (WP_DEBUG === true) {
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }
    }
}
