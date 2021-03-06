<?
include '../scat.php';
include '../lib/person.php';

$person_id= (int)$_REQUEST['person'];

$person= person_load($db, $person_id);

if (!$person)
  die_jsonp('No such person.');

if (!$_REQUEST['subject'])
  die_jsonp("Subject required.");

if (!$_REQUEST['message'])
  die_jsonp("Message required.");

if (!$person['email'])
  die_jsonp("No email address available for this person.");

$httpClient= new \Http\Adapter\Guzzle6\Client(new \GuzzleHttp\Client());
$sparky= new \SparkPost\SparkPost($httpClient,
                                  [ 'key' => SPARKPOST_KEY ]);

$data= [
  'subject' => $_REQUEST['subject'],
  'from' => $_REQUEST['from'],
  'message' => $_REQUEST['message'],
  'dynamic_html' => [
    'message' => nl2br($_REQUEST['message']),
  ]
];

$promise= $sparky->transmissions->post([
  'content' => [
    'template_id' => 'customer-contact',
  ],
  'substitution_data' => $data,
  'recipients' => [
    [
      'address' => [
        'name' => $person['name'],
        'email' => $person['email'],
      ],
    ],
    [
      // BCC ourselves
      'address' => [
        'header_to' => $person['email'],
        'email' => OUTGOING_EMAIL_ADDRESS,
      ],
    ],
  ],
  'options' => [
    'inlineCss' => true,
  ],
]);

try {
  $response= $promise->wait();

  echo jsonp(array("message" => "Email sent."));
} catch (\Exception $e) {
  error_log(sprintf("SparkPost failure: %s (%s)",
                    $e->getMessage(), $e->getCode()));
  die_jsonp(sprintf("SparkPost failure: %s (%s)",
                    $e->getMessage(), $e->getCode()));
}
