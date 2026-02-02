<?php
function getConnection() {
    $server = "190.7.11.199,1433";       // IP y puerto de SQL Server
    $database = "donbosco";                // Nombre de la base
    $username = "crosschex_user";          // Usuario creado en SQL Server
    $password = "unaClaveSegura123!";      // Contraseña del usuario

    try {
        $dsn = "sqlsrv:Server=$server;Database=$database;Encrypt=no;TrustServerCertificate=yes";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("❌ Error de conexión SQL Server: " . $e->getMessage());
    }
}
