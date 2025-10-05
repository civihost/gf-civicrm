<?php

namespace GFCiviCRM;

class CiviCRMApiWrapper
{
    protected $is_external = false;

    protected $api_key;
    protected $site_key;
    protected $rest_url;

    public function __construct(array $settings)
    {
        $this->is_external = isset($settings['external']) && isset($settings['external']['enabled']) && $settings['external']['enabled'];
        if ($this->is_external) {
            $this->api_key = $settings['external']['api_key'];
            $this->site_key = $settings['external']['site_key'];
            $this->rest_url = $settings['external']['rest_url'];
        }
        return;
    }

    /**
     * Version 3 wrapper for civicrm_api.
     *
     * @param string $entity Type of entities to deal with.
     * @param string $action Create, get, delete or some special action name.
     * @param array $params Array to be passed to function.
     *
     * @throws CRM_Core_Exception
     *
     * @return array
     */
    public function civicrm_api3(string $entity, string $action, array $params = [])
    {
        if ($this->is_external) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->rest_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_ENCODING, "");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Requested-With: XMLHttpRequest"));
            curl_setopt($ch, CURLOPT_VERBOSE, true);

            $postFields = "entity={$entity}&action={$action}&api_key={$this->api_key}&key={$this->site_key}&json=" . (json_encode($params));

            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            $data = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            } else {
                $result = json_decode($data, true);
            }
            curl_close($ch);
        } else {
            $result = civicrm_api3($entity, $action, $params);
        }
        if ($result['is_error']) {
            throw new \Exception(print_r($result, true));
        }
        return $result;
    }

    /**
     * Version 4 wrapper for civicrm_api.
     *
     * @param string $entity Type of entities to deal with.
     * @param string $action Create, get, delete or some special action name.
     * @param array $params Array to be passed to function.
     *
     * @throws CRM_Core_Exception
     *
     * @return array
     */
    public function civicrm_api4(string $entity, string $action, array $params = [])
    {
        if ($this->is_external) {
            // @todo
            //$url = Config::get('external.api4_rest_url') . $entity . '/' . $action;
            //$request = stream_context_create([
            //    'http' => [
            //        'method' => 'POST',
            //        'header' => [
            //            'Content-Type: application/x-www-form-urlencoded',
            //            'X-Civi-Auth: Bearer ' . Config::get('external.api_key'),
            //        ],
            //        'content' => http_build_query(['params' => json_encode($params)]),
            //    ]
            //]);
            //$result = json_decode(file_get_contents($url, FALSE, $request), TRUE);
            //
            //if (isset($result['values'])) {
            //    return $result['values'];
            //} else {
            //    throw new \Exception(print_r($result, true));
            //}
        } else {
            if (! isset($params['checkPermissions'])) {
                $params['checkPermissions'] = false;
            }
            $result = (array) civicrm_api4($entity, $action, $params);
        }
        if (isset($result['error_message'])) {
            throw new \Exception(print_r($result, true));
        }
        return $result;
    }

    /*
    public function callApi()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->rest_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");

        curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Requested-With: XMLHttpRequest"));

        $contact_id = 0;

        // Verify if contact email exists
        $postFields = $this->postEmailExists();
        if ($postFields) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_VERBOSE, true);

            $data = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            } else {
                $obj = json_decode($data);
                if (!$obj->is_error && $obj->count > 0) {
                    $contacts = array_values((array) $obj->values);
                    $contact_id = $contacts[0]->contact_id;
                }
            }
        }

        $postFields = $this->postFields($contact_id);
        $civi_id = null;
        foreach ($postFields as $pf) {

            if ($civi_id) {
                $pf = str_replace('[ID]', $civi_id, $pf);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $pf);
            curl_setopt($ch, CURLOPT_VERBOSE, true);

            $data = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            } else {
                $obj = json_decode($data);
                parse_str($pf, $a);
                if (!$obj->is_error && isset($a['entity']) && $a['entity'] == 'contact') {
                    $civi_id = $obj->id;
                }
            }
        }
        curl_close($ch);
    }

    protected function postFields($contact_id)
    {
        $res[] = "entity={$entity}&action={$action}&api_key={$this->api_key}&key={$this->site_key}&json=" . (json_encode($params));
        return $res;
    }
    */
}
