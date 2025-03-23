<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log file for debugging
$logFile = '/tmp/db_connection.log';
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

logMessage("Starting database connection process");

// Define the secret name
$secretName = "ecommerce/db-credential";
$region = "us-east-1"; // Update this to your AWS region

// Function to get secret using AWS CLI
function getSecretCLI($secretName, $region) {
    global $logFile;
    $command = "aws secretsmanager get-secret-value --secret-id " . escapeshellarg($secretName) . " --region " . escapeshellarg($region) . " --query SecretString --output text";
    
    logMessage("Executing AWS CLI command to retrieve secret");
    
    // Execute command and capture both stdout and stderr
    $descriptorspec = array(
        0 => array("pipe", "r"),  // stdin
        1 => array("pipe", "w"),  // stdout
        2 => array("pipe", "w")   // stderr
    );
    
    $process = proc_open($command, $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $return_value = proc_close($process);
        
        if ($return_value !== 0) {
            logMessage("AWS CLI error (code $return_value): $error");
            return false;
        }
        
        if (empty($output)) {
            logMessage("AWS CLI returned empty output");
            return false;
        }
        
        logMessage("Secret retrieved successfully");
        $decoded = json_decode($output, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("JSON decode error: " . json_last_error_msg());
            logMessage("Raw output (first 100 chars): " . substr($output, 0, 100));
            return false;
        }
        
        return $decoded;
    }
    
    logMessage("Failed to execute AWS CLI command");
    return false;
}

// Try to get the secret
logMessage("Attempting to retrieve secret from AWS Secrets Manager");
$secret = getSecretCLI($secretName, $region);

// If secret retrieval failed, fall back to .env.php
if (!$secret) {
    logMessage("Secret retrieval failed, falling back to .env.php");
    
    // Check if .env.php exists
    if (!file_exists(__DIR__ . '/.env.php')) {
        logMessage("ERROR: .env.php file not found");
        die("Configuration error: Database credentials not available. Check server logs for details.");
    }
    
    require_once __DIR__ . '/.env.php';
    
    // Check if constants are defined
    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
        logMessage("ERROR: Required database constants not defined in .env.php");
        die("Configuration error: Database credentials not properly defined. Check server logs for details.");
    }
    
    $dbHost = DB_HOST;
    $dbUser = DB_USER;
    $dbPass = DB_PASS;
    $dbName = DB_NAME;
    $dbSsl = defined('DB_SSL') ? DB_SSL : false;
    
    logMessage("Using credentials from .env.php: Host=$dbHost, User=$dbUser, DB=$dbName, SSL=" . ($dbSsl ? 'true' : 'false'));
} else {
    // Extract values from the secret
    $dbHost = $secret['host'] ?? '';
    $dbUser = $secret['username'] ?? '';
    $dbPass = $secret['password'] ?? '';
    $dbName = $secret['dbname'] ?? '';
    $dbSsl = $secret['ssl'] ?? false;
    
    // Check if we have all required values
    if (empty($dbHost) || empty($dbUser) || empty($dbPass) || empty($dbName)) {
        logMessage("ERROR: Missing required database credentials in secret");
        logMessage("Secret keys available: " . implode(", ", array_keys($secret)));
        die("Configuration error: Incomplete database credentials in secret. Check server logs for details.");
    }
    
    logMessage("Using credentials from Secrets Manager: Host=$dbHost, User=$dbUser, DB=$dbName, SSL=" . ($dbSsl ? 'true' : 'false'));
}

// Attempt initial connection without SSL
logMessage("Attempting initial database connection to $dbHost");
$con = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

// Check connection
if ($con->connect_error) {
    logMessage("Initial connection failed: " . $con->connect_error);
    
    // Try connecting without database name (to check if database exists)
    logMessage("Attempting connection without database name");
    $testCon = new mysqli($dbHost, $dbUser, $dbPass);
    
    if ($testCon->connect_error) {
        logMessage("Connection without database failed: " . $testCon->connect_error);
        die("Database connection failed: " . $con->connect_error . ". Check server logs for details.");
    } else {
        // Check if database exists
        $result = $testCon->query("SHOW DATABASES LIKE '$dbName'");
        if ($result && $result->num_rows === 0) {
            logMessage("Database '$dbName' does not exist");
            die("Database '$dbName' does not exist. Check server logs for details.");
        }
        $testCon->close();
        die("Database connection failed: " . $con->connect_error . ". Check server logs for details.");
    }
}

logMessage("Initial connection successful");

// Enable SSL if configured
if ($dbSsl) {
    logMessage("SSL is enabled, reconfiguring connection with SSL");
    
    // Close the initial connection
    $con->close();
    
    // Get the CA certificate path - match the path used in the EC2 user data script
    $caCertPath = '/etc/ssl/certs/rds-ca-cert.pem';
    logMessage("Looking for CA cert at: $caCertPath");
    
    // If the RDS CA cert doesn't exist, try to create it from the secret or download it
    if (!file_exists($caCertPath) || !is_readable($caCertPath)) {
        logMessage("CA cert not found at $caCertPath, attempting to create it");
        
        // First try to get it from the secret
        if (isset($secret['ca_cert']) && !empty($secret['ca_cert'])) {
            logMessage("Creating CA cert from secret data");
            $certData = base64_decode($secret['ca_cert']);
            
            // Try to write to the standard location with proper permissions
            $writeResult = @file_put_contents($caCertPath, $certData);
            if ($writeResult === false) {
                logMessage("Failed to write to $caCertPath, using temp file instead");
                // Create a temporary file for the CA certificate
                $tempCaCertFile = tempnam(sys_get_temp_dir(), 'ca_cert_');
                file_put_contents($tempCaCertFile, $certData);
                $caCertPath = $tempCaCertFile;
                logMessage("Created temp CA cert file: $tempCaCertFile");
            } else {
                logMessage("Successfully wrote CA cert to: $caCertPath");
            }
        } else {
            // If not in secret, try to download the RDS CA bundle for US East 1
            logMessage("CA cert not in secret, attempting to download from AWS");
            $awsRdsCert = @file_get_contents('https://truststore.pki.rds.amazonaws.com/us-east-1/us-east-1-bundle.pem');
            
            if ($awsRdsCert !== false) {
                // Try to write to the standard location
                $writeResult = @file_put_contents($caCertPath, $awsRdsCert);
                if ($writeResult === false) {
                    logMessage("Failed to write downloaded cert to $caCertPath, using temp file");
                    // Create a temporary file
                    $tempCaCertFile = tempnam(sys_get_temp_dir(), 'ca_cert_');
                    file_put_contents($tempCaCertFile, $awsRdsCert);
                    $caCertPath = $tempCaCertFile;
                    logMessage("Created temp CA cert file: $tempCaCertFile");
                } else {
                    logMessage("Successfully wrote downloaded CA cert to: $caCertPath");
                }
            } else {
                logMessage("Failed to download CA cert, falling back to system CA bundle");
                // Fall back to system CA bundle
                $caCertPath = '/etc/ssl/certs/ca-certificates.crt';
                if (!file_exists($caCertPath)) {
                    // Try alternative locations for system CA bundle
                    $altPaths = [
                        '/etc/pki/tls/certs/ca-bundle.crt',  // RHEL/CentOS/Fedora
                        '/etc/ssl/cert.pem',                 // Alpine
                        '/etc/ssl/certs/ca-certificates.crt' // Debian/Ubuntu
                    ];
                    
                    foreach ($altPaths as $path) {
                        if (file_exists($path)) {
                            $caCertPath = $path;
                            logMessage("Using system CA bundle at: $caCertPath");
                            break;
                        }
                    }
                }
            }
        }
    } else {
        logMessage("Found existing CA cert at: $caCertPath");
    }
    
    // Verify the CA cert file exists and is readable
    if (!file_exists($caCertPath) || !is_readable($caCertPath)) {
        logMessage("WARNING: CA cert file does not exist or is not readable: $caCertPath");
    } else {
        logMessage("CA cert file exists and is readable");
    }
    
    // Create a new connection with SSL enabled
    $con = new mysqli();
    logMessage("Setting up SSL connection");
    $con->ssl_set(NULL, NULL, $caCertPath, NULL, NULL);
    
    // Connect with SSL
    logMessage("Attempting to connect with SSL");
    $con->real_connect($dbHost, $dbUser, $dbPass, $dbName, 3306, MYSQLI_CLIENT_SSL);
    
    // Check connection again
    if ($con->connect_error) {
        logMessage("SSL Connection failed: " . $con->connect_error);
        
        // Try one more time without SSL as a fallback
        logMessage("Trying one more time without SSL as fallback");
        $con = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        
        if ($con->connect_error) {
            logMessage("Fallback connection also failed: " . $con->connect_error);
            die("SSL Database connection failed: " . $con->connect_error . ". Check server logs for details.");
        } else {
            logMessage("Fallback connection without SSL succeeded");
        }
    } else {
        logMessage("SSL Connection successful");
    }
    
    // Clean up temporary file if created
    if (isset($tempCaCertFile) && file_exists($tempCaCertFile)) {
        register_shutdown_function(function() use ($tempCaCertFile) {
            @unlink($tempCaCertFile);
            logMessage("Temporary CA cert file removed: $tempCaCertFile");
        });
    }
}

logMessage("Database connection established successfully");
?>
