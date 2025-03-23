<?php
// Database connection parameters
// These values will be populated by the EC2 user data script
// from AWS Secrets Manager

define('DB_HOST', '');     // Will be populated with RDS endpoint
define('DB_USER', '');     // Will be populated with database username
define('DB_PASS', '');     // Will be populated with database password
define('DB_NAME', '');     // Will be populated with database name
define('DB_SSL', true);    // Enable SSL for secure connection
?>
