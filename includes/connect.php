<?php 
// Include environment variables
require_once '.env.php';

// Get database connection details from environment variables
$db_host = $_ENV['DB_HOST'] ?: 'localhost';
$db_user = $_ENV['DB_USER'] ?: 'root';
$db_password = $_ENV['DB_PASSWORD'] ?: '';
$db_name = $_ENV['DB_NAME'] ?: 'ecommerce_1';
$db_ssl = $_ENV['DB_SSL'] ?: false;

// Create connection with SSL if enabled
$con = new mysqli($db_host, $db_user, $db_password, $db_name);

// Check connection
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}

// Enable SSL if configured
if ($db_ssl) {
    $con->ssl_set(NULL, NULL, "/var/www/html/backend/ca.pem", NULL, NULL);
    $con->real_connect($db_host, $db_user, $db_password, $db_name, 3306, MYSQLI_CLIENT_SSL);
}
?>