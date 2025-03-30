<?php
// Include the AWS SDK for PHP
require __DIR__ . '/../vendor/autoload.php';
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

// Create connection with SSL options
$con = mysqli_init();

if ($secret && $caSecret) {
    // Database connection details
    $username = $secret['username'];
    $password = $secret['password'];
    $dbHost = $secret['endpoint'];
    $dbName = $secret['dbname'];

    // Get the CA certificate identifier from the secret
    $caCertIdentifier = $caSecret['CaCertIdentifier'] ?? 'rds-ca-rsa2048-g1';
    
    // Create a directory for certificates if it doesn't exist
    $certDir = __DIR__ . '/../certs';
//     if (!is_dir($certDir)) {
//         mkdir($certDir, 0755, true);
//     }
//
//     $caCertFilePath = "{$certDir}/{$caCertIdentifier}.pem";
//
//     // If the certificate doesn't exist, try to get it from the secret
//     if (isset($caSecret['CertificateContent'])) {
//         file_put_contents($caCertFilePath, $caSecret['CertificateContent']);
//     } else {
//         // Download the certificate from AWS if not in the secret
//         // This is a fallback mechanism
//         $awsCertUrl = "https://truststore.pki.rds.amazonaws.com/{$caCertIdentifier}.pem";
//         $certContent = @file_get_contents($awsCertUrl);
//         if ($certContent !== false) {
//             file_put_contents($caCertFilePath, $certContent);
//         } else {
//             $con = new mysqli($dbHost, $username, $password, $dbName);
//         }
//     }

    // Set SSL options
    $caCertFilePath = "{$certDir}/{$caCertIdentifier}.pem";
    if ($con->ssl_set(null, null, $caCertFilePath, null, null)) {
        $con->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
        if (!$con->real_connect($dbHost, $username, $password, $dbName, 3306, null, MYSQLI_CLIENT_SSL)) {
            $con = new mysqli($dbHost, $username, $password, $dbName);
        }
    } else {
        $con = new mysqli($dbHost, $username, $password, $dbName);
    }
    echo "Connected successfully to the database with SSL.";
} else {
    echo "Failed to retrieve database credentials or CA certificate.";
}
?>
