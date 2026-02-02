<?php
require_once __DIR__ . '/connect.php';

function startAuthSession() {
    // Start the session once for authentication checks.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function getAuthenticatedUser() {
    // Return the current authenticated user data.
    return $_SESSION['auth_user'] ?? null;
}

function isAuthenticated() {
    // Determine whether the user is logged in.
    return !empty($_SESSION['auth_user']);
}

function isAdminUser($user) {
    // Check if the authenticated user has admin role.
    if (!$user || !isset($user['role'])) {
        return false;
    }
    return strtolower($user['role']) === 'admin';
}

function setAuthenticatedUser($user) {
    // Persist authenticated user data in the session.
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => $user['AuthUserId'],
        'username' => $user['Username'],
        'role' => $user['Role']
    ];
}

function clearAuthenticatedUser() {
    // Destroy the current authentication session.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function authenticateUser($username, $password) {
    // Validate the username and password against stored credentials.
    $user = getAuthUserByUsername($username);
    if (!$user) {
        return null;
    }
    if (!password_verify($password, $user['PasswordHash'])) {
        return null;
    }
    return $user;
}

function getAuthUserByUsername($username) {
    // Fetch an auth user record by username.
    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT AuthUserId, Username, PasswordHash, Role, IsActive FROM AuthUsers WHERE Username = :username');
    $stmt->bindValue(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['IsActive']) {
        return null;
    }
    return $user;
}

function createAuthUser($username, $password, $role = 'user') {
    // Create a new auth user record.
    $pdo = getConnection();
    $stmt = $pdo->prepare('SELECT 1 FROM AuthUsers WHERE Username = :username');
    $stmt->bindValue(':username', $username);
    $stmt->execute();
    if ($stmt->fetchColumn()) {
        return false;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO AuthUsers (Username, PasswordHash, Role, IsActive) VALUES (:username, :passwordHash, :role, 1)');
    $insert->bindValue(':username', $username);
    $insert->bindValue(':passwordHash', $hash);
    $insert->bindValue(':role', $role);
    $insert->execute();
    return true;
}
