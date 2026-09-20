<?php
/**
 * Data loaders for admin PDF exports.
 */

function export_get_reports_data(mysqli $mysqli): array
{
    $stats = [];

    $totalAssessmentsResult = $mysqli->query("SELECT COUNT(*) as count FROM student_assessments WHERE status = 'completed'");
    $totalAssessments = (int)($totalAssessmentsResult->fetch_assoc()['count'] ?? 0);

    $avgScoreResult = $mysqli->query("SELECT AVG(total_score) as avg_score FROM student_assessments WHERE status = 'completed'");
    $avgScore = round((float)($avgScoreResult->fetch_assoc()['avg_score'] ?? 0), 1);

    $mostChosenClusterResult = $mysqli->query("
        SELECT cl.name, COUNT(*) as count
        FROM recommendations r
        LEFT JOIN courses c ON r.course_id = c.id
        LEFT JOIN clusters cl ON c.cluster_id = cl.id
        WHERE cl.name IS NOT NULL
        GROUP BY cl.id, cl.name
        ORDER BY count DESC
        LIMIT 1
    ");
    $mostChosenCluster = $mostChosenClusterResult->fetch_assoc()['name'] ?? 'N/A';

    $mostRecommendedCourseResult = $mysqli->query("
        SELECT c.course_name, COUNT(*) as count
        FROM recommendations r
        LEFT JOIN courses c ON r.course_id = c.id
        WHERE c.course_name IS NOT NULL
        GROUP BY c.id, c.course_name
        ORDER BY count DESC
        LIMIT 1
    ");
    $mostRecommendedCourse = $mostRecommendedCourseResult->fetch_assoc()['course_name'] ?? 'N/A';

    $stats = [
        'most_chosen_cluster' => $mostChosenCluster,
        'avg_score' => $avgScore . '%',
        'total_assessments' => $totalAssessments,
        'most_recommended_course' => $mostRecommendedCourse,
    ];

    $clusterDistribution = [];
    $clusterResult = $mysqli->query("
        SELECT s.name as strand_name, s.code, COUNT(DISTINCT sa.student_id) as count
        FROM student_assessments sa
        LEFT JOIN students st ON sa.student_id = st.id
        LEFT JOIN strands s ON st.strand_id = s.id
        WHERE sa.status = 'completed' AND s.id IS NOT NULL
        GROUP BY s.id, s.name, s.code
        ORDER BY count DESC
    ");

    $totalStudents = (int)($mysqli->query("
        SELECT COUNT(DISTINCT sa.student_id) as count
        FROM student_assessments sa
        LEFT JOIN students st ON sa.student_id = st.id
        LEFT JOIN strands s ON st.strand_id = s.id
        WHERE sa.status = 'completed' AND s.id IS NOT NULL
    ")->fetch_assoc()['count'] ?? 0);

    while ($row = $clusterResult->fetch_assoc()) {
        $percentage = $totalStudents > 0 ? round(($row['count'] / $totalStudents) * 100, 1) : 0;
        $clusterDistribution[] = [
            'name' => $row['strand_name'],
            'code' => $row['code'],
            'count' => (int)$row['count'],
            'percentage' => $percentage,
        ];
    }

    return [
        'stats' => $stats,
        'cluster_distribution' => $clusterDistribution,
        'total_students' => $totalStudents,
    ];
}

function export_get_assessment_results_data(mysqli $mysqli, string $search = '', int $yearId = 0, int $strandId = 0): array
{
    $search = trim($search);
    $assessmentResults = [];

    $whereParts = ["sa.status = 'completed'"];
    $params     = [];
    $types      = '';

    if ($search !== '') {
        $whereParts[] = "(CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.student_id LIKE ? OR s.email LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like]);
        $types .= 'sss';
    }

    if ($yearId > 0) {
        $whereParts[] = "(sa.school_year_id = ? OR s.school_year_id = ?)";
        $params = array_merge($params, [$yearId, $yearId]);
        $types .= 'ii';
    }

    if ($strandId > 0) {
        $whereParts[] = "s.strand_id = ?";
        $params[] = $strandId;
        $types .= 'i';
    }

    $whereSql = implode(' AND ', $whereParts);

    $sql = "
        SELECT sa.id, sa.student_id, sa.completed_at, sa.total_score,
               CONCAT(COALESCE(s.first_name,''), ' ', COALESCE(s.last_name,'')) as student_name,
               s.student_id as lrn,
               st.name as strand_name, st.code as strand_code
        FROM student_assessments sa
        LEFT JOIN students s ON sa.student_id = s.id
        LEFT JOIN strands st ON s.strand_id = st.id
        WHERE $whereSql
        ORDER BY sa.completed_at DESC
        LIMIT 1000
    ";

    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recStmt = $mysqli->prepare("
                SELECT c.course_name, cl.name as cluster_name
                FROM recommendations r
                LEFT JOIN courses c ON r.course_id = c.id
                LEFT JOIN clusters cl ON c.cluster_id = cl.id
                WHERE r.assessment_id = ?
                ORDER BY r.match_percentage DESC, r.rank ASC
                LIMIT 1
            ");
            $recStmt->bind_param('i', $row['id']);
            $recStmt->execute();
            $topRec = $recStmt->get_result()->fetch_assoc();
            $recStmt->close();

            $assessmentResults[] = [
                'student_name' => $row['student_name'] ?? 'N/A',
                'lrn' => $row['lrn'] ?? 'N/A',
                'strand' => $row['strand_name'] ?? 'N/A',
                'strand_code' => $row['strand_code'] ?? '',
                'top_category' => $topRec['cluster_name'] ?? 'N/A',
                'top_course' => $topRec['course_name'] ?? 'N/A',
                'score_percentage' => round((float)($row['total_score'] ?? 0), 1),
                'date_completed' => $row['completed_at'],
            ];
        }
    }

    $strandCounts = [];
    $totalCompleted = count($assessmentResults);
    foreach ($assessmentResults as $result) {
        $strand = $result['strand_code'] ?: 'Other';
        $strandCounts[$strand] = ($strandCounts[$strand] ?? 0) + 1;
    }

    $distribution = [];
    foreach ($strandCounts as $strand => $count) {
        $distribution[] = [
            'name' => $strand,
            'count' => $count,
            'percentage' => $totalCompleted > 0 ? round(($count / $totalCompleted) * 100, 1) : 0,
        ];
    }

    return [
        'results' => $assessmentResults,
        'distribution' => $distribution,
        'total_completed' => $totalCompleted,
        'search' => $search,
    ];
}
