<?php
// Include the AWS SDK for PHP
require _DIR_ . '/../vendor/autoload.php';
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

function getSecret($secretName) {
    $client = new SecretsManagerClient([
        'version' => 'latest',
        'region' => 'us-east-1'
    ]);

    try {
        $result = $client->getSecretValue([
            'SecretId' => $secretName,
        ]);

        if (isset($result['SecretString'])) {
            return json_decode($result['SecretString'], true);
        } else {
            throw new Exception('Secret is not a string');
        }
    } catch (AwsException $e) {
        echo "Error retrieving secret: " . $e->getMessage();
        return null;
    }
}

// Retrieve the RDS secret for database credentials
$secretName = 'MyRDSSecret'; // Replace with your secret name
$secret = getSecret($secretName);

// Retrieve the CA certificate
$caSecretName = 'MyRDSCACert'; // Replace with your CA certificate secret name
$caSecret = getSecret($caSecretName);
$caCertificate = $caSecret['SecretString']; // Assuming the secret is stored as plain string

if ($secret && $caCertificate) {
    // Database connection details
    $username = $secret['username'];
    $password = $secret['password'];
    $dbHost = $secret['endpoint'];
    $dbName = "ecommerce_1";

    // Path to store CA certificate temporarily
    $caCertFilePath = '/tmp/ca-cert.pem';
    file_put_contents($caCertFilePath, $caCertificate);

    // Create connection without SSL
    $con = new mysqli($dbHost, $username, $password, $dbName);

    // Check connection
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }

    // Set SSL options for the connection
    $con->ssl_set(null, null, $caCertFilePath, null, null);

    // Verify server certificate
    $con->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);

    // Reconnect with SSL
    if (!$con->real_connect($dbHost, $username, $password, $dbName, 3306, null, MYSQLI_CLIENT_SSL)) {
        die("SSL Connection failed: " . $con->connect_error);
    }

    echo "Connected successfully to the database with SSL.";
} else {
    echo "Failed to retrieve database credentials or CA certificate.";
}
?>