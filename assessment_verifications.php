<?php
// Assessment Verifications List
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'system_config.php';
require_once 'config.php';

// Check if user is logged in (admin or counselor)
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['counselor_id'])) {
    header('Location: login.php');
    exit();
}

// Get counselor/admin name
$userName = isset($_SESSION['counselor_id']) ? $_SESSION['counselor_name'] : $_SESSION['admin_name'] ?? 'Counselor';

$filter = $_GET['filter'] ?? 'pending';
$validFilters = ['pending', 'returned', 'verified'];
if (!in_array($filter, $validFilters)) {
    $filter = 'pending';
}

$query = "
    SELECT sa.id, sa.status, sa.verification_status, sa.recommendation_validation, sa.completed_at, sa.created_at,
           s.first_name, s.last_name, s.grade_level, s.student_id as lrn,
           st.name as strand_name, st.code as strand_code
    FROM student_assessments sa
    LEFT JOIN students s ON sa.student_id = s.id
    LEFT JOIN strands st ON s.strand_id = st.id
    WHERE sa.status = 'completed' AND sa.verification_status = ?
    ORDER BY sa.completed_at ASC
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param('s', $filter);
$stmt->execute();
$result = $stmt->get_result();
$assessments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Verification - <?php echo htmlspecialchars(getSystemConfig('name')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="counselor.css">
    <style>
        .filter-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 0;
        }
        .filter-tab {
            padding: 0.75rem 1.5rem;
            color: #94a3b8;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            color: #e2e8f0;
        }
        .filter-tab.active {
            color: #fbbf24;
            border-bottom-color: #fbbf24;
        }
        .table-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
        }
        .results-table th {
            text-align: left;
            padding: 1rem 1.25rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            background: rgba(15, 23, 42, 0.4);
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }
        .results-table td {
            padding: 1rem 1.25rem;
            font-size: 0.88rem;
            color: #e2e8f0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.06);
            vertical-align: middle;
        }
        .results-table tbody tr:hover {
            background: rgba(251, 191, 36, 0.03);
        }
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-badge.verified { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-badge.returned { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-review {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-review:hover {
            background: rgba(59, 130, 246, 0.25);
            color: #93c5fd;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php require_once __DIR__ . '/includes/staff_sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Assessment Verification</h1>
                </div>
                <div class="top-bar-actions">
                    <div class="user-profile">
                        <div class="user-avatar counselor-avatar">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                        <div class="user-dropdown">
                            <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <div class="filter-tabs">
                    <a href="?filter=pending" class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        Pending Verification
                    </a>
                    <a href="?filter=returned" class="filter-tab <?php echo $filter === 'returned' ? 'active' : ''; ?>">
                        Returned for Revision
                    </a>
                    <a href="?filter=verified" class="filter-tab <?php echo $filter === 'verified' ? 'active' : ''; ?>">
                        Verified
                    </a>
                </div>

                <div class="table-card">
                    <div class="table-responsive">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Grade Level</th>
                                    <th>Strand</th>
                                    <th>Assessment Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($assessments)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 2rem; color: #94a3b8;">
                                        No assessments found for this status.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($assessments as $a): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: #f8fafc;">
                                                <?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: #94a3b8;">
                                                LRN: <?php echo htmlspecialchars($a['lrn']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($a['grade_level'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($a['strand_code'])): ?>
                                                <span style="background: rgba(255,255,255,0.1); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                                    <?php echo htmlspecialchars($a['strand_code']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y h:i A', strtotime($a['completed_at'] ?? $a['created_at'])); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars($a['verification_status']); ?>">
                                                <?php echo ucfirst($a['verification_status']); ?>
                                            </span>
                                            <?php if (!empty($a['recommendation_validation'])): ?>
                                                <div style="margin-top: 4px;">
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; font-weight: 600; <?php echo ($a['recommendation_validation'] === 'appropriate') ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3);'; ?>">
                                                        <i class="fa-solid <?php echo ($a['recommendation_validation'] === 'appropriate') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                                                        <?php echo ($a['recommendation_validation'] === 'appropriate') ? 'Appropriate' : 'Needs Review'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="counselor_review.php?id=<?php echo $a['id']; ?>" class="btn-review">
                                                <i class="fa-solid fa-eye"></i> Review
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php include 'includes/app_footer.php'; ?>
        </main>
    </div>
    <script>
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>
