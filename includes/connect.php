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
$secretName = 'MyRDSSSecret'; // Replace with your secret name
$secret = getSecret($secretName);

// Retrieve the CA certificate information
$caSecretName = 'MyRDSSCACert'; // Replace with your CA certificate secret name
$caSecret = getSecret($caSecretName);

if ($secret && $caSecret) {
    // Database connection details
    $username = $secret['username'];
    $password = $secret['password'];
    $dbHost = $secret['endpoint'];
    $dbName = "ecommerce_1";

    // Get the CA certificate identifier from the secret
    $caCertIdentifier = $caSecret['CaCertIdentifier'] ?? 'rds-ca-rsa2048-g1';
    
    // For MySQL/MariaDB, we need to specify the CA certificate path
    // AWS RDS CA certificates are typically available at a standard location
    // or can be downloaded from AWS
    $caCertFilePath = "/var/www/html/certs/{$caCertIdentifier}.pem";
    
    // If the certificate doesn't exist at the standard location, you may need to download it
    if (!file_exists($caCertFilePath)) {        
        // If you have the certificate content in the secret, you can write it to a file
        if (isset($caSecret['CertificateContent'])) {
            file_put_contents($caCertFilePath, $caSecret['CertificateContent']);
        }
    }

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
