<?php
// Include the AWS SDK for PHP
require __DIR__ . '/../vendor/autoload.php';
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

// Enable error logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log function for debugging
function logError($message) {
    error_log("[RDS Connection] " . $message);
    // Uncomment for debugging
    // echo $message . "<br>";
}

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
        logError("Error retrieving secret: " . $e->getMessage());
        return null;
    }
}

// Initialize connection variable
$pdo = null;

// Retrieve the RDS secret for database credentials
$secretName = 'MyRDSSSecret';
$secret = getSecret($secretName);

// Retrieve the CA certificate information
$caSecretName = 'MyRDSSCACert';
$caSecret = getSecret($caSecretName);

// Check if secrets were retrieved successfully
if ($secret && $caSecret) {
    try {
        // Extract database connection details
        $username = $secret['username'];
        $password = $secret['password'];
        $directEndpoint = $secret['endpoint'];
        $proxyEndpoint = isset($secret['proxy_endpoint']) ? $secret['proxy_endpoint'] : null;
        $dbName = $secret['dbname'];
        
        // Set path to the CA certificate
        $certDir = __DIR__ . '/../certs';
        $caCertFilePath = $certDir . '/global-bundle.pem';
        
        // Check if CA certificate file exists
        if (!file_exists($caCertFilePath)) {
            throw new Exception("SSL CA certificate file not found at $caCertFilePath");
        }
        
        // Try connecting through the proxy first if available
        if ($proxyEndpoint) {
            logError("Attempting to connect via RDS Proxy: $proxyEndpoint");
            
            try {
                // Define DSN for proxy
                $dsn = "mysql:host=$proxyEndpoint;dbname=$dbName";
                
                // Basic PDO options for proxy connection
                $options = array(
                    PDO::MYSQL_ATTR_SSL_CA => $caCertFilePath,
                    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, // Disable hostname verification for wildcard certs
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                );
                
                // Create PDO connection to proxy
                $pdo = new PDO($dsn, $username, $password, $options);
                
                // Test the connection
                $pdo->query("SELECT 1");
                
                // Verify SSL connection
                $result = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
                logError("Proxy connection successful with SSL: " . ($result['Value'] ? "YES - " . $result['Value'] : "NO"));
                
                // If we get here, proxy connection was successful
                return $pdo;
                
            } catch (PDOException $e) {
                // Log the proxy connection error
                logError("Proxy connection failed: " . $e->getMessage());
                
                // Fall back to direct connection
                logError("Falling back to direct RDS connection");
            }
        }
        
        // If proxy connection failed or wasn't available, try direct connection
        logError("Attempting direct RDS connection: $directEndpoint");
        
        // Define DSN for direct connection
        $dsn = "mysql:host=$directEndpoint;dbname=$dbName";
        
        // Basic PDO options for direct connection
        $options = array(
            PDO::MYSQL_ATTR_SSL_CA => $caCertFilePath,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        );
        
        // Create PDO connection directly to RDS
        $pdo = new PDO($dsn, $username, $password, $options);
        
        // Test the connection
        $pdo->query("SELECT 1");
        
        // Verify SSL connection
        $result = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
        logError("Direct connection successful with SSL: " . ($result['Value'] ? "YES - " . $result['Value'] : "NO"));
        
    } catch (PDOException $e) {
        logError("All connection attempts failed: " . $e->getMessage());
        echo "Database connection failed. Please try again later.";
        $pdo = null;
    } catch (Exception $e) {
        logError("Exception: " . $e->getMessage());
        echo "Database connection error: " . $e->getMessage();
        $pdo = null;
    }
} else {
    logError("Failed to retrieve database credentials or CA certificate");
    echo "Failed to retrieve database credentials or CA certificate.";
}

// Return the PDO connection
return $pdo;
?>
