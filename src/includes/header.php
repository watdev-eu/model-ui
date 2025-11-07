<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?? 'Pagina' ?> - MX5-Winkel</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables Bootstrap 5 integration -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

    <!-- Custom styles -->
    <link href="/assets/css/sidebar.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tables.css">
    <link rel="stylesheet" href="/assets/css/modal.css">

    <!-- Icons -->
    <script src="https://kit.fontawesome.com/a2e0c139e7.js" crossorigin="anonymous"></script>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <nav id="sidebar" class="bg-light border-end">
        <div class="p-3 d-flex flex-column h-100">
            <h4 class="mb-3">MX5</h4>
            <ul class="nav nav-pills flex-column mb-auto">
                <li><a href="/index.php" class="nav-link text-dark"><i class="fas fa-home me-2"></i> Home</a></li>
                <li><a href="/dashboard.php" class="nav-link text-dark"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                <li><a href="/orders.php" class="nav-link text-dark"><i class="fas fa-table me-2"></i> Bestellingen</a></li>
                <li><a href="/products.php" class="nav-link text-dark"><i class="fas fa-th me-2"></i> Producten</a></li>
                <li><a href="/customers.php" class="nav-link text-dark"><i class="fas fa-user me-2"></i> Klanten</a></li>
            </ul>
            <div class="mt-auto pt-3 border-top">
                <a href="/logout.php" class="d-flex align-items-center text-decoration-none">
                    <img src="https://via.placeholder.com/32" alt="pf" class="rounded-circle me-2">
                    <strong><?= $_SESSION['role'] ?? 'Gebruiker' ?></strong>
                </a>
            </div>
        </div>
    </nav>
    <!-- Page Content -->
    <div class="flex-grow-1 p-4" id="main-content">