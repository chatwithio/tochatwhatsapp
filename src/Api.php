<?php
/**
 * @author    360dialog – Official WhatsApp Business Solution Provider. <info@360dialog.com>
 * @copyright 2021 360dialog GmbH.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException as HttpConnectException;

class Api
{
    private $apikey;

    private $url;
    
    /**
     * @var array
     */
    private $endpoint = [
        'contact' => [
            'method' => 'POST',
            'url' => 'contacts'
        ],
        'message' => [
            'method' => 'POST',
            'url' => 'messages'
        ]
        ,
        'template' => [
            'method' => 'GET',
            'url' => 'configs/templates'
        ],
    ];

    public function __construct($apikey, $url)
    {
        $this->apikey = $apikey;
        $this->url = $url;
    }

    private function send($endpoint, $data = [])
    {
        try {
            $client = new Client();

            $url = $this->url;

            $request = $client->createRequest(
                $this->endpoint[$endpoint]['method'],
                $url . $this->endpoint[$endpoint]['url'],
                [
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'D360-API-KEY' => $this->apikey
                    ],
                    "json" => $data
                ]
            );

            $response = $client->send($request);

            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                return json_decode($response->getBody()->getContents());
            } else {
                return json_decode($response->getBody()->getContents());
            }
        } catch (HttpConnectException $e) {
            return $e->getMessage();
        } catch (ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents());

            if (isset($response->meta->success) && $response->meta->success == false) {
                return (string)$response->meta->developer_message;
            }
            
            return (string)$e->getMessage();
        }
    }

    private function buildMessage($messageTemplate, $placeholders)
    {
        $counter = 1;
        foreach ($placeholders as $placeholder) {
            $messageTemplate = str_replace("{$counter}", $placeholder, $messageTemplate);
        }
        return $messageTemplate;
    }

    public function getTemplates()
    {
        return $this->send('template');
    }

    public function sendWhatsApp($to, $placeholders, $template, $language, $namespace)
    {
        $payload = [
            "to" => $to,
            "type" => "template",
            "template" => [
                "namespace" => $namespace,
                "language" => [
                    "policy" => "deterministic",
                    "code" => $language
                ],
                "name" => $template,
                "components" => [
                    [
                        "type" => "body",
                        "parameters" => $this->buildParams($placeholders)

                    ]
                ]
            ]
        ];
        return $this->send('message', $payload);
    }

    /**
     * @param $placeholders (an array of text only placeholders)
     * @return array
     */
    private function buildParams($placeholders)
    {
        $arr = [];
        foreach ($placeholders as $placeholder) {
            $arr[] = [
                "type" => "text",
                "text" => $placeholder
            ];
        }
        return $arr;
    }

    public function checkContact($contact)
    {
        try {
            //Since sanbox does not provide contact validation
            $payload = [
                "blocking" => "wait",
                "contacts" => ["+" . $contact],
                "force_check" => true
            ];
            $response = $this->send('contact', $payload);
            if (!empty($response->contacts)) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $exception) {
        }
        return false;
    }
}
