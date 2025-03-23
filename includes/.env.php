<?php
// Database connection environment variables
// These will be populated by EC2 userdata script
$_ENV['DB_HOST'] = ''; // Will be populated with RDS endpoint
$_ENV['DB_USER'] = ''; // Will be populated with database username
$_ENV['DB_PASSWORD'] = ''; // Will be populated with database password
$_ENV['DB_NAME'] = ''; // Will be populated with database name
$_ENV['DB_SSL'] = ''; // Will be populated with SSL setting (true/false)
?>
