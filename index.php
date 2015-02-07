<?php

require 'vendor/autoload.php';
require 'code/getLoginEmail.php';
require 'code/helper.php';
use Aws\Common\Aws;
use Aws\Ses\SesClient;
use Aws\DynamoDb\DynamoDbClient;

// Set timezone
date_default_timezone_set("UTC"); 

// Prepare Slim PHP app
$app = new \Slim\Slim(array(
    'templates.path' => 'templates'
));

function isValid ($app) {

	// The old token should be sent in request header
	$token = $app->request->headers->get('X-Authorization');
	
	$app->response->headers->set('Content-Type', 'application/json');

	// If the user had a token in their local storage, refresh it and send the new one back.
	if (isset($token)) {

		// Get email, expiration timestamp, and signature from old token
		$oldToken = explode(':', $token);
		$email = $oldToken[0];
		$expirationTimestamp = $oldToken[1];
		$givenSignature = $oldToken[2];

		// Setup dates to check if token is expired
		$currentDate = new DateTime();
        $expirationDate = new DateTime();
		$expirationDate->setTimestamp(intval($expirationTimestamp));

		// Setup expected signature for purposes of comparison.
		$rawToken = $email . ':' . $expirationTimestamp;
		$expectedSignature = hash_hmac('ripemd160', $rawToken, 'secret');

		if ($currentDate >= $expirationDate) {

			// The token is expired
			$app->response->setStatus(403);
        	$app->response->setBody(json_encode(array('message' => 'Forbidden')));

		} else if (md5($givenSignature) === md5($expectedSignature)) {

			// All is well and we can finally refresh the auth token
			$newRawToken = $email . ':' . strtotime('+7 days');
			$newSignature = hash_hmac('ripemd160', $newRawToken, 'secret');
			$newAuthToken = $newRawToken . ':' . $newSignature;
                        
			$app->response->headers->set('X-Authorization', $newAuthToken);
			return true;
		
		} else {

			// The token is invalid and has probably been tampered with
            $app->response->setBody(json_encode(array('token' => 'InvalidToken')));

		}
		
	} else {

		$app->response->setStatus(403);
        $app->response->setBody(json_encode(array('message' => 'Forbidden')));
    }

}

$app->get('/', function () use ($app) {

    // Render index view
    $app->render('index.html');
});

$app->get('/api/usernotes', function () use ($app) {

	if (isValid($app)) {
	
		$token = $app->request->headers->get('X-Authorization');
		$oldToken = explode(':', $token);
		$email = $oldToken[0];

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Query user notes from database
		$result = $dbClient->getItem(array(
		    'ConsistentRead' => true,
		    'TableName' => 'usernotes',
		    'Key'       => array(
		        'email' => array('S' => $email)
		    )
		));

		$userNotes = $result['Item']['usernotes']['S'];

        $app->response->setBody(json_encode(array('userNotes' => $userNotes)));
	}

});

$app->put('/api/usernotes', function () use ($app) {

	if (isValid($app)) {

		$token = $app->request->headers->get('X-Authorization');
		$oldToken = explode(':', $token);
		$email = $oldToken[0];

		$userNotes = $app->request->post('userNotes');

		foreach ($userNotes as $userNoteKey => $userNoteValue) {

			if ($userNoteValue['itemType'] === 'notebook' && !isset($userNoteValue['notebookId'])) {

				$userNotes[$userNoteKey]['notebookId'] = uniqid();
			}

			if ($userNoteValue['itemType'] === 'box' && !isset($userNoteValue['boxId'])) {

				$userNotes[$userNoteKey]['boxId'] = uniqid();
			}

		}
		unset($userNoteValue);

		$userNotesEncoded = json_encode($userNotes);

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Make update or insert to user notes in database
		$dbClient->putItem(array(
		    'TableName' => 'usernotes',
	        'Item' => array(
	        	'email' 	=> array('S' => $email), // Primary Key
	        	'usernotes' => array('S' => $userNotesEncoded)
	        )
		));

        $app->response->setBody(json_encode(array('message' => $userNotes)));
	}

});

$app->get('/api/note/:noteId', function ($noteId) use ($app) {

	if (isValid($app)) {

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Query notes from database
		$result = $dbClient->getItem(array(
		    'ConsistentRead' => true,
		    'TableName' => 'notes',
		    'Key'       => array(
		        'noteId' => array('S' => $noteId)
		    )
		));

		$note = $result['Item']['noteText']['S'];

        $app->response->setBody(json_encode(array('noteText' => $note)));
	}

});

$app->post('/api/note', function () use ($app) {

	if (isValid($app)) {

		$noteTitle = json_encode($app->request->post('noteTitle'));
		$noteText = json_encode($app->request->post('noteText'));
		$noteId = uniqid();

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Make insert into user notes in database
		$dbClient->putItem(array(
		    'TableName' => 'notes',
		    'Item'       => array(
		        'noteId'   => array('S' => $noteId), // Primary Key
		        'noteTitle' => array('S' => $noteTitle),
		        'noteText' => array('S' => $noteText)
		    )
		));

        $app->response->setBody(json_encode(array('noteId' => $noteId)));
	}

});

$app->put('/api/note/:noteId', function ($noteId) use ($app) {

	if (isValid($app)) {

		$noteTitle = json_encode($app->request->post('noteTitle'));
		$noteText = json_encode($app->request->post('noteText'));

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Make insert into user notes in database
		$dbClient->putItem(array(
		    'TableName' => 'notes',
		    'Item'       => array(
		        'noteId'   => array('S' => $noteId), // Primary Key
		        'noteTitle' => array('S' => $noteTitle),
		        'noteText' => array('S' => $noteText)
		    )
		));

        $app->response->setBody(json_encode(array('message' => 'Successful')));
	}

});

$app->delete('/api/note/:noteId', function ($noteId) use ($app) {

	if (isValid($app)) {

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Make insert into user notes in database
		$dbClient->deleteItem(array(
		    'TableName' => 'notes',
		    'Key'       => array(
		        'noteId'   => array('S' => $noteId) // Primary Key
		    )
		));

        $app->response->setBody(json_encode(array('message' => 'Successful')));
		
	}

});

$app->get('/api/token', function () use ($app) {

	// The old token should be sent in request header
	$token = $app->request->headers->get('X-Authorization');

	$app->response->headers->set('Content-Type', 'application/json');

	// If the user had a token in their local storage, refresh it and send the new one back.
	if (isset($token)) {

		// Get email, expiration timestamp, and signature from old token
		$oldToken = explode(':', $token);
		$email = $oldToken[0];
		$expirationTimestamp = $oldToken[1];
		$givenSignature = $oldToken[2];

		// Setup dates to check if token is expired
		$currentDate = new DateTime();
        $expirationDate = new DateTime();
		$expirationDate->setTimestamp(intval($expirationTimestamp));

		// Setup expected signature for purposes of comparison.
		$rawToken = $email . ':' . $expirationTimestamp;
		$expectedSignature = hash_hmac('ripemd160', $rawToken, 'secret');

		if ($currentDate >= $expirationDate) {

			// The token is expired
            $app->response->setBody(json_encode(array('token' => 'InvalidToken')));

		} else if (md5($givenSignature) === md5($expectedSignature)) {

			// All is well and we can finally refresh the auth token
			$newRawToken = $email . ':' . strtotime('+7 days');
			$newSignature = hash_hmac('ripemd160', $newRawToken, 'secret');
			$newAuthToken = $newRawToken . ':' . $newSignature;
                        
            $app->response->setBody(json_encode(array('token' => $newAuthToken)));
		
		} else {

			// The token is invalid and has probably been tampered with
            $app->response->setBody(json_encode(array('token' => 'InvalidToken')));

		}
		
	} else {

		// User didn't supply a token to be refreshed so this is either an invalid request or
		// they just opened the appication.
        $app->response->setBody(json_encode(array('token' => 'InvalidToken')));
    }

});

$app->post('/api/login', function () use ($app) {

    $app->response->headers->set('Content-Type', 'application/json');
	$email = $app->request->post('email');

	// Establish AWS Clients
	$sesClient = SesClient::factory(array(
        'region'  => 'us-west-2'
	));

	$tokenId = Helper::GUID();

	$msg = array();
	$msg['Source'] = "ny2244111@hotmail.com";
	//ToAddresses must be an array
	$msg['Destination']['ToAddresses'][] = $email;

	$msg['Message']['Subject']['Data'] = "Notello login email";
	$msg['Message']['Subject']['Charset'] = "UTF-8";

	$msg['Message']['Body']['Text']['Data'] = getLoginTextEmail($email, $tokenId);
	$msg['Message']['Body']['Text']['Charset'] = "UTF-8";
	$msg['Message']['Body']['Html']['Data'] = getLoginHTMLEmail($email, $tokenId);
	$msg['Message']['Body']['Html']['Charset'] = "UTF-8";

	try {

	    $result = $sesClient->sendEmail($msg);

	    //save the MessageId which can be used to track the request
	    $msg_id = $result->get('MessageId');

	    //view sample output
            echo json_encode(true);

	} catch (Exception $e) {
	    //An error happened and the email did not get sent
	    echo($e->getMessage());
	}
});

$app->get('/authenticate', function () use ($app) {

    // Get tokenId from query string which is most likely given from login email
	$tokenId = $app->request->get('token');

	// Get rid of any left over tempAuthTokens.
	$app->deleteCookie('tempAuthToken');

	if (isset($tokenId)) {

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Query token in Database
		$result = $dbClient->getItem(array(
		    'ConsistentRead' => true,
		    'TableName' => 'tokens',
		    'Key'       => array(
		        'tokenId'   => array('S' => $tokenId)
		    )
		));

		// Get email from query result
		$email = $result['Item']['email']['S'];

		// If the email is not there, the token has been deleted or is just invalid
		if (isset($email)) {

			// Get inserted date from query result for comparison purposes
			$insertedDateTimeStamp = $result['Item']['insertedDate']['N'];
			$insertedDate = DateTime::createFromFormat( 'U', $insertedDateTimeStamp);
			$currentTime = new DateTime();

			// Delete token from database regardless of whether it's expired or not.
			// Query each token for the given email
			$scan = $dbClient->getIterator('Query', array(
					'TableName' => 'tokens',
			    	'IndexName' => 'email-index',
			    	'KeyConditions' => array(
				        'email' => array(
				            'AttributeValueList' => array(
				                array('S' => $email)
				            ),
				            'ComparisonOperator' => 'EQ'
				        )
			    	)
				)
			);

			// Delete each item for the given email
			foreach ($scan as $item) {

				$dbClient->deleteItem(array(
				        'TableName' => 'tokens',
				        'Key' => array(
				            'tokenId' => array('S' => $item['tokenId']['S'])
				     	)
					)
				);
			}

			// If the token is over 1 hour old then it is considered invalid and we don't authenticate the user
			if (date_diff($insertedDate, $currentTime)->h > 1) {

				$app->setCookie('tempAuthToken', 'expired', '5 minutes', '/', null, true);

			} else {

				$rawToken = $email . ':' . strtotime('+7 days');
				$signature = hash_hmac('ripemd160', $rawToken, 'secret');
				$authToken = $rawToken . ':' . $signature;

				$app->setCookie('tempAuthToken', $authToken, '5 minutes', '/', null, true);

			}

		} else {

			// Invalid or deleted token
			$app->setCookie('tempAuthToken', 'invalid', '5 minutes', '/', null, true);
		}

	}

	$app->response->redirect('/', 303);

});

// Run app
$app->run();
