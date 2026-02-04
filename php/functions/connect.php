<?php
function getConnectionConfig() {
    $server = "190.7.11.199,1433";       // SQL Server host and port.
    $database = "donbosco";               // Primary database name.
    $username = "crosschex_user";         // SQL Server user.
    $password = "unaClaveSegura123!";     // SQL Server password.

    return [
        'server' => $server,
        'database' => $database,
        'username' => $username,
        'password' => $password
    ];
}

function createSqlServerConnection($server, $database, $username, $password) {
    try {
        $dsn = "sqlsrv:Server=$server;Database=$database;Encrypt=no;TrustServerCertificate=yes";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("❌ Error de conexión SQL Server: " . $e->getMessage());
    }
}

function getConnection() {
    $config = getConnectionConfig();
    return createSqlServerConnection(
        $config['server'],
        $config['database'],
        $config['username'],
        $config['password']
    );
}

function getNuporaConnection() {
    $config = getConnectionConfig();
    $nuporaDatabase = "nupora"; // Secondary database name.
    return createSqlServerConnection(
        $config['server'],
        $nuporaDatabase,
        $config['username'],
        $config['password']
    );
}
