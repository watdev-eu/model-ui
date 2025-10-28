<?php
// Simple PHP test page

// Display PHP configuration info if ?info=1 is in the URL
if (isset($_GET['info'])) {
    phpinfo();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP Docker Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f6fa;
            color: #2f3640;
            margin: 2em;
        }
        h1 { color: #273c75; }
        .info {
            background: #dcdde1;
            padding: 1em;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <h1>🚀 PHP Docker Test Page</h1>
    <p>If you see this page, your PHP Docker container is working!</p>

    <div class="info">
        <strong>PHP Version:</strong> <?= phpversion(); ?><br>
        <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
        <strong>Document Root:</strong> <?= $_SERVER['DOCUMENT_ROOT']; ?><br>
        <strong>Date/Time:</strong> <?= date('Y-m-d H:i:s'); ?>
    </div>

    <p><a href="?info=1">View phpinfo()</a></p>
</body>
</html>
