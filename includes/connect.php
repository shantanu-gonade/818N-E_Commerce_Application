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
// $con = mysqli_init();

// if ($secret && $caSecret) {
//     // Database connection details
//     $username = $secret['username'];
//     $password = $secret['password'];
//     $dbHost = $secret['endpoint'];
//     $dbName = $secret['dbname'];

//     // Get the CA certificate identifier from the secret
//     $caCertIdentifier = $caSecret['CaCertIdentifier'] ?? 'rds-ca-rsa2048-g1';
    
//     // Set path to the CA certificate
//     $certDir = __DIR__ . '/../certs';
//     $caCertFilePath = "{$certDir}/{$caCertIdentifier}.pem";
    
//     // Configure SSL connection
//     if (!file_exists($caCertFilePath)) {
//         die("Error: SSL CA certificate file not found at {$caCertFilePath}. SSL connection is required.");
//     }
    
//     // Set SSL options with proper certificate verification
//     if (!$con->ssl_set($con, null, $caCertFilePath, null, null)) {
//         die("Error: Failed to set SSL parameters: " . $con->error);
//     }
    
//     // Enable strict SSL certificate verification
//     $con->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
    
//     // Force SSL connection with no fallback to non-SSL
//     if (!$con->real_connect($dbHost, $username, $password, $dbName, 3306, null, MYSQLI_CLIENT_SSL)) {
//         die("Error: Failed to establish secure SSL connection to database: " . $con->error);
//     }
    
//     echo "Connected successfully to the database with SSL.";
// } else {
//     echo "Failed to retrieve database credentials or CA certificate.";
// }

// Initialize connection variable
$pdo = null;

// Check if secrets were retrieved successfully
if ($secret && $caSecret) {
    try {
        // Extract database connection details
        $username = $secret['username'];
        $password = $secret['password'];
        $dbHost = $secret['endpoint'];
        $dbName = $secret['dbname'];

        // Get the CA certificate identifier from the secret
        $caCertIdentifier = isset($caSecret['CaCertIdentifier']) ? $caSecret['CaCertIdentifier'] : 'rds-ca-rsa2048-g1';
        
        // Set path to the CA certificate
        $certDir = __DIR__ . '/../certs';
        $caCertFilePath = $certDir . '/global-bundle.pem';
        
        // Check if CA certificate file exists
        if (!file_exists($caCertFilePath)) {
            throw new Exception("Error: SSL CA certificate file not found at $caCertFilePath. SSL connection is required.");
        }
        
        // Define DSN
        $dsn = "mysql:host=$dbHost;dbname=$dbName";
        
        // Define PDO options
        $options = array(
            PDO::MYSQL_ATTR_SSL_CA => $caCertFilePath,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );
        
        // Create PDO connection
        $pdo = new PDO($dsn, $username, $password, $options);
        
        // Verify SSL connection
        $result = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
        echo "SSL connection: " . ($result['Value'] ? "YES - " . $result['Value'] : "NO") . "\n";
        
    } catch (PDOException $e) {
        echo "Connection failed: " . $e->getMessage();
    } catch (Exception $e) {
        echo $e->getMessage();
    }
} else {
    echo "Failed to retrieve database credentials or CA certificate.";
}

// Return the PDO connection
return $pdo;
?>
