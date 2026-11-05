<!-- View Details Modal (Shared Component) -->
<div class="modal" id="viewDetailsModal">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header-custom">
            <div class="header-left-box">
                <div class="icon-box-header" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25);">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="header-titles">
                    <h2>Assessment Details & Insights</h2>
                    <p>Complete performance breakdown, student profile, and course recommendations</p>
                </div>
            </div>
            <button class="modal-close" id="closeDetailsModal" title="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 1.5rem 1.75rem;">
            <div class="assessment-details-container">
                
                <!-- Student Hero Banner Card -->
                <div class="student-hero-banner">
                    <div class="shb-left">
                        <div class="shb-avatar" id="detailAvatar">FE</div>
                        <div class="shb-info">
                            <h3 class="shb-name" id="detailStudentName">—</h3>
                            <div class="shb-lrn"><i class="fa-solid fa-id-card"></i> Student ID: <span id="detailLrn">—</span></div>
                        </div>
                    </div>
                    <div class="shb-badges">
                        <span class="shb-pill grade" id="detailGradePill">
                            <i class="fa-solid fa-graduation-cap"></i> <span id="detailGradeLevel">Grade 11</span>
                        </span>
                        <span class="shb-pill strand" id="detailStrandPill">
                            <i class="fa-solid fa-layer-group"></i> <span id="detailStrand">Academic Pro</span>
                        </span>
                        <span class="shb-pill stat-badge completed" id="detailStatusPill">
                            <i class="fa-solid fa-circle-check"></i> <span id="detailStatusText">Completed</span>
                        </span>
                    </div>
                </div>

                    <!-- Low Confidence Warning Banner -->
                    <div class="rc-confidence-banner" id="detailConfidenceBanner" style="display:none;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <h4>Broad Interests / Multiple Close Matches Detected</h4>
                            <p id="detailConfidenceMessage">This student shows multiple close options with balanced interests across several career pathways. A follow-up counseling conversation is recommended to help them narrow down their preferred field.</p>
                        </div>
                    </div>

                <!-- 4-Card Performance Summary -->
                <div class="adm-metrics-grid">
                    <div class="adm-metric-card">
                        <div class="amc-icon" style="background: rgba(245, 158, 11, 0.12); color: #fbbf24;">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                        <div class="amc-content">
                            <span class="amc-label">Overall Match Score</span>
                            <span class="amc-value text-amber" id="detailScore">—</span>
                            <span class="amc-sub">Composite Result</span>
                        </div>
                    </div>
                    <div class="adm-metric-card">
                        <div class="amc-icon" style="background: rgba(99, 102, 241, 0.12); color: #818cf8;">
                            <i class="fa-solid fa-bullseye"></i>
                        </div>
                        <div class="amc-content">
                            <span class="amc-label">Top Career Cluster</span>
                            <span class="amc-value" id="detailCluster">—</span>
                            <span class="amc-sub">Leading Direction</span>
                        </div>
                    </div>
                    <div class="adm-metric-card">
                        <div class="amc-icon" style="background: rgba(16, 185, 129, 0.12); color: #34d399;">
                            <i class="fa-solid fa-award"></i>
                        </div>
                        <div class="amc-content">
                            <span class="amc-label">Top Recommendation</span>
                            <span class="amc-value text-emerald" id="detailTopCourse" title="">—</span>
                            <span class="amc-sub">#1 Ranked Pathway</span>
                        </div>
                    </div>
                    <div class="adm-metric-card">
                        <div class="amc-icon" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa;">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <div class="amc-content">
                            <span class="amc-label">Date Completed</span>
                            <span class="amc-value" id="detailDate" style="font-size: 0.95rem;">—</span>
                            <span class="amc-sub" id="detailTime">—</span>
                        </div>
                    </div>
                </div>

                <!-- Category Score Breakdown Section -->
                <div class="adm-section-card">
                    <div class="adm-section-header">
                        <h4>
                            <i class="fa-solid fa-chart-simple" style="color: #fbbf24;"></i>
                            Category Score Breakdown
                        </h4>
                        <span class="adm-sec-badge">4 Core Assessment Dimensions</span>
                    </div>
                    <div class="breakdown-list" id="breakdownList">
                        <p style="color:#94a3b8;font-size:0.875rem;">Loading category scores...</p>
                    </div>
                </div>

                <!-- Recommended Programs Section -->
                <div class="adm-section-card">
                    <div class="adm-section-header">
                        <h4>
                            <i class="fa-solid fa-graduation-cap" style="color: #38bdf8;"></i>
                            Recommended Academic Programs
                        </h4>
                        <span class="adm-sec-badge" id="detailCourseCountBadge">Curated Pathways</span>
                    </div>
                    <div id="detailCoursesWrap" class="rec-courses-container">
                        —
                    </div>
                </div>

            </div>
        </div>
        <div class="modal-footer" style="padding: 1rem 1.75rem; border-top: 1px solid rgba(255,255,255,0.06); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <a id="detailViewAnswersBtn" href="#" style="display: none; align-items: center; gap: 8px; text-decoration: none; background: rgba(99, 102, 241, 0.15); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.3); padding: 0.55rem 1.2rem; border-radius: 8px; font-size: 0.88rem; font-weight: 600; transition: all 0.2s;">
                    <i class="fa-solid fa-clipboard-check"></i> View Full Answers
                </a>
                <a id="detailReviewBtn" href="#" style="display: none; align-items: center; gap: 8px; text-decoration: none; background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); padding: 0.55rem 1.2rem; border-radius: 8px; font-size: 0.88rem; font-weight: 600; transition: all 0.2s;">
                    <i class="fa-solid fa-stamp"></i> Review / Return Assessment
                </a>
            </div>
            <button type="button" class="btn-secondary" id="closeDetailsBtn" style="margin-left: auto;">Close</button>
        </div>
    </div>
</div>
