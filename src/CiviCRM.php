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
    public function createContactFromGF($entry, $config, $instrument = 'paypal', $action = null)
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
            if (isset($config['contribution']['source'])) {
                $params['api.contribution.create']['source'] = $config['contribution']['source'];
            }

            foreach ($config['contribution']['entries'] as $name => $e) {
                if (! empty($entry[$e]) && ! in_array($name, ['amount', 'frequency_unit'])) {
                    $params['api.contribution.create'][$name] = $entry[$e];
                }
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
            if (! empty($entry[$e])) {
                $params[$name] = $entry[$e];
            }
        }

        if (! $contact_id) {
            $params['api.Email.create'] = [
                'email' => $email,
                'contact_id' => '$value.id',
                'is_primary' => 1,
            ];
        }

        if (isset($config['phone'])) {
            $phone = [
                'contact_id' => '$value.id',
            ];
            if (isset($config['phone']['entries'])) {
                foreach ($config['phone']['entries'] as $name => $e) {
                    $phone[$name] = $entry[$e];
                }
            }
            if (isset($config['phone']['phone_type_id'])) {
                $phone['phone_type_id'] = $config['phone']['phone_type_id'];
            }
            if (isset($config['phone']['is_primary'])) {
                $phone['is_primary'] = $config['phone']['is_primary'];
            }
            if (isset($config['phone']['location_type_id'])) {
                $phone['location_type_id'] = $config['phone']['location_type_id'];
            }
            if ($phone['phone']) {
                $params['api.Phone.create'] = $phone;
            }
        }

        if (isset($config['tags'])) {
            $tags = [];
            if (isset($config['tags']['entries'])) {
                foreach ($config['grtagsoups']['entries'] as $name => $e) {
                    if ($entry[$e] && ! $this->tagExists($contact_id, $entry[$e])) {
                        $tags[] = $entry[$e];
                    }
                }
            }
            if (isset($config['tags']['values'])) {
                foreach ($config['tags']['values'] as $tagId) {
                    if (! $this->tagExists($contact_id, $tagId)) {
                        $tags[] = $tagId;
                    }
                }
            }
            if (count($tags) > 0) {
                $params['api.EntityTag.create'] = [
                    'tag_id' => $tags,
                    'contact_id' => '$value.id',
                ];
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

        // MailingEventSubscribe.create API - only one group id
        if (isset($config['subscribe'])) {
            $params['api.MailingEventSubscribe.create'] = [
                'group_id' => [],
                'contact_id' => '$value.id',
                'email' => $email,
            ];
            if (isset($config['subscribe']['entry'])) {
                if ($entry[$config['groups']['entry']]) {
                    $params['api.MailingEventSubscribe.create']['group_id'] = $entry[$config['groups']['entry']];
                }
            }
            if (isset($config['subscribe']['value'])) {
                $params['api.MailingEventSubscribe.create']['group_id'] = $config['subscribe']['value'];
            }
        }

        if (isset($config['activity'])) {
            $params['api.activity.create'] = $this->getActivity($config['activity'], $entry);
        }
        if (isset($config['activities'])) {
            $i = 1;
            foreach ($config['activities'] as $activity) {
                $params['api.activity.create' . ($i === 1 ? '' : ".$i")] = $this->getActivity($activity, $entry);
                $i++;
            }
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

    public function tagExists($entityId, $tagId, $entityTable = 'Contact'): bool
    {
        if (! $entityId) {
            return false;
        }

        $exists = $this->apiWrapper->civicrm_api3('EntityTag', 'get', [
            'entity_id'    => $entityId,
            'entity_table' => $entityTable,
            'tag_id'       => $tagId,
        ]);

        return $exists['count'] > 0;
    }

    protected function getActivity($activityConfig, $entry)
    {
        $activity = [
            'source_contact_id' => '$value.id',
            'activity_type_id' => $activityConfig['type'],
            'target_id' => '$value.id',
        ];

        if (isset($activityConfig['entries'])) {
            foreach ($activityConfig['entries'] as $name => $e) {
                $activity[$name] = $entry[$e];
            }
        }

        if (isset($activityConfig['subject'])) {
            $activity['subject'] = $activityConfig['subject'];
        }
        if (isset($activityConfig['assignee'])) {
            $activity['assignee_id'] = $activityConfig['assignee'];
        }
        if (isset($activityConfig['campaign'])) {
            $activity['campaign_id'] = $activityConfig['campaign'];
        }
        if (isset($activityConfig['details'])) {
            $activity['details'] = $activityConfig['details'];
        }

        if (isset($activityConfig['scheduled'])) {
            $s = $activityConfig['scheduled'];
            $scheduled_activity = [
                'source_contact_id' => '[ID]',
                'activity_type_id' => $s['type'],
                'target_id' => '[ID]',
                'activity_date_time' => date('Y-m-d', strtotime($s['date'])),
                'status_id' => 'Scheduled',
            ];
            if (isset($s['subject'])) {
                $scheduled_activity['subject'] = $activityConfig['subject'];
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

        return $activity;
    }

    /**
     * Handle form pre-rend (`gform_pre_render`) event for Gravity Forms
     *
     * @param array $form The form being processed.
     * @return array
     */
    public function setCountries($form, $addressConfig)
    {
        if (! isset($addressConfig['entries']['country_id'])) {
            return $form;
        }

        if (! ($gf_field = \GFAPI::get_field($form, $addressConfig['entries']['country_id']))) {
            return $form;
        }

        $choices = [];
        $countries = \Civi\Api4\Country::get(false)
            ->execute();
        foreach ($countries as $country) {
            $choices[] = ['text' => $country['name'], 'value' => $country['id']];
        }
        $gf_field->choices = $choices;
        $gf_field->defaultValue = 1107;

        if (isset($addressConfig['entries']['state_province_id'])) {
            $country_id = rgpost('input_' . str_replace('.', '_', $addressConfig['entries']['country_id']));
            $form = $this->setProvinces($form, $addressConfig['entries']['state_province_id'], empty($country_id) ? $gf_field->defaultValue : $country_id);
        }

        return $form;
    }
    public function setProvinces($form, $field_id, $country_id)
    {
        if (! $form['id']) {
            return $form;
        }

        if (! ($gf_field = \GFAPI::get_field($form, $field_id))) {
            return $form;
        }
        $gf_field->choices = $this->ajaxProvinces($country_id);
        return $form;
    }

    public function countriesInitScript($form, $addressConfig)
    {
        if (! isset($addressConfig['entries']['country_id'])) {
            return $form;
        }

        $countryId = $addressConfig['entries']['country_id'];
        $provinceId = $addressConfig['entries']['state_province_id'];        
        if (! ($gf_field = \GFAPI::get_field($form, $countryId))) {
            return $form;
        }
        $form_id = $form['id'];
        $ajax_url = admin_url('admin-ajax.php');

        ob_start(); ?>
(function() {
  var form = document.getElementById('gform_<?php echo $form_id ?>');
  if (!form) return;

  var countrySelect = form.querySelector('select[name="input_<?php echo $countryId ?>"]');
  var provinceSelect = form.querySelector('[name="input_<?php echo $provinceId ?>"]');

  if (!countrySelect || !provinceSelect) return;

  countrySelect.addEventListener("change", function(event) {
    var country = event.target.value;
    var params = {
      action: 'gfcivicrm_provinces',
      country: country,
    };

    // Svuota select province
    while (provinceSelect.firstChild) {
      provinceSelect.removeChild(provinceSelect.firstChild);
    }

    var request = new XMLHttpRequest();
    request.open('POST', '<?php echo esc_url($ajax_url); ?>', true);
    request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    request.onload = function() {
      if (this.status >= 200 && this.status < 400) {
        try {
          var data = JSON.parse(this.response).data;
          data.forEach(function(entry) {
            provinceSelect.add(new Option(entry.text, entry.value));
          });
        } catch(e) {
          console.error("Errore parsing JSON:", this.response);
        }
      } else {
        console.error("Errore AJAX:", this.response);
      }
    };
    request.onerror = function() {
      console.error("Errore di connessione AJAX");
    };
    request.send(new URLSearchParams(params).toString());
  });
})();
<?php
        $script = ob_get_clean();

        \GFFormDisplay::add_init_script($form['id'], 'countries_script', \GFFormDisplay::ON_PAGE_RENDER, $script);

        return $form;
    }

    public function ajaxProvinces($country_id)
    {
        $choices = [];
        $stateProvinces = \Civi\Api4\StateProvince::get(false)
            ->addWhere('country_id', '=', $country_id)
            ->execute();
        foreach ($stateProvinces as $province) {
            $choices[] = ['text' => $province['name'], 'value' => $province['id']];
        }
        return $choices;
    }
}
