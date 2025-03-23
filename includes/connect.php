<?php
// Define the secret name
$secretName = "ecommerce/db-credentials";
$region = "us-east-1"; // Update this to your AWS region

// Function to get secret using AWS CLI
function getSecretCLI($secretName, $region) {
    $command = "aws secretsmanager get-secret-value --secret-id " . escapeshellarg($secretName) . " --region " . escapeshellarg($region) . " --query SecretString --output text";
    $output = shell_exec($command);
    
    if (!$output) {
        error_log("Error retrieving secret from AWS Secrets Manager");
        return false;
    }
    
    return json_decode($output, true);
}

// Get the secret
$secret = getSecretCLI($secretName, $region);

// If secret retrieval failed, fall back to .env.php
if (!$secret) {
    error_log("Falling back to .env.php for database credentials");
    require_once __DIR__ . '/.env.php';
    
    $dbHost = defined('DB_HOST') ? DB_HOST : '';
    $dbUser = defined('DB_USER') ? DB_USER : '';
    $dbPass = defined('DB_PASS') ? DB_PASS : '';
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $dbSsl = defined('DB_SSL') ? DB_SSL : false;
} else {
    // Extract values from the secret
    $dbHost = $secret['host'] ?? '';
    $dbUser = $secret['username'] ?? '';
    $dbPass = $secret['password'] ?? '';
    $dbName = $secret['dbname'] ?? '';
    $dbSsl = $secret['ssl'] ?? false;
}

// Create initial connection
$con = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

// Check connection
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}

// Enable SSL if configured
if ($dbSsl) {
    // Close the initial connection
    $con->close();
    
    // Get the CA certificate path
    $caCertPath = '/etc/ssl/certs/ca-certificates.crt';
    
    // Check if we have a custom RDS CA certificate file
    if (file_exists('/etc/ssl/certs/rds-ca-cert.pem')) {
        $caCertPath = '/etc/ssl/certs/rds-ca-cert.pem';
    }
    // If not, check if we have a CA certificate in the secret
    else if (isset($secret['ca_cert']) && !empty($secret['ca_cert'])) {
        // Create a temporary file for the CA certificate
        $tempCaCertFile = tempnam(sys_get_temp_dir(), 'ca_cert_');
        file_put_contents($tempCaCertFile, base64_decode($secret['ca_cert']));
        $caCertPath = $tempCaCertFile;
    }
    
    // Create a new connection with SSL enabled
    $con = new mysqli();
    $con->ssl_set(NULL, NULL, NULL, $caCertPath, NULL);
    
    // Connect with SSL
    $con->real_connect($dbHost, $dbUser, $dbPass, $dbName, 3306, MYSQLI_CLIENT_SSL);
    
    // Check connection again
    if ($con->connect_error) {
        die("SSL Connection failed: " . $con->connect_error);
    }
    
    // Clean up temporary file if created
    if (isset($tempCaCertFile) && file_exists($tempCaCertFile)) {
        register_shutdown_function(function() use ($tempCaCertFile) {
            @unlink($tempCaCertFile);
        });
    }
}
?>
