<?php 
// Create an array with SSL options
$ssl_options = array(
    "ssl" => array(
        "verify_peer" => true,
        "verify_peer_name" => false,
        "cafile" => __DIR__ . "/../ca.pem"
    )
);

// Create connection with SSL
$con = mysqli_init();
mysqli_options($con, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true);
mysqli_ssl_set($con, NULL, NULL, __DIR__ . "/../ca.pem", NULL, NULL);
mysqli_real_connect($con, 'localhost', 'root', '', 'ecommerce_1', 3306, NULL, MYSQLI_CLIENT_SSL);

// Check connection
if (mysqli_connect_errno()) {
    die("Failed to connect to MySQL: " . mysqli_connect_error());
}
?>
