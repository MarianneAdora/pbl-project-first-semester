<?php
session_start();

// Protection: Ensure only HR users can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    header('Location: ../index.php');
    exit();
}

// Sample Dynamic Data (Replace with MySQL DB queries)
$stats = [
    'total'       => 35,
    'pending'     => 13,
    'in_progress' => 7,
    'completed'   => 15,
    'passed'      => 22,
    'failed'      => 13
];

$recent_applicants = [
    ['id' => 101, 'name' => 'Juan Dela Cruz', 'date' => 'Sep 14, 2026', 'status' => 'Pending',     'badge' => 'status-pending'],
    ['id' => 102, 'name' => 'Bruce Wayne',    'date' => 'Sep 13, 2026', 'status' => 'In Progress', 'badge' => 'status-progress'],
    ['id' => 103, 'name' => 'Clark Kent',     'date' => 'Sep 12, 2026', 'status' => 'Completed',   'badge' => 'status-completed']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - Expertise Portal</title>
    
    <!-- Google Fonts & Chart.js -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Dashboard HR Stylesheet -->
    <link rel="stylesheet" href="../assets/css/dashboardhr.css">
</head>
<body>

    <!-- Continuous 8-Stripe Horizontal Sweep Overlay -->
    <div class="page-overlay" id="pageOverlay">
        <div class="overlay-stripe stripe-1"></div>
        <div class="overlay-stripe stripe-2"></div>
        <div class="overlay-stripe stripe-3"></div>
        <div class="overlay-stripe stripe-4"></div>
        <div class="overlay-stripe stripe-5"></div>
        <div class="overlay-stripe stripe-6"></div>
        <div class="overlay-stripe stripe-7"></div>
        <div class="overlay-stripe stripe-8"></div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal-backdrop" id="logoutModal">
        <div class="modal-card">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out of the HR portal?</p>
            <div class="modal-actions">
                <button class="btn-cancel" id="cancelLogout">Cancel</button>
                <button class="btn-confirm" id="confirmLogout">Log Out</button>
            </div>
        </div>
    </div>

    <!-- Sidebar Component with Enhanced 3D Shadows -->
    <aside class="sidebar" id="sidebar">
        <!-- Top Header & Bigger Aligned Brand Logo -->
        <div class="sidebar-header">
            <div class="brand-container">
                <img src="../pic/expertise2.png" alt="Expertise Logo" class="brand-logo">
            </div>
            <button class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar">
                <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="sidebar-nav">
            <a href="dashboardhr.php" class="nav-item active">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- Applicants Dropdown Item -->
            <div class="nav-group" id="applicantsGroup">
                <div class="nav-dropdown-wrapper">
                    <!-- Main Applicants Button -->
                    <a href="screeninglist.php" class="nav-item nav-main-link" id="applicantsMainLink">
                        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        <span class="nav-text">Applicants</span>
                    </a>
                    <!-- Pure White Arrow Toggle Button -->
                    <button class="dropdown-arrow-btn" id="arrowToggleBtn" title="Toggle Menu">
                        <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                    </button>
                </div>
                <div class="sub-menu" id="applicantsSubMenu">
                    <a href="screeninglist.php" class="sub-item"><span class="sub-text">Screening List</span></a>
                    <a href="addapplicants.php" class="sub-item"><span class="sub-text">Add Applicant</span></a>
                </div>
            </div>
        </nav>

        <!-- Sidebar Footer Options -->
        <div class="sidebar-footer">
            <a href="settinghr.php" class="nav-item">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6-3.6z"/></svg>
                <span class="nav-text">Settings</span>
            </a>
            <button class="nav-item btn-logout-trigger" id="logoutBtn">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-text">Log out</span>
            </button>
        </div>
    </aside>

    <!-- Main Content Panel Area -->
    <main class="main-content">
        <!-- Top Greeting Header -->
        <header class="content-header">
            <h1>Hello, HR!</h1>
            <p>Overview of application activity.</p>
        </header>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            
            <!-- Summary Stat Cards -->
            <div class="metrics-container">
                <div class="stat-card" data-url="screeninglist.php?status=all">
                    <div class="card-title">Total Applicants</div>
                    <div class="card-value"><?php echo $stats['total']; ?></div>
                </div>

                <div class="stat-card" data-url="screeninglist.php?status=Pending">
                    <div class="card-header-icon">
                        <svg class="icon yellow" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                        <span class="card-title">Pending</span>
                    </div>
                    <div class="card-value"><?php echo $stats['pending']; ?></div>
                </div>

                <div class="stat-card" data-url="screeninglist.php?status=In Progress">
                    <div class="card-header-icon">
                        <svg class="icon blue" viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                        <span class="card-title">In Progress</span>
                    </div>
                    <div class="card-value"><?php echo $stats['in_progress']; ?></div>
                </div>

                <div class="stat-card" data-url="screeninglist.php?status=Completed">
                    <div class="card-header-icon">
                        <svg class="icon green" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        <span class="card-title">Completed</span>
                    </div>
                    <div class="card-value"><?php echo $stats['completed']; ?></div>
                </div>
            </div>

            <!-- Pie Chart Component -->
            <div class="chart-card">
                <h3>Passed/Rejected</h3>
                <div class="chart-wrapper">
                    <canvas id="passedRejectedChart"></canvas>
                </div>
                <div class="chart-legend">
                    <span class="legend-item" id="legendPassed"><span class="legend-dot green"></span> Passed</span>
                    <span class="legend-item" id="legendFailed"><span class="legend-dot red"></span> Failed</span>
                </div>
            </div>

            <!-- Recent Applicants Table -->
            <div class="recent-card">
                <div class="card-top">
                    <h3>Recent Applicants</h3>
                    <a href="screeninglist.php" class="view-all">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="applicants-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Applied Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_applicants as $applicant): ?>
                            <tr class="clickable-row" data-url="details.php?id=<?php echo $applicant['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($applicant['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($applicant['date']); ?></td>
                                <td>
                                    <span class="status-pill <?php echo $applicant['badge']; ?>">
                                        <?php echo htmlspecialchars($applicant['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Action Box -->
            <div class="quick-action-card">
                <h3>Quick Action</h3>
                <div class="avatar-circle">
                    <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                </div>
                <p class="quick-action-text">Add Shortlisted Applicants</p>
                <a href="addapplicants.php" class="btn-add-new">ADD NEW</a>
            </div>

        </div>
    </main>

    <!-- Pass PHP Variables to JavaScript -->
    <script>
        const chartData = {
            passed: <?php echo $stats['passed']; ?>,
            failed: <?php echo $stats['failed']; ?>
        };
    </script>
    <script src="../assets/js/dashboardhr.js"></script>
</body>
</html>