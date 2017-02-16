#!/usr/bin/env php
<?php
// Convert eml file into SparkPost Transmission API JSON object
//Copyright  2016 SparkPost

//Licensed under the Apache License, Version 2.0 (the "License");
//you may not use this file except in compliance with the License.
//You may obtain a copy of the License at
//
//    http://www.apache.org/licenses/LICENSE-2.0
//
//Unless required by applicable law or agreed to in writing, software
//distributed under the License is distributed on an "AS IS" BASIS,
//WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//See the License for the specific language governing permissions and
//limitations under the License.

//
// Author: Steve Tuck, February 2017
//
// Third-party library dependencies:
//  http://php.net/manual/en/ref.mailparse.php
//      on Mac OSX: brew install homebrew/php/php55-mailparse
//      on CentOS: sudo yum install php-pecl-mailparse --skip-broken
//
//  SparkPost PHP library - for more info see https://developers.sparkpost.com
//      installation instructions on https://github.com/SparkPost/php-sparkpost
//
require 'vendor/autoload.php';
use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

// Fetch API key and other params from ini file
$iniFile = 'sparkpost.ini';
if(!file_exists($iniFile)) {
    echo("Error: can't find initialisation file:" . $iniFile);
    exit(1);
}
$paramArray = parse_ini_file($iniFile, true);
if(!array_key_exists('SparkPost', $paramArray) ) {
    echo("Error: missing [SparkPost] section in " . $iniFile . "\n");
    exit(1);
}

// Authorization string - needed for sparkpost.com and Enterprise
if(!array_key_exists('Authorization', $paramArray['SparkPost'])) {
    echo("Error: Can't find valid Authorization line in " . $iniFile . "\n");
    exit(1);
}
$apiKey = $paramArray['SparkPost']['Authorization'];
if(is_null($apiKey)) {
    echo("Error: Can't find valid Authorization line in " . $iniFile . "\n");
    exit(1);
}

// Host  - needed only for Enterprise
if(array_key_exists('Host', $paramArray['SparkPost'])) {
    $host = $paramArray['SparkPost']['Host'];           // For SparkPost Enterprise
} else {
    $host = 'api.sparkpost.com';                        // Default to sparkpost.com if absent
}

// Return Path - needed only for Enterprise
if(array_key_exists('Return-Path', $paramArray['SparkPost'])) {
    $returnPath = $paramArray['SparkPost']['Return-Path'];
} else
{
    $returnPath = '';
}

// Binding - needed only for Enterprise
if(array_key_exists('Binding', $paramArray['SparkPost'])) {
    $binding = $paramArray['SparkPost']['Binding'];
} else {
    $binding = '';
}

// Grab the parameters passed to the program
$progName = $argv[0];
$shortProgName = basename($progName);

$forcedFrom = NULL;
$forcedTo = NULL;
// Check argument count, otherwise accessing beyond array bounds throws an error in PHP 5.5+
if($argc >= 2) {
    $emlFile = $argv[1];

    if($argc >=3 ) {
        $forcedFrom = $argv[2];

        if($argc >= 4) {
            $forcedTo = $argv[3];
        }
    }
}
else {
    echo "\nNAME\n";
    echo "   " . $progName . "\n";
    echo "   Parse and send an RFC822-compliant file (e.g. .eml extension) via SparkPost.\n\n";
    echo "SYNOPSIS\n";
    echo "  ./" . $shortProgName . " filename.eml [forced_from [forced_to] ]\n\n";
    echo "  filename.eml must contain RFC822 formatted content including subject, from, to, and MIME parts.\n";
    echo "  cc and bcc headers are also read and applied.\n\n";
    echo "OPTIONAL PARAMETERS\n";
    echo "    forced_from - such as test@example.com - override the From: address in the file.\n";
    echo "    forced_to - such as sender@example.com - override the To: addresses in the file\n";
    exit(0);
}

if (!file_exists($emlFile) ) {
    echo("Can't open file " . $emlFile . " - stopping.\n");
    exit(0);
}

// use the MailParse library to open the input eml file - for more info see http://php.net/manual/en/book.mailparse.php
$msg = new MimeMessage("file", $emlFile);

// Collect the RFC822-format output headers, and the API-format recipient list (initialised into to/cc/bcc order for nice debug output)
$outputHeaders = "";
$recipsList = [
    "to" => NULL,
    "cc" => NULL,
    "bcc" => NULL
];
//
// Get the headers, and the lists of various types of recipients from the input file, copying across selectively
//
// "email_rfc822" attribute:
//      MUST be present (or SparkPost will give an API error response)
//           From:
//
//      SHOULD be present:
//           Subject:                otherwise you'll have a blank subject-line
//           To:                     aka the "Envelope To" - for nice presentation in mail client
//
//      MAY be present:
//           Content-type:           if omitted, SparkPost will insert a header with text/plain)
//           Date:                   if omitted, SparkPost will insert a header with current date/time
//
//           Cc:                     |If you're using this form of message address
//           Bcc:                    |
//
// "recipients" attribute MUST be present (or SparkPost will give an API error response)
//      SHOULD comprise the collected To, Cc, Bcc recipients
//          See https://support.sparkpost.com/customer/portal/articles/2432290-using-cc-and-bcc-with-the-rest-api
//
foreach($msg->data["headers"] as $hdrName => $hdrValue) {
    switch(ucfirst($hdrName)) {
        // Copy these ones across directly.  Use upper-case leading letter for tidiness
        case "Subject":
        case "Content-type":
        case "Date":
            $outputHeaders .= ucfirst($hdrName) . ': '. $hdrValue."\n";
            break;

        // Handle From: specifically, to allow sending domain to be modified during testing
        case "From":
            if($forcedFrom) {
                $hdrValue = $forcedFrom;            // command-line debug argument
                echo("Forced From:\t" . $hdrValue. "\n");
            }
            $outputHeaders .= ucfirst($hdrName) . ': '. $hdrValue."\n";
            break;

        // Extract To: and use in both header and the envelope (API recipient list)
        case "To":
            $recipsList['to'] = mailparse_rfc822_parse_addresses($hdrValue);
            $outputHeaders .= ucfirst($hdrName) . ': '. $hdrValue."\n";
            break;

        // cc: headers are handled in similar way
        case "Cc":
            $recipsList['cc'] = mailparse_rfc822_parse_addresses($hdrValue);
            $outputHeaders .= ucfirst($hdrName) . ': '. $hdrValue."\n";
            break;

        // bcc: destinations are delivered, but are NOT shown in the headers that can be viewed by recipients
        case "Bcc":
            $recipsList['bcc'] = mailparse_rfc822_parse_addresses($hdrValue);
            break;

        // Just ignore these ones.  If the info is important, we could track it in SparkPost metadata, or insert it as mail headers via the API
        case "Mime-version":
        case "X-mailer":
        default:
            break;
    }
}

if($forcedTo) {
    // command-line debug argument to force delivery to a single test recipient
    $r = mailparse_rfc822_parse_addresses($forcedTo);
    echo("Forced To: \t\"" . $r[0]['display'] . "\" <" . $r[0]['address'] . ">\n");
    $allRecips[] = [
        'address' => [
            'name' => $r[0]['display'],
            'email' => $r[0]['address']
        ]
    ];
}
else {
    // Collect all the recipients together into a list, with display name and email address correctly prepared for API
    foreach ($recipsList as $rlName => $rl) {
        if(!is_null($rl)) {
            foreach ($rl as $r) {
                echo($rlName . "\t\"" . $r['display'] . "\" <" . $r['address'] . ">\n");
                $allRecips[] = [
                    'address' => [
                        'name' => $r['display'],
                        'email' => $r['address']
                    ]
                ];
            }
        }
    }
}

// Transfer across the selected RFC822 header attributes, and the entire email body intact
$body = $msg->extract_body(MAILPARSE_EXTRACT_RETURN);
$rfc822Parts = $outputHeaders . "\n" . $body;

echo "Headers: " . strlen($outputHeaders) . " bytes\n";
echo "Body:    " . strlen($body) . " bytes\n\n";

// Open the SparkPost connection -  now includes host parameter for Enterprise / SparkPost.com compatibility
$httpAdapter = new GuzzleAdapter(new Client());
$sparky = new SparkPost($httpAdapter, ['key'=>$apiKey, 'timeout'=>0, 'host'=>$host]);

// Build the request structure
$jsonReq = [
    'content' =>  ['email_rfc822'     => $rfc822Parts],
    'recipients' => $allRecips,
    'campaign'   => 'some text',
    'metadata'   => [
        'example1' => 'newsletter'
    ],
];

// SparkPost Enterprise additional attributes needed
if(!is_null($returnPath)) {
    $jsonReq['return_path'] = $returnPath;
}

if(!is_null($binding)) {
    $jsonReq['metadata']['binding'] = $binding;
}
$startTime = microtime(true);
$promise = $sparky->transmissions->post($jsonReq);

try {
    $response = $promise->wait();
    $endTime = microtime(true);
    $time = $endTime - $startTime;

    echo "Message accepted by SparkPost : https status code " . $response->getStatusCode() . "\n";
    $results = $response->getBody();
    echo("Total accepted recipients: " . $results['results']['total_accepted_recipients'] . "\n");
    echo("Total rejected recipients: " . $results['results']['total_rejected_recipients'] . "\n");
    echo("Transmission id:           " . $results['results']['id'] . "\n");
    echo("API call duration:         " . round($time,3) . " seconds\n");

} catch (Exception $e) {
    echo("Message rejected by SparkPost\n");
    echo($e->getCode()."\n");
    echo($e->getMessage()."\n");
    exit(1);
}