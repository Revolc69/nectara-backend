<?php
session_start();

// If already logged in, skip this page and head straight to the weather dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: weather.php");
    exit();
}

$host = "localhost";
$db_name = "nectara_db"; 
$username = "root";      
$password = ""; 

$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $user_pass = trim($_POST['password']);

    if (!empty($email) && !empty($user_pass)) {
        try {
            $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Lookup the user account record
            $query = "SELECT id, password FROM users WHERE email = :email LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify the hashed password setup
                if (password_verify($user_pass, $row['password'])) {
                    // SUCCESS: Assign session variables
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['email'] = $email;

                    // Redirect to the weather dashboard page
                    header("Location: weather.php");
                    exit();
                } else {
                    $error_msg = "Invalid email or password configuration.";
                }
            } else {
                $error_msg = "Invalid email or password configuration.";
            }
        } catch(PDOException $e) {
            $error_msg = "Database error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill out all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nectara - Sign In</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 50px; text-align: center; }
        .login-box { background: white; max-width: 350px; margin: 0 auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        input { width: 90%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; }
        button { width: 96%; padding: 10px; background-color: #208AEF; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #1a73ca; }
        .error { color: red; font-size: 14px; }
    </style>
</head>
<body>

<div class="login-box">
    <h2>Sign In to Nectara</h2>
    <?php if(!empty($error_msg)): ?>
        <p class="error"><?php echo $error_msg; ?></p>
    <?php endif; ?>
    
    <form method="POST" action="signin.php">
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Log In</button>
    </form>
</div>

</body>
</html>