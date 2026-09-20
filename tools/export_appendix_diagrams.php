<?php
/**
 * Export Appendix E Mermaid diagrams to PNG via mermaid.ink
 * Usage: php tools/export_appendix_diagrams.php
 */

$projectRoot = dirname(__DIR__);
$outputDir = $projectRoot . '/docs/appendix_e/diagrams';
$markdownFile = $projectRoot . '/APPENDIX_E_SYSTEM_DOCUMENTATION.md';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$diagrams = [
    'figure_e1_use_case' => <<<'MERMAID'
flowchart TB
    subgraph StudentActor["Student"]
        S((Student))
    end

    subgraph StaffActors["Staff Portal - RBAC"]
        C((Counselor))
        A((Admin))
        SA((Super Admin))
    end

    subgraph StudentUC["Student Use Cases"]
        UC1[Register Account]
        UC2[Login Logout]
        UC3[Reset Password]
        UC4[Manage Profile]
        UC5[Take Career Assessment]
        UC6[View Results and Recommendations]
        UC7[Export PDF Report]
    end

    subgraph SharedStaffUC["Shared Staff Use Cases"]
        UC8[Login Logout]
        UC9[View Dashboard]
        UC10[Manage Students]
        UC11[Manage Questions]
        UC12[View Ongoing Assessments]
        UC13[View Assessment History]
        UC14[View Assessment Answers]
        UC15[View and Export Reports]
        UC16[View Own Activity Logs]
    end

    subgraph AdminUC["Admin-Only Use Cases"]
        UC17[Manage Careers Courses Schools Jobs]
        UC18[Manage Counselors]
        UC19[System Settings]
        UC20[View All Activity Logs]
    end

    subgraph SuperAdminUC["Super Admin-Only Use Cases"]
        UC21[Manage Admin Accounts]
    end

    S --> UC1
    S --> UC2
    S --> UC3
    S --> UC4
    S --> UC5
    S --> UC6
    S --> UC7

    C --> UC8
    C --> UC9
    C --> UC10
    C --> UC11
    C --> UC12
    C --> UC13
    C --> UC14
    C --> UC15
    C --> UC16

    A --> UC8
    A --> UC9
    A --> UC10
    A --> UC11
    A --> UC12
    A --> UC13
    A --> UC14
    A --> UC15
    A --> UC16
    A --> UC17
    A --> UC18
    A --> UC19
    A --> UC20

    SA --> UC8
    SA --> UC9
    SA --> UC10
    SA --> UC11
    SA --> UC12
    SA --> UC13
    SA --> UC14
    SA --> UC15
    SA --> UC16
    SA --> UC17
    SA --> UC18
    SA --> UC19
    SA --> UC20
    SA --> UC21
MERMAID,
    'figure_e2_registration_login' => <<<'MERMAID'
flowchart TD
    A([Start]) --> B[Open CareerPath Website]
    B --> C{Has Account?}
    C -->|No| D[Enter School ID and Personal Info]
    D --> E{School ID Valid?}
    E -->|No| F[Show Error]
    F --> D
    E -->|Yes| G[Create Account]
    G --> H[Login]
    C -->|Yes| H
    H --> I{Credentials Correct?}
    I -->|No| J[Show Login Error]
    J --> H
    I -->|Yes| K[Open Student Dashboard]
    K --> L([End])
MERMAID,
    'figure_e3_assessment_flow' => <<<'MERMAID'
flowchart TD
    A([Start Assessment]) --> B[Load 30 Random Questions per Category]
    B --> C[Answer Questions]
    C --> D{All Categories Done?}
    D -->|No| C
    D -->|Yes| E[Save Answers to Database]
    E --> F[Compute Category Scores]
    F --> G[Compute Competency Scores]
    G --> H[Run Recommendation Algorithm]
    H --> I[Save Top Course Recommendations]
    I --> J[Display Results with Match Percent]
    J --> K([End])
MERMAID,
    'figure_e4_recommendation_scoring' => <<<'MERMAID'
flowchart TD
    A[Student Assessment Scores] --> B[For Each Course in Database]
    B --> C[Skills Match x 40 percent]
    B --> D[Career Interest Match x 30 percent]
    B --> E[Personality Match x 20 percent]
    B --> F[Strand Alignment x 10 percent]
    C --> G[Final Course Score]
    D --> G
    E --> G
    F --> G
    G --> H[Rank All Courses by Score]
    H --> I[Show Top 5 Recommendations]
    I --> J[Show Related Schools and Careers]
MERMAID,
    'figure_e5_admin_management' => <<<'MERMAID'
flowchart TD
    A([Admin Login]) --> B[Admin Dashboard]
    B --> C{Select Module}
    C -->|Questions| D[Add Edit Delete Assessment Questions]
    C -->|Courses| E[Manage Courses Jobs Competencies]
    C -->|Schools| F[Manage Schools and Course Links]
    C -->|Students| G[Add Edit View Student Records]
    C -->|Results| H[View and Export Assessment Reports]
    D --> I[Save to Database]
    E --> I
    F --> I
    G --> I
    H --> I
    I --> J[Log Action in Audit Trail]
    J --> K([End])
MERMAID,
    'figure_e6_architecture' => <<<'MERMAID'
flowchart TB
    subgraph Presentation["Presentation Layer - Client Browser"]
        UI[HTML CSS JavaScript]
        P1[Student Pages]
        P2[Admin and Counselor Pages]
    end

    subgraph Application["Application Layer - XAMPP Apache PHP"]
        PHP[PHP Pages and Scripts]
        API[API Endpoints]
        AUTH[Authentication and RBAC]
        SCORE[Scoring and Recommendation Engine]
        PDF[PDF Export DomPDF]
        MAIL[Email and Notifications]
    end

    subgraph Data["Data Layer"]
        DB[(MariaDB Database riverview_data)]
        FILES[File Storage uploads]
    end

    UI --> PHP
    P1 --> PHP
    P2 --> PHP
    PHP --> AUTH
    PHP --> API
    API --> SCORE
    API --> PDF
    PHP --> MAIL
    AUTH --> DB
    SCORE --> DB
    PDF --> DB
    PHP --> DB
    PHP --> FILES
MERMAID,
    'figure_e7_hardware' => <<<'MERMAID'
flowchart TB
    subgraph Clients["Client Devices - Local Network"]
        ST[Student PC Laptop or Mobile]
        CO[Counselor PC Laptop]
        AD[Admin PC Laptop]
    end

    BROWSER[Web Browser HTTP]

    subgraph Server["XAMPP Server - Host Computer"]
        AP[Apache Web Server Port 80]
        PH[PHP Runtime]
        DB[MariaDB Database riverview_data]
        FS[File Storage uploads]
    end

    ST --> BROWSER
    CO --> BROWSER
    AD --> BROWSER
    BROWSER --> AP
    AP --> PH
    PH --> DB
    PH --> FS
MERMAID,
    'figure_e8_erd' => <<<'MERMAID'
erDiagram
    STUDENTS ||--o{ STUDENT_ASSESSMENTS : takes
    STUDENTS }o--|| STRANDS : enrolled_in
    STUDENTS }o--o| SCHOOL_YEARS : belongs_to

    STUDENT_ASSESSMENTS ||--o{ STUDENT_ANSWERS : contains
    STUDENT_ASSESSMENTS ||--o{ CATEGORY_SCORES : has
    STUDENT_ASSESSMENTS ||--o{ COMPETENCY_SCORES : has
    STUDENT_ASSESSMENTS ||--o{ RECOMMENDATIONS : generates

    RECOMMENDATIONS }o--|| COURSES : recommends

    COURSES }o--|| CLUSTERS : belongs_to
    COURSES }o--|| STRANDS : aligned_to
    COURSES ||--o{ COURSE_SCHOOLS : offered_at
    COURSES ||--o{ COURSE_JOBS : leads_to
    COURSES ||--o{ COURSE_COMPETENCIES : requires

    SCHOOLS ||--o{ COURSE_SCHOOLS : offers
    JOBS ||--o{ COURSE_JOBS : linked_to
    COMPETENCIES ||--o{ COURSE_COMPETENCIES : mapped_to

    STUDENTS {
        int id PK
        varchar student_id UK
        varchar email
        varchar password
        int strand_id FK
        enum grade_level
        enum status
    }

    STUDENT_ASSESSMENTS {
        int id PK
        int student_id FK
        decimal total_score
        enum status
        datetime completed_at
    }

    STUDENT_ANSWERS {
        int id PK
        int assessment_id FK
        enum question_type
        int question_id
        decimal score
    }

    COURSES {
        int id PK
        varchar course_name
        int cluster_id FK
        int strand_id FK
    }

    RECOMMENDATIONS {
        int id PK
        int assessment_id FK
        int course_id FK
        decimal match_percentage
        int rank
    }

    SCHOOLS {
        int id PK
        varchar name
        varchar city
        varchar province
    }

    CLUSTERS {
        int id PK
        varchar name
    }

    STRANDS {
        int id PK
        varchar name
    }
MERMAID,
];

function mermaidBase64Url(string $diagram): string
{
    return rtrim(strtr(base64_encode($diagram), '+/', '-_'), '=');
}

function downloadMermaidPng(string $diagram, string $outputPath): array
{
    $encoded = mermaidBase64Url($diagram);
    $urls = [
        'https://mermaid.ink/img/' . $encoded,
        'https://mermaid.ink/img/' . $encoded . '?type=png&bgColor=white',
    ];

    foreach ($urls as $url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'header' => "User-Agent: CareerPath-Diagram-Export\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data !== false && strlen($data) > 1000) {
            file_put_contents($outputPath, $data);
            return ['ok' => true, 'url' => $url, 'bytes' => strlen($data)];
        }
    }

    // Fallback: kroki.io
    $krokiUrl = 'https://kroki.io/mermaid/png';
    $payload = json_encode(['diagram_source' => $diagram, 'diagram_type' => 'mermaid']);
    if ($payload !== false && function_exists('curl_init')) {
        $ch = curl_init($krokiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code === 200 && strlen($data) > 1000) {
            file_put_contents($outputPath, $data);
            return ['ok' => true, 'url' => $krokiUrl, 'bytes' => strlen($data)];
        }
    }

    return ['ok' => false, 'url' => $urls[0], 'bytes' => 0];
}

echo "Exporting Appendix E diagrams to:\n$outputDir\n\n";

$results = [];
foreach ($diagrams as $filename => $source) {
    $pngPath = $outputDir . '/' . $filename . '.png';
    echo "Exporting $filename.png ... ";
    $result = downloadMermaidPng(trim($source), $pngPath);
    if ($result['ok']) {
        echo "OK ({$result['bytes']} bytes)\n";
        $results[] = ['file' => $filename . '.png', 'status' => 'success'];
    } else {
        echo "FAILED\n";
        $results[] = ['file' => $filename . '.png', 'status' => 'failed'];
    }
}

// Write export manifest
$manifest = [
    'exported_at' => date('c'),
    'output_directory' => 'docs/appendix_e/diagrams',
    'diagrams' => $results,
];

file_put_contents(
    $outputDir . '/README.txt',
    "Appendix E - Exported Diagrams (PNG)\n" .
    "Generated: " . date('Y-m-d H:i:s') . "\n\n" .
    "Files:\n" .
    implode("\n", array_map(static function ($r) {
        return '- ' . $r['file'] . ' [' . $r['status'] . ']';
    }, $results)) . "\n"
);

file_put_contents($outputDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

echo "\nDone. " . count(array_filter($results, fn($r) => $r['status'] === 'success')) . "/" . count($results) . " exported.\n";
